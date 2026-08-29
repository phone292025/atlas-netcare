<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * `php artisan atlas:mail-test` — the only check that says anything real about
 * whether FR-102 can deliver.
 *
 * The suite fakes the mailer, so it can prove the command reports the right
 * thing in each state, and cannot prove SMTP works. That is the point of the
 * command existing: somebody has to run it against the real Gmail account
 * after pasting an app password, because nothing automated will.
 *
 * @see app/Console/Commands/MailTest.php
 * @see docs/04-open-questions.md Q11
 */
class MailTestCommandTest extends TestCase
{
    public function test_it_refuses_to_pretend_the_log_mailer_sent_anything(): void
    {
        config(['mail.default' => 'log']);

        $this->artisan('atlas:mail-test', ['to' => 'swan@example.com'])
            ->expectsOutputToContain('nothing will be sent anywhere')
            ->assertExitCode(1);
    }

    public function test_it_names_the_empty_app_password(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.password' => '',
        ]);

        $this->artisan('atlas:mail-test', ['to' => 'swan@example.com'])
            ->expectsOutputToContain('MAIL_PASSWORD is empty')
            ->assertExitCode(1);
    }

    public function test_it_sends_when_smtp_looks_configured(): void
    {
        Mail::fake();

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.password' => 'sixteencharacter',
        ]);

        $this->artisan('atlas:mail-test', ['to' => 'swan@example.com'])
            ->assertExitCode(0);
    }

    public function test_a_rejected_login_is_explained_rather_than_dumped(): void
    {
        /*
         * Gmail's 535 arrives with a support URL and no advice, and the real
         * cause is nearly always a pasting mistake — the account password
         * instead of an app password, or the spaces Google displays left in.
         * Printing the raw line and stopping there sends people to check the
         * wrong things.
         */
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.password' => 'wrong',
        ]);

        Mail::shouldReceive('raw')
            ->once()
            ->andThrow(new TransportException(
                'Expected response code "250" but got code "535", with message '
                .'"535-5.7.8 Username and Password not accepted."'
            ));

        $this->artisan('atlas:mail-test', ['to' => 'swan@example.com'])
            ->expectsOutputToContain('APP password')
            ->assertExitCode(1);
    }

    public function test_it_falls_back_to_the_from_address(): void
    {
        Mail::fake();

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.password' => 'sixteencharacter',
            'mail.from.address' => 'atlas@example.com',
        ]);

        $this->artisan('atlas:mail-test')
            ->expectsOutputToContain('atlas@example.com')
            ->assertExitCode(0);
    }
}
