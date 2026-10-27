<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Probe agents, and which one watches each network device (ADR-0010,
 * ADR-0017, roadmap task 1.2).
 *
 * An agent is a program inside a network that measures its devices and pushes
 * readings to Atlas. It proves who it is with a token, of which only the
 * SHA-256 hash is stored: the token is shown once, when it is issued, and a
 * copy of this table yields none. The hint is its last four characters, so an
 * administrator can tell which token a machine has.
 *
 * Retiring an agent is a soft delete, so its token stops working and its
 * readings keep pointing at it. A device is watched by one agent at most.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();

            // Null only for the simulator's virtual agent, which never calls
            // the API (task 1.5).
            $table->char('token_hash', 64)->nullable()->unique();
            $table->string('token_hint', 4)->nullable();
            $table->dateTime('token_issued_at')->nullable();
            $table->boolean('is_virtual')->default(false);

            // Written on every authenticated request, never from a form.
            $table->dateTime('last_seen_at')->nullable();
            $table->integer('clock_offset_ms')->nullable();
            $table->string('version', 40)->nullable();
            $table->string('last_address', 45)->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('network_devices', function (Blueprint $table): void {
            $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->index('agent_id');
        });
    }

    public function down(): void
    {
        Schema::table('network_devices', function (Blueprint $table): void {
            $table->dropForeign(['agent_id']);
            $table->dropIndex(['agent_id']);
            $table->dropColumn('agent_id');
        });

        Schema::drop('agents');
    }
};
