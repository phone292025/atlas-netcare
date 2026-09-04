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

    public function handle(): int
    {
        $renames = $this->plan();

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

        $tickets = Ticket::withTrashed()
            ->orderBy('opened_at')
            ->orderBy('id')
            ->get(['id', 'ticket_number', 'opened_at']);

        foreach ($tickets->groupBy(fn (Ticket $t) => $t->opened_at->year) as $year => $ofYear) {
            $sequence = 0;

            foreach ($ofYear as $ticket) {
                $sequence++;
                $expected = Ticket::formatNumber($sequence, (int) $year);

                if ($ticket->ticket_number !== $expected) {
                    $renames[$ticket->ticket_number] = $expected;
                }
            }
        }

        return $renames;
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
     * Via SQL REPLACE(), which MariaDB and SQLite both have, so JSON columns
     * are handled as the text they are. The pattern is checked first so a
     * reference can never smuggle a quote into the statement.
     *
     * @param  array<string, string>  $renames
     */
    private function rewriteReferences(array $renames): void
    {
        foreach ($renames as $old => $new) {
            if (! preg_match('/^TKT-\d{4}-\d{5}$/', $old) || ! preg_match('/^TKT-\d{4}-\d{5}$/', $new)) {
                continue;
            }

            foreach (self::TEXT_COLUMNS as $table => $columns) {
                foreach ($columns as $column) {
                    DB::table($table)
                        ->where($column, 'like', "%{$old}%")
                        ->update([$column => DB::raw("REPLACE({$column}, '{$old}', '{$new}')")]);
                }
            }
        }
    }
}
