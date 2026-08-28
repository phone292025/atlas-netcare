<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One traceable fault report, from an assistant noticing it to an engineer
 * closing it.
 *
 * POLYMORPHIC. A ticket can be about a lab PC or an access point, so
 * `ticketable_type` + `ticketable_id` carries both rather than two ticket
 * tables or two nullable foreign keys.
 *
 * The lifecycle this table supports is the one proposed in Q3 and adopted on
 * 2026-08-28. `status` is a plain string column cast to TicketStatus, so
 * changing the lifecycle is a code change in TicketService rather than an
 * ALTER TABLE on a table six other people have locally (ADR-0004).
 *
 * @see docs/06-database.md
 * @see docs/04-open-questions.md Q3
 * @see docs/modules/M4-ticketing.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();

            // Human-readable and quotable over the phone: TKT-2026-00417.
            // Reserved by TicketService, not a database default.
            $table->string('ticket_number', 20)->unique();

            $table->string('title');
            $table->text('description');

            $table->string('category', 20);
            $table->string('priority', 20);
            $table->string('status', 20)->default('open');

            /*
             * The asset this is about. Nullable because a fault can be
             * reported against a place rather than a machine — "no network in
             * the whole of Level 6" is a real ticket and belongs to nothing
             * more specific than a location.
             */
            $table->nullableMorphs('ticketable');

            // Where it is. Denormalised from the asset on purpose so the queue
            // can filter by location without joining two different asset
            // tables through a polymorphic relation.
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            /*
             * The inspection that produced this, when it came from a failed
             * checklist item (FR-207). Null for a ticket raised by hand or by
             * M5's collector.
             */
            $table->foreignId('inspection_id')->nullable()->constrained()->nullOnDelete();

            // restrictOnDelete: a ticket must outlive the person leaving.
            // Accounts deactivate rather than delete, so this never fires in
            // normal use — it is here to stop a later hard delete erasing who
            // reported a fault.
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('opened_at');

            // Computed at creation from priority and config('atlas.sla_hours').
            $table->timestamp('due_at')->nullable();

            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->text('resolution_notes')->nullable();

            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'priority']);
            $table->index('assigned_to');
            $table->index('reported_by');

            // "Is this asset already the subject of an open ticket?" — the
            // question M5's collector asks before auto-raising another one.
            $table->index(['ticketable_type', 'ticketable_id', 'status']);

            // The SLA sweep: outstanding tickets past their due time.
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
