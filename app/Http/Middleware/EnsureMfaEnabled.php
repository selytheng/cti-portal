<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Privileged roles (superadmin, admin) must have TOTP multi-factor
 * authentication enrolled before they can use privileged pages.
 */
class EnsureMfaEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->hasAnyRole(['superadmin', 'admin']) && ! $user->mfa_enabled) {
            $allowed = [
                'mfa.setup', 'mfa.verify', 'mfa.disable', 'logout',
                'password.change', 'password.change.store',
            ];

            if (! in_array(Route::currentRouteName(), $allowed, true)) {
                return redirect()
                    ->route('mfa.setup')
                    ->with('warning', 'Multi-factor authentication must be enabled for your role.');
            }
        }

        return $next($request);
    }
}
