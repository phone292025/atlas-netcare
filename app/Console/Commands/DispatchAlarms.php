<?php

namespace App\Console\Commands;

use App\Services\Shifts\AlarmService;
use App\Services\Tickets\TicketService;
use Illuminate\Console\Command;

/**
 * Send every alarm that has come due.
 *
 * Alarms are written ahead of time with a `trigger_at`; this is what turns
 * them into something a person can see. Runs every minute — a reminder that
 * arrives twenty minutes after the round should have started is worse than
 * none, because it teaches people the reminders are not to be trusted.
 *
 * @see docs/modules/M6-shifts-and-alarms.md
 */
class DispatchAlarms extends Command
{
    protected $signature = 'atlas:dispatch-alarms';

    protected $description = 'Send scheduled alarms that have come due';

    public function handle(AlarmService $alarms, TicketService $tickets): int
    {
        /*
         * Raise, then send, in that order and in one run — so a ticket that
         * goes overdue is noticed and delivered within the same minute rather
         * than waiting for the next pass.
         *
         * The SLA rule itself belongs to M4: `Ticket::scopeBreaching()` and
         * `TicketService::raiseSlaBreachAlarms()`. This command only decides
         * when to ask.
         */
        $breaches = $tickets->raiseSlaBreachAlarms();

        if ($breaches > 0) {
            $this->warn($breaches.' SLA '.str('breach')->plural($breaches).' raised.');
        }

        $sent = $alarms->dispatchDue();

        $this->info($sent.' '.str('alarm')->plural($sent).' sent.');

        return self::SUCCESS;
    }
}
