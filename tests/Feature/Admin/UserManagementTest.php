<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M1 — account management.
 *
 * This is the only way a person enters the system, so the things worth pinning
 * are the ones that would either lock everyone out or let someone in who
 * should not be.
 *
 * @see docs/modules/M1-identity-and-audit.md
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->userWith(UserRole::Admin);
    }

    private function userWith(UserRole $role, bool $active = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => $active])->save();

        return $user->fresh();
    }

    // ------------------------------------------------------------ access

    public function test_an_engineer_cannot_reach_user_management(): void
    {
        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_an_assistant_cannot_reach_user_management(): void
    {
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_a_deactivated_admin_cannot_reach_user_management(): void
    {
        // FR-106: signed out by EnsureAccountIsActive before the role check.
        $this->actingAs($this->userWith(UserRole::Admin, active: false))
            ->get(route('admin.users.index'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_an_admin_sees_the_user_list(): void
    {
        $admin = $this->admin();
        $other = $this->userWith(UserRole::Engineer);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee($other->name)
            ->assertSee($other->email);
    }

    // ------------------------------------------------------------ creating

    public function test_an_admin_creates_an_account(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Nyan Lin Tun',
                'email' => 'nyan@apu.test',
                'tp_number' => 'TP086590',
                'role' => UserRole::Assistant->value,
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
            ])
            ->assertSessionHasNoErrors();

        $created = User::where('email', 'nyan@apu.test')->firstOrFail();

        $this->assertSame(UserRole::Assistant, $created->role);
        $this->assertTrue($created->is_active);
        $this->assertTrue($created->must_change_password, 'A new account must change the password an admin chose.');
    }

    public function test_a_new_account_can_sign_in_with_the_password_the_admin_set(): void
    {
        $this->actingAs($this->admin())->post(route('admin.users.store'), [
            'name' => 'Swan Yee Htet',
            'email' => 'swan@apu.test',
            'role' => UserRole::Engineer->value,
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        auth()->logout();

        $this->post('/login', ['email' => 'swan@apu.test', 'password' => 'correct-horse-battery'])
            ->assertRedirect();

        $this->assertAuthenticated();
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        $existing = $this->userWith(UserRole::Engineer);

        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Someone Else',
                'email' => $existing->email,
                'role' => UserRole::Assistant->value,
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_an_engineer_cannot_create_an_account(): void
    {
        $this->actingAs($this->userWith(UserRole::Engineer))
            ->post(route('admin.users.store'), [
                'name' => 'Sneaky', 'email' => 'sneaky@apu.test',
                'role' => UserRole::Admin->value,
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@apu.test']);
    }

    // ------------------------------------------------------------ editing

    public function test_an_admin_changes_another_users_role(): void
    {
        $target = $this->userWith(UserRole::Assistant);

        $this->actingAs($this->admin())
            ->patch(route('admin.users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'role' => UserRole::Engineer->value,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(UserRole::Engineer, $target->fresh()->role);
    }

    public function test_an_admin_cannot_change_their_own_role(): void
    {
        // The only way to leave the system with no administrator at all.
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => UserRole::Assistant->value,
        ]);

        $this->assertSame(UserRole::Admin, $admin->fresh()->role, 'An admin demoted themselves.');
    }

    public function test_leaving_the_password_blank_keeps_the_existing_one(): void
    {
        $target = $this->userWith(UserRole::Assistant);
        $before = $target->password;

        $this->actingAs($this->admin())->patch(route('admin.users.update', $target), [
            'name' => 'Renamed Person',
            'email' => $target->email,
            'role' => UserRole::Assistant->value,
            'password' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame($before, $target->fresh()->password);
        $this->assertSame('Renamed Person', $target->fresh()->name);
    }

    // ------------------------------------------------- deactivation

    public function test_an_admin_deactivates_an_account_without_deleting_it(): void
    {
        $target = $this->userWith(UserRole::Assistant);

        $this->actingAs($this->admin())
            ->patch(route('admin.users.toggle-active', $target));

        $this->assertFalse($target->fresh()->is_active);
        $this->assertNotNull(User::find($target->id), 'Deactivation must not delete the record.');
    }

    public function test_an_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch(route('admin.users.toggle-active', $admin))
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_a_deactivated_user_cannot_sign_in(): void
    {
        // FR-106 at the login form, which is the half the middleware cannot
        // cover. See the note against FR-106 in docs/01-requirements.md.
        $user = $this->userWith(UserRole::Engineer, active: false);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // ------------------------------------------------------------ auditing

    public function test_creating_an_account_is_recorded_against_the_admin_who_did_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Audited Person', 'email' => 'audited@apu.test',
            'role' => UserRole::Assistant->value,
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $created = User::where('email', 'audited@apu.test')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'created',
            'auditable_id' => $created->id,
            'description' => 'audited@apu.test',
        ]);
    }

    public function test_an_update_records_which_fields_changed(): void
    {
        $admin = $this->admin();
        $target = $this->userWith(UserRole::Assistant);

        $this->actingAs($admin)->patch(route('admin.users.update', $target), [
            'name' => 'A New Name',
            'email' => $target->email,
            'role' => UserRole::Assistant->value,
        ]);

        $log = AuditLog::where('action', 'updated')->where('auditable_id', $target->id)->latest('id')->firstOrFail();

        $this->assertArrayHasKey('name', $log->changes);
        $this->assertSame($admin->id, $log->user_id);
    }

    public function test_a_save_that_changes_nothing_writes_no_audit_row(): void
    {
        $admin = $this->admin();
        $target = $this->userWith(UserRole::Assistant);
        AuditLog::query()->delete();

        $this->actingAs($admin)->patch(route('admin.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'role' => UserRole::Assistant->value,
        ]);

        $this->assertSame(0, AuditLog::where('action', 'updated')->count());
    }

    public function test_the_audit_log_screen_is_admin_only(): void
    {
        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('admin.audit.index'))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('admin.audit.index'))
            ->assertOk();
    }

    /*
    |---------------------------------------------------------------------
    | What a value WAS, added 2026-09-04
    |---------------------------------------------------------------------
    |
    | The observer wrote getChanges(), which is new values only, so the log
    | could answer "who changed this" and not "from what". For a trail meant
    | to satisfy NFR-06 the second question is the one that gets asked, and
    | "role changed to admin" is not evidence of anything without knowing it
    | had been assistant.
    */

    public function test_the_log_records_what_a_value_was_before(): void
    {
        $admin = $this->admin();
        $target = $this->userWith(UserRole::Assistant);
        AuditLog::query()->delete();

        $this->actingAs($admin)->patch(route('admin.users.update', $target), [
            'name' => 'A New Name',
            'email' => $target->email,
            'role' => UserRole::Assistant->value,
        ]);

        $log = AuditLog::where('action', 'updated')->latest('id')->firstOrFail();

        $this->assertSame('A New Name', $log->changes['name']);
        $this->assertSame($target->name, $log->previous['name']);
    }

    public function test_the_two_halves_always_describe_the_same_fields(): void
    {
        /*
         * The guard that keeps a pair renderable. If `previous` were taken
         * from the whole original model rather than keyed off what changed,
         * it would carry fields that did not move — and, far worse, the OLD
         * password hash, which the exclusion list is there to keep out.
         */
        $admin = $this->admin();
        $target = $this->userWith(UserRole::Assistant);
        AuditLog::query()->delete();

        $target->forceFill([
            'name' => 'Renamed',
            'password' => bcrypt('a-brand-new-password'),
        ])->save();

        $log = AuditLog::where('action', 'updated')->latest('id')->firstOrFail();

        $this->assertSame(array_keys($log->changes), array_keys($log->previous));
        // That it changed, never what it was or became.
        $this->assertSame(AuditLog::SECRET_CHANGED, $log->previous['password']);
        $this->assertSame(AuditLog::SECRET_CHANGED, $log->changes['password']);
        $this->assertStringNotContainsString('$2y$', json_encode([$log->changes, $log->previous]));
    }

    public function test_a_calendar_token_never_reaches_the_audit_log(): void
    {
        /*
         * A REAL LEAK, found on the live database on 2026-09-04.
         *
         * `users.calendar_token` is a 48-character key that reads somebody's
         * roster feed without signing in — the model's own docblock says it
         * must never reach a JSON response or a log line. The observer's
         * exclusion list was a hardcoded `['updated_at', 'remember_token',
         * 'password']`, so regenerating a calendar link wrote the new token
         * into `audit_logs` in plain text, where every administrator can read
         * it. One was sitting there and still worked.
         *
         * The list now comes from the model's `$hidden`, so a secret is
         * covered by the act of declaring it secret.
         */
        $target = $this->userWith(UserRole::Assistant);
        $target->forceFill(['calendar_token' => 'the-old-token-value'])->save();
        AuditLog::query()->delete();

        /*
         * The name changes alongside the token, so a row IS written and there
         * is something to inspect. Changing only the token writes nothing at
         * all — correct, but it makes the assertion loop over an empty set and
         * pass without checking anything. PHPUnit called that out as risky.
         */
        $target->forceFill([
            'calendar_token' => 'the-new-token-value',
            'name' => 'Rotated Their Calendar Link',
        ])->save();

        $row = AuditLog::where('action', 'updated')->latest('id')->firstOrFail();
        $written = json_encode([$row->changes, $row->previous]);

        $this->assertStringContainsString('Rotated Their Calendar Link', $written, 'The row should exist.');
        $this->assertStringNotContainsString('the-new-token-value', $written, 'The new token reached the audit log.');
        $this->assertStringNotContainsString('the-old-token-value', $written, 'The old token reached the audit log.');
        $this->assertSame(AuditLog::SECRET_CHANGED, $row->changes['calendar_token']);
    }

    public function test_no_hidden_attribute_of_any_kind_is_logged(): void
    {
        /*
         * The general form, so the next secret column is covered without
         * anybody editing the observer. Changing every hidden attribute at
         * once writes one row that says each changed, and holds none of the
         * values. remember_token turns over at every remembered login, so it
         * is left out entirely.
         */
        $target = $this->userWith(UserRole::Assistant);
        AuditLog::query()->delete();

        $secrets = collect($target->getHidden())
            ->mapWithKeys(fn (string $attribute) => [$attribute => 'secret-'.$attribute])
            ->all();

        $this->assertNotEmpty($secrets, 'The User model should declare hidden attributes.');

        $target->forceFill($secrets)->save();

        $row = AuditLog::where('action', 'updated')->sole();
        $written = json_encode([$row->changes, $row->previous]);

        foreach ($secrets as $attribute => $value) {
            $this->assertStringNotContainsString($value, $written, "{$attribute}'s value reached the audit log.");
        }

        $this->assertArrayNotHasKey('remember_token', $row->changes);
        $this->assertSame(AuditLog::SECRET_CHANGED, $row->changes['password']);
    }

    public function test_the_summary_reads_as_a_sentence(): void
    {
        // What the audit screen actually prints. Before this it printed the
        // field name alone, which told a reader nothing they could act on.
        $target = $this->userWith(UserRole::Assistant);
        AuditLog::query()->delete();

        // Captured before the save: Eloquent re-syncs its originals afterwards,
        // so getOriginal() would hand back the new name.
        $wasCalled = $target->name;

        $target->forceFill(['name' => 'Second Name'])->save();

        $log = AuditLog::where('action', 'updated')->latest('id')->firstOrFail();

        $this->assertContains("name: {$wasCalled} → Second Name", $log->changeSummary());
    }

    public function test_a_row_written_before_the_column_existed_still_renders(): void
    {
        /*
         * Every audit row already in the live database has no `previous`, and
         * there is no honest way to backfill one. They must degrade to the new
         * value rather than claiming a change from nothing.
         */
        $log = AuditLog::create([
            'user_id' => null,
            'action' => 'updated',
            'auditable_type' => 'user',
            'auditable_id' => 1,
            'description' => 'someone@atlas.local',
            'changes' => ['role' => 'admin'],
            'previous' => null,
            'created_at' => now(),
        ]);

        $this->assertSame(['role: admin'], $log->changeSummary());
    }

    public function test_a_foreign_key_is_shown_as_a_name_not_a_number(): void
    {
        /*
         * "assigned to: empty → 5" is accurate and useless. Nobody reading an
         * audit log knows who 5 is, and an audit trail nobody can read is not
         * evidence of anything.
         *
         * Resolved at READ time rather than written into the log: `changes`
         * has to stay a record of what was actually put in the column.
         */
        $engineer = $this->userWith(UserRole::Engineer);
        $ticket = Ticket::factory()->create(['assigned_to' => null]);
        AuditLog::query()->delete();

        $ticket->forceFill(['assigned_to' => $engineer->id])->save();

        $log = AuditLog::where('action', 'updated')->latest('id')->firstOrFail();

        $this->assertContains(
            "assigned to: empty → {$engineer->name}",
            $log->changeSummary(AuditLog::labelsFor([$log])),
        );

        // And without the map it still renders, just as a number.
        $this->assertContains("assigned to: empty → {$engineer->id}", $log->changeSummary());
    }

    public function test_names_are_resolved_in_one_query_per_model(): void
    {
        /*
         * The audit screen paginates fifty rows. Resolving per row would be
         * fifty queries for one column, which is how a log page becomes the
         * slowest screen in the system.
         */
        $engineers = collect(range(1, 4))->map(fn () => $this->userWith(UserRole::Engineer));
        AuditLog::query()->delete();

        foreach ($engineers as $engineer) {
            Ticket::factory()->create(['assigned_to' => null])
                ->forceFill(['assigned_to' => $engineer->id])->save();
        }

        $logs = AuditLog::where('action', 'updated')->get();
        $this->assertCount(4, $logs);

        DB::enableQueryLog();
        $labels = AuditLog::labelsFor($logs);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries, 'Four rows naming four users should cost one query.');
        $this->assertCount(4, $labels['assigned_to']);
    }

    public function test_a_deactivated_user_is_still_named(): void
    {
        // An account being deactivated is exactly when its history matters.
        $engineer = $this->userWith(UserRole::Engineer);
        $ticket = Ticket::factory()->create(['assigned_to' => null]);
        AuditLog::query()->delete();

        $ticket->forceFill(['assigned_to' => $engineer->id])->save();
        $engineer->delete();

        $log = AuditLog::where('action', 'updated')->latest('id')->firstOrFail();

        $this->assertContains(
            "assigned to: empty → {$engineer->name}",
            $log->changeSummary(AuditLog::labelsFor([$log])),
        );
    }

    public function test_the_audit_screen_shows_the_change_not_just_the_field(): void
    {
        $admin = $this->admin();
        $target = $this->userWith(UserRole::Assistant);
        AuditLog::query()->delete();

        $target->forceFill(['name' => 'Visible On Screen'])->save();

        $this->actingAs($admin)
            ->get(route('admin.audit.index'))
            ->assertOk()
            ->assertSee('→ Visible On Screen');
    }
}
