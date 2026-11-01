<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class PasswordChangeController extends Controller
{
    /**
     * Forced password change for freshly seeded / reset accounts
     * (must_change_password = ***.
     */
    public function edit(Request $request)
    {
        return Inertia::render('PasswordChange', [
            'warning' => $request->session()->get('warning'),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'string', 'min:10', 'confirmed', 'different:current_password'],
        ]);

        $user = $request->user();
        $user->forceFill([
            'password' => $data['password'], // hashed via cast
            'must_change_password' => false,
        ])->save();

        activity()
            ->performedOn($user)
            ->causedBy($user)
            ->event('password_changed')
            ->log('Password changed');

        return redirect()
            ->route('dashboard')
            ->with('success', 'Password updated. Welcome to the CTI Portal.');
    }
}
