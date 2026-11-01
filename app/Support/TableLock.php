<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One writer at a time on a small table, until the transaction ends.
 *
 * For a rule that reads several rows and then writes, where a unique index
 * cannot say the whole rule: "fill each roster slot up to its count",
 * "version numbers go up by one per name". Two requests that both read
 * before either writes would each act on the same stale picture. Taking
 * this lock first makes the second wait for the first to commit, then read
 * what it wrote.
 *
 * On PostgreSQL it is `LOCK TABLE ... IN SHARE ROW EXCLUSIVE MODE`, which
 * conflicts with itself and with every INSERT, UPDATE and DELETE, and lets
 * plain reads through. SQLite, which only the test suite runs on, already
 * allows one writing transaction per database, so there is nothing to add.
 *
 * Only for tables written rarely and by people (rosters, checklists). Never
 * for telemetry, which is written every few seconds.
 */
final class TableLock
{
    public static function forWriting(string $table): void
    {
        if (DB::transactionLevel() === 0) {
            // Outside a transaction the lock would be released at once.
            throw new LogicException("Lock {$table} inside a transaction.");
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('LOCK TABLE '.DB::getQueryGrammar()->wrapTable($table).' IN SHARE ROW EXCLUSIVE MODE');
        }
    }
}
