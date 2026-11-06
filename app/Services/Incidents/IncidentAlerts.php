<?php

namespace App\Services\Incidents;

use App\Enums\AlertChannel;
use App\Models\Incident;
use App\Models\IncidentAlert;
use App\Models\User;
use App\Services\Shifts\AlarmService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Throwable;

/**
 * Alerts about incidents, through every channel a person can get (roadmap
 * 2.6).
 *
 * Each alert is claimed before it is sent: a row keyed by incident, person,
 * escalation step and channel. The claim that inserts the row queues the
 * send; anything that finds the row already there sends nothing.
 *
 * The send runs on the queue with retries. A worker first takes the alert
 * by setting `attempted_at`, and only if nobody has it or the last taker is
 * older than LEASE_SECONDS, so two jobs for one alert never send at once.
 * A failed attempt gives the alert back for the next retry.
 *
 * The row is the record of what is owed, and the queue only a way to send
 * it. If the job never reached the queue (the process died after the
 * incident committed) or the worker died mid-send, the alert stays unsent
 * and untaken. The minute sweep (atlas:watch-incidents) queues every such
 * alert again once it has been quiet for LEASE_SECONDS, so it goes out late
 * and is never lost.
 *
 * In the app the alarm and `sent_at` are written in one transaction, so that
 * channel is exactly once. Email and Telegram are at least once: a worker
 * that dies after the provider took the message and before `sent_at` was
 * saved leaves an alert the sweep sends again. So does a timeout: the
 * provider may have taken the message before the connection dropped. Such an
 * alert is marked `maybe_sent`, and goes again as a labelled repeat, so the
 * person can tell it from a second incident. An email also keeps one
 * Message-ID per alert, so a mail server that drops repeated IDs shows one.
 * A retry after the provider refused the message, with an error status, is
 * not a repeat and is sent plain.
 */
class IncidentAlerts
{
    /**
     * How long a worker keeps an alert it took. Longer than a job can run:
     * the worker kills a job after 60 seconds, and a Telegram call gives up
     * after 10.
     */
    public const LEASE_SECONDS = 180;

    /** The first line of an alert that may have reached its person already. */
    public const REPEAT = 'Repeat: Atlas may have sent you this alert already, and is sending it again in case it did not arrive.';

    public function __construct(private readonly AlarmService $alarms) {}

    /** @param  Collection<int, User>  $people */
    public function send(Incident $incident, Collection $people, int $step): void
    {
        foreach ($people as $user) {
            foreach ($this->channelsFor($user) as $channel) {
                $claimed = DB::table('incident_alerts')->insertOrIgnore([
                    'incident_id' => $incident->id,
                    'user_id' => $user->id,
                    'step' => $step,
                    'channel' => $channel->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($claimed) {
                    $id = IncidentAlert::query()
                        ->where(['incident_id' => $incident->id, 'user_id' => $user->id, 'step' => $step, 'channel' => $channel->value])
                        ->value('id');

                    $this->queue($id);
                }
            }
        }
    }

    /**
     * Queue again every alert that is owed and that nobody has touched for
     * LEASE_SECONDS: its job was lost, or its worker died. Run by the minute
     * sweep. Touching `updated_at` keeps one alert from being queued again
     * every minute while its new job waits.
     *
     * @return int how many were queued again
     */
    public function requeueStalled(): int
    {
        $quiet = now()->subSeconds(self::LEASE_SECONDS);

        $ids = IncidentAlert::query()
            ->whereNull('sent_at')
            ->whereNull('failed_at')
            ->where('updated_at', '<', $quiet)
            ->where(fn ($q) => $q->whereNull('attempted_at')->orWhere('attempted_at', '<', $quiet))
            ->orderBy('id')
            ->limit(500)
            ->pluck('id');

        foreach ($ids as $id) {
            IncidentAlert::query()->whereKey($id)->update(['updated_at' => now()]);
            $this->queue($id);
        }

        return $ids->count();
    }

    /** On the queue, after any open transaction commits, with the worker's retries. */
    private function queue(int $id): void
    {
        // The last failure is written on the claim.
        dispatch(fn () => app(self::class)->deliver($id))
            ->catch(fn (Throwable $e) => app(self::class)->failed($id, $e->getMessage()))
            ->afterCommit();
    }

    /** @return list<AlertChannel> */
    public function channelsFor(User $user): array
    {
        $channels = [AlertChannel::InApp];

        if (config('atlas.mail_configured') && filled($user->email)) {
            $channels[] = AlertChannel::Email;
        }

        if (filled(config('atlas.incidents.telegram_bot_token')) && filled($user->telegram_chat_id)) {
            $channels[] = AlertChannel::Telegram;
        }

        return $channels;
    }

    /**
     * Send one claimed alert, unless it has already gone, has failed for
     * good, or another worker has it.
     */
    public function deliver(int $alertId): void
    {
        if (! $this->take($alertId)) {
            return;
        }

        $alert = IncidentAlert::query()->with(['incident.device.location', 'incident.agent', 'user'])->find($alertId);

        try {
            $this->sendTaken($alert);
        } catch (Throwable $e) {
            // Give it back, so the queue's retry, or the sweep, can take it.
            IncidentAlert::query()->whereKey($alertId)->update(['attempted_at' => null, 'updated_at' => now()]);

            throw $e;
        }
    }

    /**
     * Take the alert for this worker, if it is owed and nobody else has it.
     * Taking it from a worker whose lease ran out marks it `maybe_sent`: that
     * worker may have sent it before it died.
     */
    private function take(int $alertId): bool
    {
        $owed = fn () => IncidentAlert::query()->whereKey($alertId)->whereNull('sent_at')->whereNull('failed_at');

        if ($owed()->whereNull('attempted_at')->update(['attempted_at' => now(), 'updated_at' => now()]) === 1) {
            return true;
        }

        return $owed()
            ->where('attempted_at', '<', now()->subSeconds(self::LEASE_SECONDS))
            ->update(['attempted_at' => now(), 'maybe_sent' => true, 'updated_at' => now()]) === 1;
    }

    private function sendTaken(IncidentAlert $alert): void
    {
        $alertId = $alert->id;

        if ($alert->channel === AlertChannel::InApp) {
            DB::transaction(function () use ($alertId) {
                $alert = IncidentAlert::query()->whereKey($alertId)->lockForUpdate()->with(['incident', 'user'])->first();

                if ($alert->sent_at !== null) {
                    return;
                }

                $this->alarms->raise($alert->incident->rule->alarmType(), $alert->user, $alert->incident->title, $this->summary($alert->incident), related: $alert->incident);
                $alert->forceFill(['sent_at' => now()])->save();
            });

            return;
        }

        try {
            $this->sendOutside($alert);
        } catch (Throwable $e) {
            // An answer with an error status means the provider refused it:
            // Telegram's HTTP error, an SMTP server's 4xx or 5xx, a mail
            // API's error response. Anything else, a timeout above all, may
            // have been delivered.
            if (! $this->refused($e)) {
                IncidentAlert::query()->whereKey($alertId)->update(['maybe_sent' => true]);
            }

            // The Telegram API puts the bot token in its URL, and a
            // connection error quotes the URL. Nothing stored or logged may
            // carry it.
            throw new RuntimeException($this->redact($e->getMessage()), previous: null);
        }

        $alert->forceFill(['sent_at' => now()])->save();
    }

    private function sendOutside(IncidentAlert $alert): void
    {
        $repeat = $alert->maybe_sent ? self::REPEAT."\n\n" : '';

        match ($alert->channel) {
            AlertChannel::Email => Mail::raw(
                $repeat.$this->summary($alert->incident)."\n\n".$alert->incident->urlFor($alert->user),
                function ($message) use ($alert) {
                    $message->to($alert->user->email)->subject('[Atlas] '.($alert->maybe_sent ? 'Repeat: ' : '').$alert->incident->title);
                    // One ID per alert, so a resend after a crash is the same message.
                    $message->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', $this->messageId($alert));
                },
            ),
            AlertChannel::Telegram => Http::timeout(10)->post(
                'https://api.telegram.org/bot'.config('atlas.incidents.telegram_bot_token').'/sendMessage',
                ['chat_id' => $alert->user->telegram_chat_id, 'text' => $repeat.$alert->incident->title."\n".$this->summary($alert->incident)."\n".$alert->incident->urlFor($alert->user)],
            )->throw(),
        };
    }

    /** Whether the provider answered, and the answer was no. */
    private function refused(Throwable $e): bool
    {
        return $e instanceof RequestException
            || $e instanceof UnexpectedResponseException
            || ($e instanceof HttpTransportException && $e->getResponse()->getStatusCode() >= 400);
    }

    public function messageId(IncidentAlert $alert): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'atlas.local';

        return "incident-alert-{$alert->id}@{$host}";
    }

    private function redact(string $message): string
    {
        $token = (string) config('atlas.incidents.telegram_bot_token');

        return $token === '' ? $message : str_replace($token, '[token]', $message);
    }

    public function failed(int $alertId, string $error): void
    {
        IncidentAlert::query()->whereKey($alertId)->whereNull('sent_at')
            ->update(['failed_at' => now(), 'error' => mb_substr($this->redact($error), 0, 250), 'updated_at' => now()]);
    }

    private function summary(Incident $incident): string
    {
        if ($incident->agent) {
            return 'Last heard from at '.($incident->agent->last_seen_at?->campus()->format('j M, H:i') ?? 'never')
                .'. Its devices show as unknown until it reports again.';
        }

        $where = $incident->device?->location?->label();

        return $incident->rule->label().($where ? " in {$where}" : '').', since '.$incident->opened_at->campus()->format('H:i').'.';
    }
}
