<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\BillingLink;
use App\Services\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** /{workspace}/settings/billing (3-R23): Owners only; the redirect carries a token naming the signed-in user. */
class BillingController extends Controller
{
    public function __invoke(Request $request, CurrentWorkspace $current, BillingLink $billing): RedirectResponse
    {
        // A support session must not buy staff an Owner's billing session.
        abort_if(! BillingLink::enabled() || $request->attributes->has('support_session'), 404);
        $workspace = $current->get();
        Gate::authorize('openBilling', $workspace);

        return redirect()->away($billing->url($workspace, $request->user()));
    }
}
