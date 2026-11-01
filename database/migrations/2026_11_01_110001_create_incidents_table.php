<?php

use App\Services\Incidents\IncidentService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A fault the system has noticed (ADR-0014, roadmap 2.2).
 *
 * An incident sits between readings and tickets. A rule opens it on a device,
 * or on an agent for "silent"; it can be acknowledged; it resolves itself
 * when the device recovers. A ticket is created from it when the fault needs
 * work, at most one per incident. Not unique the other way: a device that
 * fails again while its ticket is still open shares that ticket, rather than
 * putting a second one in the queue for the same fault.
 *
 * One open incident per rule and device, and per rule and agent, held by two
 * partial unique indexes. Two workers noticing the same outage at once get
 * one incident, not two.
 *
 * `grouped_into_id`: an incident on an access point that turned out to sit
 * behind a failed switch is resolved into the switch's incident (roadmap
 * 2.3), and points at it.
 *
 * `escalation_step` and `next_escalation_at` drive on-call (roadmap 2.5):
 * step 0 is the assistant on shift, 1 the engineers, 2 the administrators.
 *
 * @see IncidentService
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();

            $table->string('rule', 20);
            $table->string('status', 20)->default('open');
            $table->string('title');

            $table->foreignId('network_device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('grouped_into_id')->nullable()->constrained('incidents')->nullOnDelete();

            $table->dateTime('opened_at');
            $table->dateTime('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->string('resolution', 20)->nullable();

            $table->unsignedTinyInteger('escalation_step')->default(0);
            $table->dateTime('next_escalation_at')->nullable();

            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            // The incidents screen: unresolved first, newest first.
            $table->index(['resolved_at', 'opened_at']);
            // The escalation sweep, every minute.
            $table->index('next_escalation_at');
        });

        DB::statement('CREATE UNIQUE INDEX incidents_one_open_per_device_unique ON incidents (network_device_id, rule) WHERE resolved_at IS NULL AND network_device_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX incidents_one_open_per_agent_unique ON incidents (agent_id, rule) WHERE resolved_at IS NULL AND agent_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
