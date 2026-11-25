<?php

namespace App\Providers;

use App\Enums\Entitlement;
use App\Http\Controllers\Web\OAuthController;
use App\Mcp\OAuth\AgentAccessTokenRepository;
use App\Mcp\OAuth\AgentAuthCodeRepository;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\CurrentWorkspace;
use App\Services\Entitlements;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Passport;
use Laravel\Sanctum\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentWorkspace::class);

        // A-D3: OAuth for MCP clients. Only the routes in routes/ai.php; tokens carry the agent picked on consent.
        Passport::ignoreRoutes();
        $this->app->bind(AuthCodeRepository::class, AgentAuthCodeRepository::class);
        $this->app->bind(AccessTokenRepository::class, AgentAccessTokenRepository::class);
        $this->app->when(OAuthController::class)->needs(StatefulGuard::class)->give(fn () => Auth::guard('web'));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
        // 3-R15: `php artisan dev` starts Reverb only when live updates are configured; otherwise pages poll.
        $reverb = config('broadcasting.connections.reverb');
        if (config('broadcasting.default') !== 'reverb' || ! $reverb['key'] || ! $reverb['secret'] || ! $reverb['app_id']) {
            DevCommands::except('reverb');
        }
        // Names people choose (workspace, inviter, project) are echoed into Markdown mail: keep them text, not links.
        Markdown::withSecuredEncoding();
        Model::preventLazyLoading(! $this->app->isProduction());

        // Every token belongs to the workspace it was made in (spec 7.0a); one made outside a workspace is never accepted.
        PersonalAccessToken::creating(fn (PersonalAccessToken $token) => $token->workspace_id ??= app(CurrentWorkspace::class)->id());

        Passport::authorizationView(OAuthController::consent(...));
        // Every call re-checks the agent anyway; short access tokens keep a leaked one short-lived. Clients refresh.
        Passport::tokensExpireIn(now()->addHour());

        // Per-agent API budget (spec 7.0c, 8 A3): api_per_minute and api_per_day of the token's workspace, else
        // PM_API_RATE_LIMIT per minute and no daily limit. Keyed by agent, so a regenerated token keeps the count and
        // another agent of the same owner isn't slowed. Runs before ResolveTokenWorkspace (the middleware priority list puts ThrottleRequests ahead of it), so it
        // reads the token's agent itself and the workspace from the agent row.
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();
            $agent = $user?->agent();
            $by = $agent ? "agent:{$agent->id}" : ($user ? "user:{$user->id}" : "ip:{$request->ip()}");
            $workspace = $agent ? Workspace::find($agent->workspace_id) : null;
            if ($workspace) {
                // 3-R20: API calls per workspace per day, for the admin API's usage.
                // shortcut: one write per API call, move to a cache counter if it shows up in profiling
                DB::table('workspace_api_usage')->upsert(['workspace_id' => $workspace->id, 'day' => now()->toDateString(), 'calls' => 1], ['workspace_id', 'day'], ['calls' => DB::raw('calls + 1')]);
            }
            $entitlements = app(Entitlements::class);
            $perMinute = ($workspace ? $entitlements->get($workspace, Entitlement::ApiPerMinute) : null) ?? (int) config('pm.api_rate_limit');
            $perDay = $workspace ? $entitlements->get($workspace, Entitlement::ApiPerDay) : null;
            $tooMany = fn (int $limit, string $per) => fn (Request $request, array $headers) => response()->json([
                'message' => "Too many requests: the limit is {$limit} per {$per} per agent. Retry after {$headers['Retry-After']} seconds.",
            ], 429, $headers);

            return array_filter([
                Limit::perMinute($perMinute)->by($by)->response($tooMany($perMinute, 'minute')),
                // E11: one counter per agent per calendar day.
                $perDay === null ? null : Limit::perDay($perDay)->by("pm:api-day:{$by}:".now()->toDateString())->response($tooMany($perDay, 'day')),
            ]);
        });

        RateLimiter::for('invites', function (Request $request) {
            $limit = (int) config('pm.invite_rate_limit');
            $message = "Too many invitations: the limit is {$limit} per hour. Try again later.";

            return Limit::perHour($limit)->by('invites:'.$request->user()?->id)->response(fn (Request $request, array $headers) => $request->expectsJson()
                ? response()->json(['message' => $message], 429, $headers)
                : back()->withErrors(['email' => $message]));
        });

        // API docs are public so agents can discover the API without a token.
        Gate::define('viewApiDocs', fn (?User $user = null) => true);

        // Spec 7.0d: sign-in events go to every workspace the user belongs to. Never the credentials themselves.
        $audit = fn (string $action, $user, array $metadata = []) => app(AuditLogger::class)->forEveryWorkspaceOf($user instanceof User ? $user : null, $action, $metadata);
        Event::listen(Login::class, fn (Login $e) => $audit('auth.login', $e->user));
        Event::listen(Logout::class, fn (Logout $e) => $e->user ? $audit('auth.logout', $e->user) : null);
        Event::listen(PasswordReset::class, fn (PasswordReset $e) => $audit('auth.password_reset', $e->user));
        // An unknown address has no workspace: one unscoped row naming the address tried.
        Event::listen(Failed::class, fn (Failed $e) => $audit('auth.login_failed', $e->user, $e->user ? [] : ['email' => Str::limit((string) ($e->credentials['email'] ?? ''), 255, '')]));
    }
}
