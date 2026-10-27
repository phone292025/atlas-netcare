<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AgentRequest;
use App\Models\Agent;
use App\Services\Telemetry\AgentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Probe agents and their tokens (ADR-0017).
 *
 * Registering and rotating answer with the token page itself rather than a
 * redirect, so the token is never put in the session, which the database
 * session driver would write to the sessions table. Reloading that page asks
 * to resubmit, which issues another token: the earlier one stops working,
 * and nothing is left half done.
 *
 * Retiring is final. Bringing an agent back would revive a token that may be
 * on a lost machine, so a returning probe is registered again.
 */
class AgentController extends Controller
{
    public function __construct(private readonly AgentService $agents) {}

    public function index(Request $request): View
    {
        return view('admin.agents.index', [
            'agents' => Agent::query()
                ->where('is_virtual', false)
                ->withCount('devices')
                ->when($request->boolean('retired'), fn ($q) => $q->withTrashed())
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.agents.create');
    }

    public function store(AgentRequest $request): View
    {
        ['agent' => $agent, 'token' => $token] = $this->agents->register($request->validated('name'));

        return view('admin.agents.token', ['agent' => $agent, 'token' => $token, 'fresh' => true]);
    }

    public function show(Agent $agent): View
    {
        abort_if($agent->is_virtual, 404);

        return view('admin.agents.show', [
            'agent' => $agent,
            'devices' => $agent->devices()->with('location')->orderBy('code')->get(),
        ]);
    }

    public function rotate(Agent $agent): View
    {
        abort_if($agent->is_virtual, 404);

        $token = $this->agents->issue($agent);

        return view('admin.agents.token', ['agent' => $agent, 'token' => $token, 'fresh' => false]);
    }

    public function destroy(Agent $agent): RedirectResponse
    {
        abort_if($agent->is_virtual, 404);

        $this->agents->retire($agent);

        return redirect()
            ->route('admin.agents.index')
            ->with('success', "{$agent->name} is retired. Its token no longer works, and its readings are kept.");
    }
}
