<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\InboxItemResource;
use App\Models\Issue;
use App\Services\CurrentWorkspace;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Spec 7.10 (3-R25): the inbox page; web only. */
class NotificationController extends Controller
{
    public function __construct(private NotificationService $notifications, private CurrentWorkspace $current) {}

    public function index(Request $request): Response
    {
        $page = $this->notifications->inbox($request->user(), $this->current->get());

        return Inertia::render('Notifications/Index', ['items' => InboxItemResource::collection($page)->response()->getData(true)]);
    }

    public function read(Request $request, int $notification): RedirectResponse
    {
        $item = $this->notifications->resolve($request->user(), $notification);
        $this->notifications->markRead($request->user(), $item->id);
        if (! $request->boolean('open')) {
            return back();
        }
        // Opening: the issue's page, or the inbox again when the reader can no longer see it (nothing is hinted).
        $issue = $item->issue_id ? Issue::visibleTo($request->user())->with('project')->find($item->issue_id) : null;

        return $issue ? redirect()->route('issues.show', $issue->key) : redirect()->route('notifications.index');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->notifications->markRead($request->user(), 'all');

        return back();
    }
}
