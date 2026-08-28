<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An attributable trail of every significant state change.
 *
 * NFR-06 requires that every significant change is attributable to a user and
 * a timestamp. Written by AuditObserver rather than by controllers, so it
 * cannot be forgotten at a call site.
 *
 * No `updated_at`: an audit row is a fact about a moment. Anything that edits
 * one has misunderstood what it is for.
 *
 * @see docs/modules/M1-identity-and-audit.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();

            // Nullable: scheduled commands act with no signed-in user, and a
            // deleted account must not take its history with it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action', 30);

            // Polymorphic: one log covers users, locations, assets and
            // everything M2 to M7 will add.
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');

            $table->text('description')->nullable();

            // json for the before/after of an update. The reason a row changed
            // is usually more useful than the fact that it did.
            $table->json('changes')->nullable();

            $table->string('ip_address', 45)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
