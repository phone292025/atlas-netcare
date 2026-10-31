<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give every ticket a sequential reference in the order it was opened.
 *
 * WHY THIS EXISTS. Until 2026-09-04 `TicketFactory` invented references with
 * numberBetween(1, 99999), and `reserveNumber()` takes the highest and adds
 * one, so a database seeded with the old factory carries numbers like
 * TKT-2026-96851 and every real ticket raised afterwards continued from
 * there. The factory is fixed; this repairs a database seeded before it was.
 *
 * WHAT IT CHANGES. Only `tickets.ticket_number`, plus any text that quoted an
 * old reference — a comment, an alarm, an audit row — so nothing points at a
 * number that no longer exists. Order is `opened_at` then id, per year, which
 * is the order the numbers would have had if they had been issued correctly.
 *
 * WHAT IT REFUSES TO TOUCH. A reference outside the `TKT-YYYY-NNNNN` shape is
 * left alone and named in the output. It cannot be found reliably in free
 * text, so renumbering it is the one way this command could break the promise
 * above — and a number short enough to be ambiguous would have REPLACE()
 * rewriting text that was never a reference at all.
 *
 * Bypasses the model so the observer does not write two rows per ticket (a
 * temporary number is needed to get past the unique index), and writes one
 * honest audit row per renumbered ticket instead.
 *
 *   php artisan atlas:renumber-tickets --dry-run
 *   php artisan atlas:renumber-tickets
 *
 * Safe to re-run: a sequential database reports nothing to do.
 */
class RenumberTickets extends Command
{
    protected $signature = 'atlas:renumber-tickets
                            {--dry-run : Show what would change and change nothing}
                            {--force : Skip the confirmation}';

    protected $description = 'Renumber tickets sequentially by the date they were opened, rewriting any text that quoted the old numbers';

    /** Columns that may quote a ticket reference in free text. */
    private const TEXT_COLUMNS = [
        'ticket_comments' => ['body'],
        'alarms' => ['title', 'message'],
        'audit_logs' => ['description', 'changes', 'previous'],
    ];

    /** The columns above stored as `json`, which PostgreSQL won't search with LIKE. */
    private const JSON_COLUMNS = [
        'audit_logs' => ['changes', 'previous'],
    ];

    /**
     * References left alone because they cannot be found safely in free text.
     *
     * Reported rather than silently passed over: a number this command will
     * not touch is a number that stays out of sequence, and the person running
     * it should know which.
     *
     * @var list<string>
     */
    private array $skipped = [];

    public function handle(): int
    {
        $renames = $this->plan();

        $this->reportSkipped();

        if ($renames === []) {
            $this->info('Every ticket is already numbered in sequence. Nothing to do.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line(count($renames).' '.str('ticket')->plural(count($renames)).' would be renumbered:');
        $this->table(['From', 'To'], array_slice(array_map(null, array_keys($renames), $renames), 0, 20));

        if (count($renames) > 20) {
            $this->line('  … and '.(count($renames) - 20).' more.');
        }

        if ($this->option('dry-run')) {
            $this->info('  Dry run only. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Renumber these tickets?', false)) {
            $this->line('  Nothing was changed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($renames): void {
            $ids = $this->idsByCurrentNumber(array_keys($renames));

            // Two passes, because the unique index will not let 00002 become
            // 00001 while 00001 still exists.
            foreach ($ids as $old => $id) {
                DB::table('tickets')->where('id', $id)->update(['ticket_number' => 'RENUM-'.$id]);
            }

            foreach ($ids as $old => $id) {
                DB::table('tickets')->where('id', $id)->update(['ticket_number' => $renames[$old]]);
            }

            // Text first, audit rows second. The rewrite replaces every old
            // number wherever it is quoted — including in audit_logs — so a
            // row written before it would have had its own "previous" value
            // rewritten to the new number, and the trail would say a ticket
            // was renumbered from the number it was renumbered TO.
            $this->rewriteReferences($renames);

            foreach ($ids as $old => $id) {
                AuditLog::create([
                    'user_id' => null,
                    'action' => 'updated',
                    'auditable_type' => (new Ticket)->getMorphClass(),
                    'auditable_id' => $id,
                    'description' => 'Renumbered by atlas:renumber-tickets',
                    'changes' => ['ticket_number' => $renames[$old]],
                    'previous' => ['ticket_number' => $old],
                    'ip_address' => null,
                    'created_at' => now(),
                ]);
            }
        });

        $this->info('  '.count($renames).' '.str('ticket')->plural(count($renames)).' renumbered.');

        return self::SUCCESS;
    }

    /**
     * old number => new number, for every ticket whose number is not already
     * the one its position gives it.
     *
     * @return array<string, string>
     */
    private function plan(): array
    {
        $renames = [];
        $this->skipped = [];

        $tickets = Ticket::withTrashed()
            ->orderBy('opened_at')
            ->orderBy('id')
            ->get(['id', 'ticket_number', 'opened_at']);

        foreach ($tickets->groupBy(fn (Ticket $t) => $t->opened_at->year) as $year => $ofYear) {
            $sequence = 0;

            foreach ($ofYear as $ticket) {
                $sequence++;
                $expected = Ticket::formatNumber($sequence, (int) $year);

                if ($ticket->ticket_number === $expected) {
                    continue;
                }

                /*
                 * A number this cannot safely find in free text is left ALONE,
                 * rather than renamed and left dangling.
                 *
                 * The guard used to sit in rewriteReferences() instead, after
                 * the rename had already happened — so a reference outside the
                 * expected shape was renumbered while every comment, alarm and
                 * audit row quoting it kept a number that no longer existed.
                 * That is the one thing this command's docblock promises it
                 * does not do.
                 *
                 * It also protects against a number so short or generic that
                 * REPLACE() would rewrite unrelated text: a ticket numbered
                 * "1" would turn every "1" in every comment into something
                 * else.
                 */
                if (! $this->canRewrite($ticket->ticket_number) || ! $this->canRewrite($expected)) {
                    $this->skipped[] = $ticket->ticket_number;

                    continue;
                }

                $renames[$ticket->ticket_number] = $expected;
            }
        }

        return $renames;
    }

    /** Say which references were left out, and why, before anything else. */
    private function reportSkipped(): void
    {
        if ($this->skipped === []) {
            return;
        }

        $this->warn('  '.count($this->skipped).' '.str('ticket')->plural(count($this->skipped))
            .' left alone: the reference is not in the TKT-YYYY-NNNNN shape, so it cannot be');
        $this->line('  found reliably in comments and alarms. Renumbering it would leave those pointing at nothing.');

        foreach (array_slice($this->skipped, 0, 10) as $number) {
            $this->line('    '.$number);
        }

        if (count($this->skipped) > 10) {
            $this->line('    … and '.(count($this->skipped) - 10).' more.');
        }

        $this->newLine();
    }

    /**
     * Is this reference specific enough to find and replace in free text?
     *
     * `TKT-2026-00001`, and `TKT-2026-100000` once a year passes 99,999.
     * Anything else is left untouched: no wildcards for the LIKE, no quotes to
     * escape, and nothing short enough to match text that is not a reference.
     */
    private function canRewrite(string $number): bool
    {
        return (bool) preg_match('/^TKT-\d{4}-\d{5,}$/', $number);
    }

    /**
     * @param  array<int, string>  $numbers
     * @return array<string, int>
     */
    private function idsByCurrentNumber(array $numbers): array
    {
        return DB::table('tickets')
            ->whereIn('ticket_number', $numbers)
            ->pluck('id', 'ticket_number')
            ->all();
    }

    /**
     * Replace every quoted old reference with its new one.
     *
     * Via SQL REPLACE(), which every engine has. MariaDB and SQLite store JSON
     * as text, so it is handled as the text it is. PostgreSQL stores a real
     * `json` type with no LIKE or REPLACE, so there the column is compared and
     * edited as text and cast back.
     *
     * BOUND PARAMETERS, not interpolation. The references are checked by
     * plan() before they reach here, so neither could carry a quote — but a
     * command that rewrites free text across four tables should not depend on
     * a validation two methods away staying correct. The table and column
     * names come from a constant in this file and never from data.
     *
     * IN TWO PASSES, through a placeholder. A new number is often another
     * ticket's old one, so renaming 3 to 1 and then 1 to 2 in one pass turned
     * text about the first ticket into the second's number.
     *
     * @param  array<string, string>  $renames
     */
    private function rewriteReferences(array $renames): void
    {
        $placeholders = [];
        $i = 0;

        foreach ($renames as $old => $new) {
            $placeholders[$old] = '@@renumber:'.$i++.'@@';
        }

        $this->replaceEverywhere($placeholders);
        $this->replaceEverywhere(array_combine($placeholders, $renames));
    }

    /** @param  array<string, string>  $renames */
    private function replaceEverywhere(array $renames): void
    {
        $pgsql = DB::connection()->getDriverName() === 'pgsql';

        foreach ($renames as $old => $new) {
            foreach (self::TEXT_COLUMNS as $table => $columns) {
                foreach ($columns as $column) {
                    $json = $pgsql && in_array($column, self::JSON_COLUMNS[$table] ?? [], true);
                    $text = $json ? "{$column}::text" : $column;
                    $replaced = $json ? "REPLACE({$text}, ?, ?)::json" : "REPLACE({$column}, ?, ?)";

                    DB::update(
                        "update {$table} set {$column} = {$replaced} where {$text} like ?",
                        [$old, $new, '%'.$old.'%'],
                    );
                }
            }
        }
    }
}
