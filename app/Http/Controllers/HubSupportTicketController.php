<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\HubSupportTicket;
use App\Models\User;
use App\Services\HubSupport\HubSupportTicketSubmissionService;
use App\Services\HubSupport\HubSupportTicketWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class HubSupportTicketController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->reporter($request);

        return view('hub-support.index', [
            'reports' => HubSupportTicket::query()->where('reported_by_user_id', $user->id)
                ->latest()->paginate(20),
        ]);
    }

    public function create(Request $request): View
    {
        $this->reporter($request);

        return view('hub-support.create');
    }

    public function store(Request $request, HubSupportTicketSubmissionService $service): JsonResponse|RedirectResponse
    {
        $user = $this->reporter($request);
        $input = $request->all();
        foreach (['client_metadata', 'diagnostics'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $decoded = json_decode($input[$field], true);
                $input[$field] = is_array($decoded) ? $decoded : [];
            }
        }
        $result = $service->submit($user, $input, $request->file('attachments', []));
        $report = $result->ticket;

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Thanks — we got it.',
                'report' => [
                    'id' => $report->id,
                    'reference' => $report->ticket_number,
                    'status' => $report->status->memberLabel(),
                    'url' => route('hub-support.show', $report),
                ],
            ], $result->created ? 201 : 200);
        }

        return redirect()->route('hub-support.show', $report)->with('status', 'Thanks — we got it.');
    }

    public function show(Request $request, HubSupportTicket $ticket): View
    {
        $this->authorizeOwner($request, $ticket);

        return view('hub-support.show', [
            'report' => $ticket->load(['updates' => fn ($query) => $query
                ->whereNotNull('public_response')->orderBy('created_at')->orderBy('id'), 'attachments']),
        ]);
    }

    public function reply(Request $request, HubSupportTicket $ticket, HubSupportTicketWorkflowService $workflow): RedirectResponse
    {
        $this->authorizeOwner($request, $ticket);
        $workflow->reply($ticket, $this->reporter($request), (string) $request->input('response', ''));

        return back()->with('status', 'Your reply was sent.');
    }

    private function reporter(Request $request): User
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $user->isAuthenticationAllowed() && ! $user->must_change_password, 403);

        return $user;
    }

    private function authorizeOwner(Request $request, HubSupportTicket $ticket): void
    {
        abort_unless($ticket->reported_by_user_id === $this->reporter($request)->id, 403);
    }
}
