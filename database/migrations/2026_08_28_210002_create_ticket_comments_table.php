<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A note on a ticket, optionally visible only to staff (FR-308).
 *
 * @see docs/04-database.md
 * @see docs/modules/M4-ticketing.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_comments', function (Blueprint $table): void {
            $table->id();

            // Owned by the ticket. A ticket is soft-deleted, never hard, so
            // this cascade only fires if one is genuinely purged.
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();

            // Who said it must survive them leaving, same as the ticket itself.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->text('body');

            /*
             * Internal comments are engineer-to-engineer and are never shown
             * to the assistant who reported the fault. The filtering is done
             * in a query scope rather than in Blade — a view that decides
             * visibility is one @if away from leaking it.
             */
            $table->boolean('is_internal')->default(false);

            $table->timestamps();

            // The detail page reads exactly this: one ticket's comments, oldest
            // first.
            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_comments');
    }
};
