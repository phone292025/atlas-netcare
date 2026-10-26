<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Create the first administrator, or let an administrator back in.
 *
 * Atlas has no self-registration (FR-105): administrators create every
 * account, so the first one has to come from somewhere outside the app. It
 * used to come from ReferenceSeeder and a starting password published in
 * .env.example and the README. On any copy reachable by someone else,
 * whoever signed in first with that password owned the system, because the
 * forced password change then let them choose a password the owner didn't
 * know. Nothing in the repository holds an admin password any more.
 *
 * Creating: the password is random and shown once, on this terminal and
 * nowhere else. The account is flagged must_change_password, so every other
 * screen stays closed until the owner chooses their own. The printed password
 * keeps working until then: only whoever ran this command has seen it.
 *
 *   php artisan atlas:create-admin
 *   php artisan atlas:create-admin --email=me@example.com --name="Phone Myat Ko"
 *
 * Restoring: an existing account becomes an active, undeleted administrator
 * again, with its password unchanged. This is the way back in after the last
 * administrator was deactivated or demoted. Use the password reset for a
 * forgotten password.
 *
 *   php artisan atlas:create-admin --restore --email=me@example.com
 *
 * Whoever can run this already has a shell on the server, so it asks for no
 * further proof. It never changes an account it wasn't asked to.
 */
class CreateAdmin extends Command
{
    protected $signature = 'atlas:create-admin
                            {--email= : The address to sign in with. Defaults to ATLAS_ADMIN_EMAIL}
                            {--name= : The display name for a new account. Defaults to ATLAS_ADMIN_NAME}
                            {--restore : Make an existing account an active administrator again, keeping its password}';

    protected $description = 'Create the first administrator, or restore an existing account as one';

    /** Long enough to be strong without symbols, so it copies cleanly from any terminal. */
    private const PASSWORD_LENGTH = 24;

    public function handle(): int
    {
        $email = User::normalizeEmail($this->option('email') ?: config('atlas.first_admin.email'));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->error("\"{$email}\" is not an email address. Nothing was changed.");

            return self::FAILURE;
        }

        $existing = User::withTrashed()->firstWhere('email', $email);

        return $this->option('restore')
            ? $this->restore($email, $existing)
            : $this->create($email, $existing);
    }

    private function create(string $email, ?User $existing): int
    {
        if ($existing) {
            $this->error("An account already uses {$email}. Nothing was changed.");
            $this->line('  To make it an administrator again, add --restore.');

            return self::FAILURE;
        }

        $password = Str::password(self::PASSWORD_LENGTH, symbols: false);

        DB::transaction(function () use ($email, $password): void {
            $admin = User::create([
                'name' => $this->option('name') ?: config('atlas.first_admin.name'),
                'email' => $email,
                'password' => Hash::make($password),
            ]);

            // Not mass assignable on purpose: privilege is never set from
            // input. See the $fillable docblock on App\Models\User.
            $admin->forceFill([
                'role' => UserRole::Admin,
                'is_active' => true,
                'is_demo' => false,
                'must_change_password' => true,
                'email_verified_at' => now(),
            ])->save();
        });

        $this->info("Administrator created: {$email}");
        $this->line("  Password: {$password}");
        $this->line('  Shown once, here only. At the first sign-in Atlas asks you to choose your own.');

        return self::SUCCESS;
    }

    private function restore(string $email, ?User $existing): int
    {
        if (! $existing) {
            $this->error("No account uses {$email}. Nothing was changed.");
            $this->line('  To create one, run the command without --restore.');

            return self::FAILURE;
        }

        $existing->forceFill([
            'role' => UserRole::Admin,
            'is_active' => true,
            'is_demo' => false,
            'deleted_at' => null,
        ])->save();

        $this->info("{$email} is an active administrator again.");
        $this->line('  Its password is unchanged. If it is forgotten, use "Forgot password" on the sign-in page.');

        return self::SUCCESS;
    }
}
