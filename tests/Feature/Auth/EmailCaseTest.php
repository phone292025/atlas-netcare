<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * One email address is one account, whatever its case.
 *
 * On MariaDB this held by accident: it compared text without regard to case.
 * PostgreSQL and SQLite compare case-sensitively, so `Swan@APU.test` and
 * `swan@apu.test` would be two different accounts and an admin-typed capital
 * would lock its owner out. Atlas stores the address in lower case and looks
 * it up the same way.
 *
 * @see User::normalizeEmail()
 */
class EmailCaseTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Admin, 'is_active' => true])->save();

        return $admin->fresh();
    }

    public function test_an_address_is_stored_in_lower_case_without_spaces(): void
    {
        $user = User::factory()->create(['email' => '  Swan.Yee@APU.Test ']);

        $this->assertSame('swan.yee@apu.test', $user->fresh()->email);
    }

    public function test_sign_in_ignores_the_case_of_the_address(): void
    {
        User::factory()->create(['email' => 'swan@apu.test']);

        $this->post('/login', ['email' => 'Swan@APU.test', 'password' => 'password']);

        $this->assertAuthenticated();
    }

    public function test_an_address_that_differs_only_in_case_is_already_taken(): void
    {
        User::factory()->create(['email' => 'swan@apu.test']);

        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Someone Else',
                'email' => 'SWAN@apu.test',
                'role' => UserRole::Assistant->value,
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::query()->where('email', 'swan@apu.test')->count());
    }

    public function test_a_reset_link_reaches_the_account_whatever_the_case(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'swan@apu.test']);

        $this->post('/forgot-password', ['email' => 'Swan@Apu.Test']);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_user_search_ignores_case(): void
    {
        User::factory()->create(['name' => 'Swan Yee Htet', 'email' => 'swan@apu.test']);

        // SQLite's LIKE already ignores case for ASCII, so on SQLite this
        // passes either way. It guards the PostgreSQL run, where a plain LIKE
        // is case-sensitive and the search found nothing.
        $this->actingAs($this->admin())
            ->get(route('admin.users.index', ['search' => 'SWAN']))
            ->assertOk()
            ->assertSee('Swan Yee Htet');
    }
}
