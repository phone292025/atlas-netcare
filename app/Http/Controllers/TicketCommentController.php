<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tickets\StoreCommentRequest;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;

/**
 * Comments on a ticket (FR-308).
 *
 * Shared by both sides — an assistant and an engineer post to the same place,
 * and the only difference is whether they may mark a comment internal.
 *
 * @see docs/modules/M4-ticketing.md
 */
class TicketCommentController extends Controller
{
    public function store(StoreCommentRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('comment', $ticket);

        /*
         * An assistant asking for is_internal does not get it. The checkbox is
         * not rendered for them, but a checkbox that is merely absent from the
         * page is not a permission check — the field can be added back with
         * any browser's dev tools.
         */
        $internal = $request->boolean('is_internal')
            && $request->user()->can('commentInternally', $ticket);

        $ticket->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $request->validated('body'),
            'is_internal' => $internal,
        ]);

        return back()->with('success', $internal ? 'Internal note added.' : 'Comment added.');
    }
}
