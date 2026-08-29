<?php

use App\Http\Controllers\ShiftCalendarController;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A secret per person, so their calendar app can fetch their roster.
 *
 * Calendar applications cannot sign in. They fetch a URL on a schedule, with
 * no session and no opportunity to prompt for a password. So the URL itself
 * has to carry the authorisation, which means a long unguessable token.
 *
 * NULL until the assistant asks for a subscription link, so nobody has a live
 * feed they never requested. Regenerating replaces the token and instantly
 * breaks every copy of the old link, which is the revoke button.
 *
 * What the feed exposes if a link leaks: one person's shift dates, times and
 * room codes. Not nothing, but not credentials either, and it grants no access
 * to the application.
 *
 * @see ShiftCalendarController
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('calendar_token', 64)->nullable()->unique()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('calendar_token');
        });
    }
};
