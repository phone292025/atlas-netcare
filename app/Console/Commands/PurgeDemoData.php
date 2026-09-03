<?php

namespace App\Console\Commands;

use App\Models\AccessPoint;
use App\Models\LabPc;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Remove every demonstration row, leaving a system somebody could deploy.
 *
 * Referenced in the README, ADR-0003, the data strategy and the database
 * document since Sprint 0, and not written for weeks after. Every one of
 * those references was a promise that running this would work.
 *
 * WHAT IT DELETES. Anything tagged `is_demo` — users, lab PCs, access points,
 * tickets — plus every telemetry reading whose `source` is `simulated`, plus
 * the rows that belong to those by association.
 *
 * THE ASSOCIATION PART IS MOST OF THE WORK. Only four tables carry `is_demo`.
 * Inspections, shifts, alarms, comments, risk assessments and audit rows have
 * no flag of their own, so each has to be found through whatever it belongs
 * to. A purge that only cleared the flagged tables would leave an inspection
 * pointing at a lab PC that no longer exists — and `inspections.lab_pc_id` is
 * RESTRICT, so it would not even get that far: the delete would simply fail.
 *
 * ORDER IS DICTATED BY THE FOREIGN KEYS, not by preference. Six constraints
 * are RESTRICT, so children must go first. Where a constraint already
 * cascades, the child is still deleted explicitly — partly so the count is
 * honest, partly so the order does not silently depend on a cascade somebody
 * may relax later.
 *
 * IT REFUSES TO ORPHAN REAL DATA. `tickets.ticketable` is polymorphic and
 * therefore has no foreign key, so nothing at the database level stops a real
 * ticket from being left pointing at a deleted demo machine. On the machine
 * this was written on, all three genuine tickets pointed at demo lab PCs.
 * Deleting the assets underneath them would have quietly broken every one.
 * The command finds those first and stops.
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
        $demoAps = fn () => DB::table('access_points')->where('is_demo', true)->select('id');

        $steps = [
            /*
             * Audit rows about demo activity are demo data by association.
             *
             * FIRST IN THE ORDER, deliberately. Every clause below reads
             * a table that a later step deletes, so running it anywhere else
             * makes those subqueries match nothing — 86 rows about deleted
             * demo tickets survived exactly that way, because the audit step
             * used to sit after the tickets went. Nothing depends on
             * audit_logs, so there is no cost to going first.
             *
             * MATCHED ON THE SUBJECT, NOT JUST THE ACTOR. Rows written by a
             * seeder have no `user_id` at all — nobody is signed in — so an
             * earlier version of this matched only the actor and left 412
             * rows behind describing eighty machines that had just been
             * deleted. End-to-end testing found that; the unit tests did not,
             * because they created audit rows by hand with a user attached.
             *
             * The foreign key is SET NULL, so leaving them would break
             * nothing. It would leave an audit log full of entries about
             * assets that no longer exist, in a system whose whole claim is
             * that the audit log means something. Real users' history, and
             * history about real assets, is untouched.
             */
            ['Audit rows', 'audit_logs', fn () => DB::table('audit_logs')
                ->where(fn (Builder $q) => $q
                    ->whereIn('user_id', $demoUsers())
                    ->orWhere(fn (Builder $x) => $x->where('auditable_type', User::class)->whereIn('auditable_id', $demoUsers()))
                    ->orWhere(fn (Builder $x) => $x->where('auditable_type', LabPc::class)->whereIn('auditable_id', $demoPcs()))
                    ->orWhere(fn (Builder $x) => $x->where('auditable_type', AccessPoint::class)->whereIn('auditable_id', $demoAps()))
                    ->orWhere(fn (Builder $x) => $x->where('auditable_type', Ticket::class)
                        ->whereIn('auditable_id', DB::table('tickets')->where('is_demo', true)->select('id')))
                )],

            /*
             * Risk assessments are polymorphic, so there is no cascade to rely
             * on and no foreign key to complain. Left behind, they would be
             * history for machines that no longer exist.
             */
            ['Risk assessments', 'risk_assessments', fn () => DB::table('risk_assessments')
                ->where(fn (Builder $q) => $q
                    ->where(fn (Builder $x) => $x->where('assessable_type', LabPc::class)->whereIn('assessable_id', $demoPcs()))
                    ->orWhere(fn (Builder $x) => $x->where('assessable_type', AccessPoint::class)->whereIn('assessable_id', $demoAps()))
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

            // Cascades from access_points, but simulated readings can also
            // exist for a REAL access point — from the simulator driver — and
            // those are demonstration data too.
            ['Telemetry, hourly', 'telemetry_hourly', fn () => DB::table('telemetry_hourly')->where('source', 'simulated')],
            ['Telemetry, raw', 'telemetry_readings', fn () => DB::table('telemetry_readings')->where('source', 'simulated')],

            // Force deletes: both soft delete, and a retired demo machine is
            // still demo data.
            ['Access points', 'access_points', fn () => DB::table('access_points')->where('is_demo', true)],
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
     * Refuse to leave a real record pointing at a deleted demo asset.
     *
     * `tickets.ticketable` is polymorphic, so there is no foreign key and the
     * database will not stop this. A real ticket left pointing at a deleted
     * lab PC still renders — its asset column simply goes blank, which looks
     * like a display bug rather than the data loss it is.
     *
     * This is not hypothetical. On the machine this was written on, all three
     * genuine tickets pointed at demo lab PCs, because the demo assets were
     * the only machines there to raise a ticket against.
     */
    private function orphanCheckPasses(): bool
    {
        $orphans = DB::table('tickets')
            ->where('is_demo', false)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $x) => $x->where('ticketable_type', LabPc::class)
                    ->whereIn('ticketable_id', DB::table('lab_pcs')->where('is_demo', true)->select('id')))
                ->orWhere(fn (Builder $x) => $x->where('ticketable_type', AccessPoint::class)
                    ->whereIn('ticketable_id', DB::table('access_points')->where('is_demo', true)->select('id')))
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
     * Delete one step's rows, in chunks.
     *
     * A single DELETE across a large table holds it long enough to notice, and
     * telemetry is the largest table in the system by two orders of magnitude.
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
