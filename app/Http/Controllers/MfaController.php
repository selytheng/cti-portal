<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use PragmaRX\Google2FA\Google2FA;

class MfaController extends Controller
{
    public function __construct(private Google2FA $google2fa)
    {
    }

    /**
     * TOTP enrollment: generates (once) and shows the secret + otpauth URI
     * so the analyst can add it to any authenticator app.
     */
    public function setup(Request $request)
    {
        $user = $request->user();

        if (! $user->mfa_secret) {
            $user->forceFill([
                'mfa_secret' => $this->google2fa->generateSecretKey(32),
            ])->save();
        }

        return Inertia::render('Profile/MfaSetup', [
            'secret' => $user->mfa_secret,
            'otpauthUrl' => $this->google2fa->getQRCodeUrl(
                config('app.name'),
                $user->email,
                $user->mfa_secret
            ),
            'enabled' => $user->mfa_enabled,
        ]);
    }

    public function verify(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $user = $request->user();

        $valid = $user->mfa_secret
            && $this->google2fa->verifyKey($user->mfa_secret, $data['code'], 2);

        if (! $valid) {
            return back()->withErrors(['code' => 'Invalid or expired code. Make sure your device clock is correct and try the next code.']);
        }

        $user->forceFill([
            'mfa_enabled' => true,
            'mfa_verified_at' => now(),
        ])->save();

        activity()
            ->performedOn($user)
            ->causedBy($user)
            ->event('mfa_enabled')
            ->log('Multi-factor authentication enabled');

        return redirect()->intended(route('dashboard'))->with('success', 'Multi-factor authentication is now active.');
    }

    public function disable(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
        ]);

        $user = $request->user();
        $user->forceFill([
            'mfa_enabled' => false,
            'mfa_secret' => null,
            'mfa_verified_at' => null,
        ])->save();

        activity()
            ->performedOn($user)
            ->causedBy($user)
            ->event('mfa_disabled')
            ->log('Multi-factor authentication disabled');

        return back()->with('success', 'MFA disabled. Note: privileged roles will be required to re-enroll.');
    }
}
