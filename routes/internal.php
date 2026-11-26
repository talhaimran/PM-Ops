<?php

use App\Http\Controllers\Internal\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

// Spec 9 / platform spec 4.1: the internal admin API for Platform management, under /internal/v1 (bootstrap/app.php).
// No session, cookies or CSRF: a service token (InternalApiToken), and Idempotency-Key on every write (Idempotent).
Route::whereNumber('id')->group(function () {
    Route::get('workspaces', [WorkspaceController::class, 'index']);
    Route::get('workspaces/{id}', [WorkspaceController::class, 'show']);
    Route::get('workspaces/{id}/usage', [WorkspaceController::class, 'usage']);
    Route::put('workspaces/{id}/entitlements', [WorkspaceController::class, 'replaceEntitlements']);
    Route::put('workspaces/{id}/entitlements/{key}', [WorkspaceController::class, 'setEntitlement']);
    Route::delete('workspaces/{id}/entitlements/{key}', [WorkspaceController::class, 'removeEntitlement']);
    Route::post('workspaces/{id}/suspend', [WorkspaceController::class, 'suspend']);
    Route::post('workspaces/{id}/unsuspend', [WorkspaceController::class, 'unsuspend']);
    Route::post('workspaces/{id}/archive', [WorkspaceController::class, 'archive']);
    Route::post('workspaces/{id}/restore', [WorkspaceController::class, 'restore']);
    Route::post('workspaces/{id}/support-sessions', [WorkspaceController::class, 'startSupportSession']);
});
