<?php

namespace App\Http\Middleware;

use App\Services\AiSettingsService;
use App\Services\CurrentWorkspace;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            // Spec 7.0a: the workspace the page acts in and the switcher's list.
            'workspace' => function () use ($request) {
                $workspace = app(CurrentWorkspace::class)->id() ? app(CurrentWorkspace::class)->get() : null;

                return $workspace && $request->user() ? [
                    'id' => $workspace->id, 'name' => $workspace->name, 'slug' => $workspace->slug,
                    'role' => $request->user()->workspaceRole($workspace)?->value,
                ] : null;
            },
            'workspaces' => fn () => $request->user()?->workspaces()->orderBy('name')->get(['name', 'slug'])
                ->map(fn ($w) => ['name' => $w->name, 'slug' => $w->slug])->all() ?? [],
            // Spec 8 (A5): only whether a provider is set; plain-English search and Mode C hide their controls without one.
            'ai' => fn () => ['enabled' => app(CurrentWorkspace::class)->id() && app(AiSettingsService::class)->for(app(CurrentWorkspace::class)->get()) !== null],
            // 3-R25: the bell's count; one indexed query.
            'unread_notifications' => fn () => $request->user() && app(CurrentWorkspace::class)->id() ? app(NotificationService::class)->unreadCount($request->user(), app(CurrentWorkspace::class)->get()) : 0,
            'edition' => config('pm.edition'),
            'flash' => [
                'token' => fn () => $request->session()->get('token'),
                'tokenAgent' => fn () => $request->session()->get('token_agent'),
                // Spec 7.0c: what the plan-limit dialog shows after a refused action.
                'planLimit' => fn () => $request->session()->get('planLimit'),
                // Spec 7.0e: shown once, right after they are made.
                'recoveryCodes' => fn () => $request->session()->get('recoveryCodes'),
                // {message, method, url, data}: what the toast sends back when the person picks Undo (UndoService).
                'undo' => fn () => $request->session()->get('undo'),
                // A notice the toast shows after a save that went through (e.g. no date shifts proposed: too many follow).
                'warning' => fn () => $request->session()->get('warning'),
                // Spec 7.5: {key, shifts: [{key, start_date, due_date, note}]} after a due date moved and "ask" shifted nothing, for the shift dialog.
                'dateShift' => fn () => $request->session()->get('dateShift'),
                // Spec 7.11: {key, version, url, yours, theirs, by, at} after a stale save, for the conflict dialog (EditConflict).
                'conflict' => fn () => $request->session()->get('conflict'),
            ],
        ];
    }
}
