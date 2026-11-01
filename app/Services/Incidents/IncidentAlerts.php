<?php

namespace App\Services\Incidents;

use App\Enums\AlertChannel;
use App\Models\Incident;
use App\Models\IncidentAlert;
use App\Models\User;
use App\Services\Shifts\AlarmService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Alerts about incidents, through every channel a person can get (roadmap
 * 2.6).
 *
 * Each alert is claimed before it is sent: a row keyed by incident, person,
 * escalation step and channel. The claim that inserts the row queues the
 * send; anything that finds the row already there sends nothing. The send
 * itself runs on the queue with retries, and checks `sent_at` first, so a
 * retry after a send that succeeded doesn't send it again. In the app the
 * alarm and `sent_at` are written in one transaction, so that channel is
 * exactly once. Email and Telegram are once unless a worker dies between
 * the provider accepting the message and `sent_at` being saved.
 */
class IncidentAlerts
{
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

                    // On the queue, after the incident commits, with the worker's
                    // retries. The last failure is written on the claim.
                    dispatch(fn () => app(self::class)->deliver($id))
                        ->catch(fn (Throwable $e) => app(self::class)->failed($id, $e->getMessage()))
                        ->afterCommit();
                }
            }
        }
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

    /** Send one claimed alert, unless it has already gone. */
    public function deliver(int $alertId): void
    {
        $alert = IncidentAlert::query()->with(['incident.device.location', 'incident.agent', 'user'])->find($alertId);

        if (! $alert || $alert->sent_at !== null) {
            return;
        }

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

        match ($alert->channel) {
            AlertChannel::Email => Mail::raw(
                $this->summary($alert->incident)."\n\n".$alert->incident->urlFor($alert->user),
                fn ($message) => $message->to($alert->user->email)->subject('[Atlas] '.$alert->incident->title),
            ),
            AlertChannel::Telegram => Http::timeout(10)->post(
                'https://api.telegram.org/bot'.config('atlas.incidents.telegram_bot_token').'/sendMessage',
                ['chat_id' => $alert->user->telegram_chat_id, 'text' => $alert->incident->title."\n".$this->summary($alert->incident)."\n".$alert->incident->urlFor($alert->user)],
            )->throw(),
        };

        $alert->forceFill(['sent_at' => now()])->save();
    }

    public function failed(int $alertId, string $error): void
    {
        IncidentAlert::query()->whereKey($alertId)->whereNull('sent_at')
            ->update(['failed_at' => now(), 'error' => mb_substr($error, 0, 250), 'updated_at' => now()]);
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
