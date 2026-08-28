<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campus places that house assets: labs, the library, the cafeteria.
 *
 * Reference data, not demo data — seeded by ReferenceSeeder and never tagged
 * is_demo, because a real deployment needs these on day one.
 *
 * @see docs/06-database.md — Cluster A
 * @see docs/modules/M2-locations-and-assets.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();

            // Human-facing identifier used on signage and in conversation:
            // "L6-IT-01". Unique so two labs cannot share a code.
            $table->string('code', 30)->unique();
            $table->string('name');

            // Cast to no enum on purpose — nothing branches on this value, it
            // is a label for filtering and reporting. See ADR-0004.
            $table->string('type', 20)->default('other');

            $table->string('floor', 20)->nullable();
            $table->text('description')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
