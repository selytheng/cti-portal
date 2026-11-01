<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        if ($user) {
            $user->loadMissing('roles:id,name');
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
            ],
            'can' => [
                'manageUsers' => $user?->can('viewAny', User::class) ?? false,
                'viewAudit' => $user?->can('audit.view') ?? false,
                'configManage' => $user?->can('config.feeds.manage') ?? false,
                'manageApiKeys' => $user?->can('config.apikeys.manage') ?? false,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'warning' => $request->session()->get('warning'),
                'error' => $request->session()->get('error'),
                'testResult' => $request->session()->get('testResult'),
                'generatedPassword' => $request->session()->get('generatedPassword'),
            ],
            'appLogoUrl' => ($path = SystemSetting::get('app_logo_path')) ? Storage::url($path) : null,
        ];
    }
}
