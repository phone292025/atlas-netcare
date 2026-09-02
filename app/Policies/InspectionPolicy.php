<?php

namespace App\Policies;

use App\Models\Inspection;
use App\Models\User;

/**
 * Who may act on which inspection.
 *
 * Middleware already answered "may this role reach the assistant area?". This
 * answers what it cannot: may THIS assistant touch THIS inspection?
 *
 * The rule that matters: an inspection is a signed statement that a named
 * person checked a machine on a date. Once submitted it is evidence, and
 * nobody edits it — not the assistant who wrote it, not an administrator.
 * Getting it wrong means inspecting the machine again, which is honest.
 *
 * @see docs/03-architecture.md — Authorisation
 * @see docs/modules/M3-checklists-and-inspections.md
 */
class InspectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAssistant() || $user->isEngineer() || $user->isAdmin();
    }

    /**
     * An assistant sees their own rounds; engineers and admins see all of
     * them, because an inspection is the evidence behind a ticket and a risk
     * score and both roles need to read it.
     */
    public function view(User $user, Inspection $inspection): bool
    {
        if ($user->isEngineer() || $user->isAdmin()) {
            return true;
        }

        return $this->owns($user, $inspection);
    }

    public function create(User $user): bool
    {
        return $user->isAssistant();
    }

    /**
     * Only the assistant who started it, and only while it is unsubmitted.
     */
    public function update(User $user, Inspection $inspection): bool
    {
        return $this->owns($user, $inspection) && $inspection->isInProgress();
    }

    /**
     * Abandoning a round you started and have not submitted.
     *
     * Deliberately not available on a completed inspection, to anyone. A
     * machine that was checked and failed must not be able to un-happen.
     */
    public function delete(User $user, Inspection $inspection): bool
    {
        return $this->owns($user, $inspection) && $inspection->isInProgress();
    }

    private function owns(User $user, Inspection $inspection): bool
    {
        return $inspection->user_id === $user->id;
    }
}
