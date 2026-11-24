<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\InboxItemResource;
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
        $this->notifications->markRead($request->user(), $this->notifications->resolve($request->user(), $notification)->id);

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->notifications->markRead($request->user(), 'all');

        return back();
    }
}
