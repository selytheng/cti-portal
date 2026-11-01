<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\PasswordChangeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified', 'force-password-change', 'require-mfa'])->name('dashboard');

// Forced password change — reachable while must_change_password is ***.
Route::middleware(['auth', 'force-password-change'])->group(function () {
    Route::get('/password/change', [PasswordChangeController::class, 'edit'])->name('password.change');
    Route::patch('/password/change', [PasswordChangeController::class, 'update'])->name('password.change.store');
});

// MFA enrollment — reachable while a privileged role has not enabled MFA.
Route::middleware(['auth', 'force-password-change'])->group(function () {
    Route::get('/mfa/setup', [MfaController::class, 'setup'])->name('mfa.setup');
    Route::post('/mfa/verify', [MfaController::class, 'verify'])->name('mfa.verify');
    Route::post('/mfa/disable', [MfaController::class, 'disable'])->name('mfa.disable');
});

Route::middleware(['auth', 'verified', 'force-password-change', 'require-mfa'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // User Management — superadmin: all users; admin: monitoring accounts only (UserPolicy).
    Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserManagementController::class, 'create'])->name('users.create');
    Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [UserManagementController::class, 'edit'])->name('users.edit');
    Route::patch('/users/{user}', [UserManagementController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])->name('users.destroy');

    // Immutable audit trail (spec Section 2).
    Route::get('/audit-logs', [AuditLogController::class, 'index'])
        ->name('audit.index')
        ->middleware('permission:audit.view');

    // Sidebar structure per spec Section 1.2 — modules land in Phases 2–4.
    Route::get('/query-hub', fn () => Inertia::render('Placeholder', ['title' => 'Query Hub', 'phase' => 'Phase 3']))
        ->name('queryhub.index');
    Route::get('/cti-repository', fn () => Inertia::render('Placeholder', ['title' => 'CTI Repository', 'phase' => 'Phase 4']))
        ->name('cti.index');
    Route::get('/configuration', fn () => Inertia::render('Placeholder', ['title' => 'Configuration Center', 'phase' => 'Phase 2']))
        ->name('config.index')
        ->middleware('permission:config.feeds.manage');
});

require __DIR__.'/auth.php';
