<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record what a value WAS, not only what it became.
 *
 * `AuditObserver` writes `getChanges()`, which is the new values only. That
 * answers "who changed the checklist" and cannot answer "from what" — and for
 * an audit trail meant to satisfy NFR-06, the second question is the one that
 * gets asked. "Priority changed to critical" is not evidence of anything
 * without knowing it had been low.
 *
 * A separate nullable column rather than reshaping `changes` into
 * `{field: {from, to}}`: rows already written have no old values to backfill,
 * and rewriting the shape would mean every existing row needing a migration
 * that cannot be correct. Old rows keep working and simply have nothing here,
 * which is honest.
 *
 * @see app/Observers/AuditObserver.php
 * @see docs/01-requirements.md FR-509, NFR-06
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->json('previous')->nullable()->after('changes');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('previous');
        });
    }
};
