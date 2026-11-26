<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\SupportSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** 3-R19: the one-time URL from the admin API signs this browser in as the target, read-only, without 2FA. */
class SupportSessionController extends Controller
{
    public function consume(Request $request, string $token, SupportSessionService $support): RedirectResponse
    {
        $session = $support->consume($token);
        // A support session this browser already holds ends here, with its ended row, before the session is replaced.
        if ($held = $request->attributes->get('support_session')) {
            $support->end($held);
        }

        // A fresh session holding only the target's sign-in, without the Login event: the target didn't sign in.
        $request->session()->invalidate();
        $request->session()->put(Auth::guard('web')->getName(), $session->target_user_id);
        $request->session()->put(SupportSessionService::SESSION_KEY, $session->id);

        return redirect("/{$session->workspace->slug}/dashboard");
    }
}
