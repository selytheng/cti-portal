<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces users flagged must_change_password (seeded admins, password-reset
 * targets) to set a new password before they can reach any other page.
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            $allowed = ['password.change', 'password.change.store', 'logout', 'profile.destroy'];

            if (! in_array(Route::currentRouteName(), $allowed, true)) {
                return redirect()
                    ->route('password.change')
                    ->with('warning', 'You must change your password before continuing.');
            }
        }

        return $next($request);
    }
}
