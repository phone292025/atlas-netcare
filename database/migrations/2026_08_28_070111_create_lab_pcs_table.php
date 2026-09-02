<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * University-owned lab workstations.
 *
 * Q7: these machines never report their own health. No agent is installed on
 * campus PCs, so everything the system knows about one arrives through an
 * assistant completing a checklist or raising a ticket.
 *
 * @see docs/04-database.md — Cluster A
 * @see docs/modules/M2-locations-and-assets.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_pcs', function (Blueprint $table): void {
            $table->id();

            // Duplicate asset tags are the classic inventory demo failure, so
            // this is enforced by the database and not only by a form rule.
            $table->string('asset_tag', 40)->unique();

            // restrictOnDelete: a location with machines in it cannot be
            // deleted out from under them.
            $table->foreignId('location_id')->constrained()->restrictOnDelete();

            $table->string('hostname', 60)->nullable();
            $table->string('cpu')->nullable();
            $table->unsignedSmallInteger('ram_gb')->nullable();
            $table->string('storage', 60)->nullable();
            $table->string('os_version', 60)->nullable();

            // Age is a risk factor in M7's scoring, which is why the purchase
            // date is worth capturing even though nothing reads it yet.
            $table->date('purchase_date')->nullable();
            $table->date('warranty_expiry')->nullable();

            $table->string('status', 20)->default('operational');

            // Denormalised cache of the latest risk_assessments row, so an
            // index listing 250 machines needs no subquery. M7 writes both in
            // the same transaction as the assessment.
            $table->decimal('risk_score', 5, 2)->nullable();
            $table->string('risk_band', 20)->nullable();

            $table->timestamp('last_inspected_at')->nullable();

            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->softDeletes();

            // Retiring a machine must keep its inspection and ticket history,
            // so index the active set rather than assuming rows disappear.
            $table->index(['location_id', 'status']);
            $table->index('risk_band');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_pcs');
    }
};
