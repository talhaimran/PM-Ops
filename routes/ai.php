<?php

use App\Http\Controllers\Web\OAuthController;
use App\Http\Middleware\ResolveTokenWorkspace;
use App\Mcp\OAuth\RegisterClientController;
use App\Mcp\Servers\LocalPmServer;
use App\Mcp\Servers\PmServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Controllers\AccessTokenController;

// HTTP transport: an agent's Sanctum token (same scopes and rate limit as the REST API) or an MCP client's OAuth token.
Mcp::web('/mcp', PmServer::class)->middleware(['auth:sanctum,oauth', ResolveTokenWorkspace::class, 'throttle:api'])->name('mcp');

// stdio transport: `php artisan mcp:start pm`, authenticated by the PM_MCP_TOKEN environment variable.
Mcp::local('pm', LocalPmServer::class);

// A-D3: OAuth for MCP clients. laravel/mcp's discovery and dynamic client registration, and only the Passport
// endpoints the authorization-code flow needs (Passport's own routes are off, see AppServiceProvider).
Route::middleware('throttle:60,1')->group(function () {
    Mcp::oauthRoutes(registration: false);
    Route::post('/oauth/register', RegisterClientController::class)->name('mcp.oauth.register');
    Route::post('/oauth/token', [AccessTokenController::class, 'issueToken'])->name('passport.token');
});
Route::prefix('oauth')->name('passport.authorizations.')->middleware(['web', 'throttle:30,1'])->group(function () {
    Route::get('/authorize', [OAuthController::class, 'authorize'])->name('authorize');
    Route::post('/authorize', [OAuthController::class, 'approve'])->middleware('auth')->name('approve');
    Route::delete('/authorize', [OAuthController::class, 'deny'])->middleware('auth')->name('deny');
});
