<?php

use App\Http\Middleware\EndSupportSession;
use App\Http\Middleware\EnsureWorkspaceWritable;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\Idempotent;
use App\Http\Middleware\InternalApiToken;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\ResolveTokenWorkspace;
use App\Http\Middleware\ResolveWorkspace;
use App\Http\Middleware\UseCurrentWorkspace;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        // 3-R18: the internal admin API; outside the web group, so no session, cookies or CSRF.
        then: fn () => Route::prefix('internal/v1')->middleware([InternalApiToken::class, 'throttle:300,1', Idempotent::class])
            ->group(base_path('routes/internal.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            UseCurrentWorkspace::class,
            EndSupportSession::class,
            EnsureWorkspaceWritable::class,
        ]);

        $middleware->alias(['workspace' => ResolveWorkspace::class, 'require-2fa' => RequireTwoFactor::class]);

        // The workspace must be known before {project:key} is bound, and after auth.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: UseCurrentWorkspace::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveWorkspace::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolveTokenWorkspace::class);
        // 3-R19: a support session is known before anything reads it; 3-R16: the write check sees the URL's or token's workspace.
        $middleware->prependToPriorityList(before: UseCurrentWorkspace::class, prepend: EndSupportSession::class);
        $middleware->appendToPriorityList(after: SubstituteBindings::class, append: EnsureWorkspaceWritable::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The AI key must never come back as old() input after a rejected save (spec 8, A5).
        $exceptions->dontFlash(['api_key']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'internal/*') || $request->expectsJson(),
        );
    })->create();
