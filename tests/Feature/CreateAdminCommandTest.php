<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `atlas:create-admin`, the only way the first administrator comes to exist.
 *
 * It replaced a seeded account with a starting password published in
 * .env.example and the README. On any copy someone else could reach, whoever
 * signed in first with that password owned the system. These tests pin the
 * replacement: a random password shown once, the forced change, and no admin
 * password setting in the repository. A demo install's default administrator
 * uses the public demo password, so this command never turns a demo account
 * into a real administrator.
 *
 * @see App\Console\Commands\CreateAdmin
 */
class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Run the command and return the starting password it printed. */
    private function createAdmin(array $options = []): string
    {
        $exit = Artisan::call('atlas:create-admin', $options);
        // Read once: Artisan::output() empties its buffer when read.
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);

        preg_match('/Password: (\S+)/', $output, $match);
        $this->assertNotEmpty($match[1] ?? null, 'The command did not print the password.');

        return $match[1];
    }

    private function admin(): User
    {
        return User::withTrashed()->where('email', config('atlas.first_admin.email'))->firstOrFail();
    }

    // ------------------------------------------------------------- creating

    public function test_it_creates_an_active_administrator_that_is_not_demo_data(): void
    {
        $this->createAdmin();

        $admin = $this->admin();

        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertFalse($admin->is_demo, 'A purge of demo data would remove the only way in.');
        $this->assertNotNull($admin->email_verified_at);
    }

    public function test_the_printed_password_signs_in_once_and_must_then_be_replaced(): void
    {
        $password = $this->createAdmin();

        $this->assertTrue($this->admin()->must_change_password);
        $this->assertNotSame($password, $this->admin()->password, 'The password was stored as typed.');

        $this->post('/login', ['email' => config('atlas.first_admin.email'), 'password' => $password])
            ->assertRedirect();
        $this->assertAuthenticated();

        // Every screen but the one to choose a new password stays closed.
        $this->get(route('admin.users.index'))->assertRedirect(route('profile.edit'));
    }

    public function test_every_run_generates_a_different_password(): void
    {
        $first = $this->createAdmin(['--email' => 'first@atlas.test']);
        $second = $this->createAdmin(['--email' => 'second@atlas.test']);

        $this->assertNotSame($first, $second);
        $this->assertGreaterThanOrEqual(24, strlen($first));
    }

    public function test_the_address_and_name_can_be_given_and_the_address_is_stored_in_lower_case(): void
    {
        $this->createAdmin(['--email' => 'Phone@Atlas.TEST', '--name' => 'Phone Myat Ko']);

        $admin = User::where('email', 'phone@atlas.test')->firstOrFail();

        $this->assertSame('Phone Myat Ko', $admin->name);
        $this->assertSame(UserRole::Admin, $admin->role);
    }

    public function test_an_address_already_in_use_is_refused_and_left_alone(): void
    {
        $existing = User::factory()->create(['email' => 'taken@atlas.test']);
        $existing->forceFill(['role' => UserRole::Assistant])->save();
        $password = $existing->fresh()->password;

        $this->artisan('atlas:create-admin', ['--email' => 'TAKEN@atlas.test'])
            ->expectsOutputToContain('already uses taken@atlas.test')
            ->assertFailed();

        $this->assertSame(UserRole::Assistant, $existing->fresh()->role, 'An existing account was promoted.');
        $this->assertSame($password, $existing->fresh()->password, 'An existing password was replaced.');
    }

    public function test_an_archived_account_counts_as_in_use(): void
    {
        $archived = User::factory()->create(['email' => 'gone@atlas.test']);
        $archived->delete();

        $this->artisan('atlas:create-admin', ['--email' => 'gone@atlas.test'])->assertFailed();

        $this->assertSame(1, User::withTrashed()->where('email', 'gone@atlas.test')->count());
    }

    public function test_an_invalid_address_is_refused(): void
    {
        $this->artisan('atlas:create-admin', ['--email' => 'not-an-address'])
            ->expectsOutputToContain('is not an email address')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_creating_the_administrator_is_audited_without_the_password(): void
    {
        $password = $this->createAdmin();

        $entry = AuditLog::query()
            ->where('auditable_type', $this->admin()->getMorphClass())
            ->where('auditable_id', $this->admin()->getKey())
            ->get();

        $this->assertNotEmpty($entry, 'Creating the administrator left no audit entry.');
        $this->assertStringNotContainsString($password, $entry->toJson());
        $this->assertStringNotContainsString($this->admin()->password, $entry->toJson(), 'The password hash reached the audit log.');
    }

    // ------------------------------------------------------------ restoring

    public function test_restore_brings_back_a_locked_out_administrator_without_touching_the_password(): void
    {
        /*
         * Deactivating, demoting or archiving the last administrator is the
         * mistake somebody makes at 2am. --restore is the way back in. It
         * used to be a side effect of `db:seed`.
         */
        $this->createAdmin();
        $admin = $this->admin();
        $admin->forceFill([
            'password' => Hash::make('the-one-they-chose'),
            'must_change_password' => false,
            'is_active' => false,
            'role' => UserRole::Assistant,
        ])->save();
        $admin->delete();

        $this->artisan('atlas:create-admin', ['--restore' => true])->assertSuccessful();

        $restored = $this->admin();
        $this->assertSame(UserRole::Admin, $restored->role);
        $this->assertTrue($restored->is_active);
        $this->assertNull($restored->deleted_at);
        $this->assertTrue(Hash::check('the-one-they-chose', $restored->password), 'Restoring changed the password.');
        $this->assertFalse($restored->must_change_password, 'Restoring sent them back to the change screen.');
    }

    public function test_the_default_administrators_address_is_refused(): void
    {
        // What running it bare on a demo install meets: ATLAS_ADMIN_EMAIL is
        // the demo administrator, whose password is public.
        $this->seed(DemoDataSeeder::class);

        $this->artisan('atlas:create-admin')
            ->expectsOutputToContain('is a demo account, and its password is public')
            ->expectsOutputToContain('--email=you@example.com')
            ->assertFailed();

        $this->assertTrue($this->admin()->is_demo);
        $this->assertTrue(Hash::check(config('atlas.demo_users.password'), $this->admin()->password));
    }

    public function test_restore_never_makes_a_demo_account_a_real_administrator(): void
    {
        // It would keep the public password and survive the purge.
        $this->seed(DemoDataSeeder::class);

        $this->artisan('atlas:create-admin', ['--restore' => true, '--email' => config('atlas.first_admin.email')])
            ->expectsOutputToContain('is a demo account')
            ->assertFailed();

        $this->assertTrue($this->admin()->is_demo);
    }

    public function test_restore_refuses_an_address_nobody_uses(): void
    {
        $this->artisan('atlas:create-admin', ['--restore' => true, '--email' => 'nobody@atlas.test'])
            ->expectsOutputToContain('No account uses nobody@atlas.test')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    // ------------------------------------------------------- nothing public

    public function test_no_administrator_password_ships_in_the_repository(): void
    {
        // The guard against the old setup coming back in a later edit.
        $this->assertStringNotContainsString('ATLAS_ADMIN_PASSWORD', file_get_contents(base_path('.env.example')));
        $this->assertArrayNotHasKey('password', config('atlas.first_admin'));
        $this->assertStringNotContainsString('AtlasAdmin!', file_get_contents(base_path('README.md')));
    }
}
