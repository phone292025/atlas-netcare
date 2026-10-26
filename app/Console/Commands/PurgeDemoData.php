<?php

namespace App\Console\Commands;

use App\Models\AccessPoint;
use App\Models\LabPc;
use App\Models\NetworkDevice;
use App\Models\Shift;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Remove every demonstration row, leaving a system somebody could deploy.
 *
 * Deletes anything tagged `is_demo` (users, lab PCs, access points, tickets),
 * every telemetry row whose `source` is `simulated`, and the rows that belong
 * to those by association. Only four tables carry the flag, so inspections,
 * shifts, alarms, comments, risk assessments and audit rows are found through
 * what they belong to.
 *
 * The order follows the foreign keys: six are RESTRICT, so children go first.
 * Cascading children are deleted explicitly too, so the count is honest and
 * the order does not depend on a cascade somebody may relax.
 *
 * It refuses to orphan real data: `tickets.ticketable` is polymorphic, so no
 * foreign key stops a real ticket being left pointing at a deleted demo asset.
 *
 *   php artisan atlas:purge-demo-data --dry-run
 *   php artisan atlas:purge-demo-data
 *   php artisan atlas:purge-demo-data --force
 *
 * @see docs/decisions/ADR-0003-single-database-demo-flag.md
 * @see docs/05-data-strategy.md — Mechanism 2
 */
class PurgeDemoData extends Command
{
    protected $signature = 'atlas:purge-demo-data
                            {--dry-run : Report what would go, and change nothing}
                            {--force : Skip the confirmation}
                            {--allow-orphans : Delete demo assets even where real records point at them}';

    protected $description = 'Remove every demonstration row, leaving the reference data and real records';

    /** Rows are deleted in this many at a time. */
    private const CHUNK = 500;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->line('');
        $this->line($dryRun
            ? '  <options=bold>Dry run.</> Nothing will be deleted.'
            : '  <options=bold>Purging demonstration data.</>');
        $this->line('');

        $plan = $this->plan();
        $total = array_sum(array_column($plan, 'count'));

        foreach ($plan as $step) {
            $this->line(sprintf('  %-26s %6d', $step['label'], $step['count']));
        }

        $this->line(sprintf('  %-26s %6d', '', $total));
        $this->line('');

        if ($total === 0) {
            $this->info('  Nothing to purge. This database has no demonstration data.');
            $this->line('');

            return self::SUCCESS;
        }

        if (! $this->orphanCheckPasses()) {
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('  Dry run only. Re-run without --dry-run to delete.');
            $this->line('');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Delete these {$total} rows?", false)) {
            $this->line('  Nothing was deleted.');

            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ($plan as $step) {
            $deleted += $this->purge($step);
        }

        $this->line('');
        $this->info("  {$deleted} rows removed.");
        $this->line('  Reference data, the administrator and every real record are untouched.');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Everything to delete, children before parents.
     *
     * Each entry is a fresh query builder rather than a resolved set of ids,
     * because the earlier steps change what the later ones match.
     *
     * @return list<array{label: string, table: string, query: callable(): Builder, count: int}>
     */
    private function plan(): array
    {
        $demoUsers = fn () => DB::table('users')->where('is_demo', true)->select('id');
        $demoPcs = fn () => DB::table('lab_pcs')->where('is_demo', true)->select('id');
        $demoDevices = fn () => DB::table('network_devices')->where('is_demo', true)->select('id');

        $steps = [
            /*
             * Audit rows about demo activity or demo subjects.
             *
             * FIRST, because every clause reads a table a later step deletes;
             * run later, the subqueries match nothing and the rows survive.
             *
             * Matched on the SUBJECT as well as the actor: rows a seeder wrote
             * have no `user_id`. History of real users and real assets stays.
             */
            ['Audit rows', 'audit_logs', fn () => DB::table('audit_logs')
                ->where(fn (Builder $q) => $q
                    ->whereIn('user_id', $demoUsers())
                    ->orWhere(fn (Builder $x) => $x->where('auditable_type', User::class)->whereIn('auditable_id', $demoUsers()))
                    ->orWhere(fn (Builder $x) => $x->where('auditable_type', LabPc::class)->whereIn('auditable_id', $demoPcs()))
                    ->orWhere(fn (Builder $x) => $x->whereIn('auditable_type', [AccessPoint::class, NetworkDevice::class])->whereIn('auditable_id', $demoDevices()))
                    ->orWhere(fn (Builder $x) => $x->where('auditable_type', Ticket::class)
                        ->whereIn('auditable_id', DB::table('tickets')->where('is_demo', true)->select('id')))
                    ->orWhere(fn (Builder $x) => $x->where('auditable_type', Shift::class)
                        ->whereIn('auditable_id', DB::table('shifts')->whereIn('user_id', $demoUsers())->select('id')))
                )],

            // Polymorphic, so no cascade and no foreign key to complain.
            ['Risk assessments', 'risk_assessments', fn () => DB::table('risk_assessments')
                ->where(fn (Builder $q) => $q
                    ->where(fn (Builder $x) => $x->where('assessable_type', LabPc::class)->whereIn('assessable_id', $demoPcs()))
                    ->orWhere(fn (Builder $x) => $x->where('assessable_type', AccessPoint::class)->whereIn('assessable_id', $demoDevices()))
                )],

            // Cascades from inspections, but counted and deleted explicitly so
            // the report adds up and the order does not depend on the cascade.
            ['Inspection results', 'inspection_results', fn () => DB::table('inspection_results')
                ->whereIn('inspection_id', DB::table('inspections')
                    ->where(fn (Builder $q) => $q->whereIn('lab_pc_id', $demoPcs())->orWhereIn('user_id', $demoUsers()))
                    ->select('id'))],

            // RESTRICT on both lab_pc_id and user_id, so these must precede
            // the assets and the accounts.
            ['Inspections', 'inspections', fn () => DB::table('inspections')
                ->where(fn (Builder $q) => $q->whereIn('lab_pc_id', $demoPcs())->orWhereIn('user_id', $demoUsers()))],

            ['Ticket comments', 'ticket_comments', fn () => DB::table('ticket_comments')
                ->where(fn (Builder $q) => $q
                    ->whereIn('ticket_id', DB::table('tickets')->where('is_demo', true)->select('id'))
                    ->orWhereIn('user_id', $demoUsers()))],

            ['Tickets', 'tickets', fn () => DB::table('tickets')->where('is_demo', true)],

            // Before shifts and before users: alarms.user_id is RESTRICT.
            ['Alarms', 'alarms', fn () => DB::table('alarms')
                ->where(fn (Builder $q) => $q
                    ->whereIn('user_id', $demoUsers())
                    ->orWhereIn('shift_id', DB::table('shifts')->whereIn('user_id', $demoUsers())->select('id')))],

            ['Shifts', 'shifts', fn () => DB::table('shifts')->whereIn('user_id', $demoUsers())],

            // Cascades from network_devices, but simulated readings can also
            // exist for a REAL access point — from the simulator driver — and
            // those are demonstration data too.
            ['Telemetry, hourly', 'telemetry_hourly', fn () => DB::table('telemetry_hourly')->where('source', 'simulated')],
            ['Telemetry, raw', 'telemetry_readings', fn () => DB::table('telemetry_readings')->where('source', 'simulated')],

            // Force deletes: both soft delete, and a retired demo machine is
            // still demo data.
            ['Network devices', 'network_devices', fn () => DB::table('network_devices')->where('is_demo', true)],
            ['Lab PCs', 'lab_pcs', fn () => DB::table('lab_pcs')->where('is_demo', true)],

            ['Demo accounts', 'users', fn () => DB::table('users')->where('is_demo', true)],
        ];

        return array_map(fn (array $s) => [
            'label' => $s[0],
            'table' => $s[1],
            'query' => $s[2],
            'count' => $s[2]()->count(),
        ], $steps);
    }

    /**
     * Refuse to leave a real ticket pointing at a deleted demo asset. It would
     * still render, with a blank asset column that looks like a display bug
     * rather than the data loss it is.
     */
    private function orphanCheckPasses(): bool
    {
        $orphans = DB::table('tickets')
            ->where('is_demo', false)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $x) => $x->where('ticketable_type', LabPc::class)
                    ->whereIn('ticketable_id', DB::table('lab_pcs')->where('is_demo', true)->select('id')))
                ->orWhere(fn (Builder $x) => $x->whereIn('ticketable_type', [AccessPoint::class, NetworkDevice::class])
                    ->whereIn('ticketable_id', DB::table('network_devices')->where('is_demo', true)->select('id')))
            )
            ->pluck('ticket_number');

        if ($orphans->isEmpty()) {
            return true;
        }

        if ($this->option('allow-orphans')) {
            $this->warn("  {$orphans->count()} real ".str('ticket')->plural($orphans->count())
                .' will be left pointing at a deleted asset, because --allow-orphans was given.');
            $this->line('');

            return true;
        }

        $this->error('  Stopping: real records point at demonstration assets.');
        $this->line('');
        $this->line('  These tickets are NOT demo data, but the machines they are about are:');

        foreach ($orphans->take(10) as $number) {
            $this->line("    {$number}");
        }

        if ($orphans->count() > 10) {
            $this->line('    ... and '.($orphans->count() - 10).' more');
        }

        $this->line('');
        $this->line('  Deleting the assets would leave those tickets pointing at nothing, which');
        $this->line('  looks like a display bug rather than the data loss it is. Either point');
        $this->line('  them at a real asset first, or accept it with --allow-orphans.');
        $this->line('');

        return false;
    }

    /**
     * Delete one step's rows, in chunks: a single DELETE across telemetry, the
     * largest table by two orders of magnitude, holds it long enough to notice.
     *
     * @param  array{label: string, table: string, query: callable(): Builder, count: int}  $step
     */
    private function purge(array $step): int
    {
        if ($step['count'] === 0) {
            return 0;
        }

        $deleted = 0;

        do {
            $went = $step['query']()->limit(self::CHUNK)->delete();
            $deleted += $went;
        } while ($went > 0);

        $this->line(sprintf('  removed %-24s %6d', $step['label'], $deleted));

        return $deleted;
    }
}
