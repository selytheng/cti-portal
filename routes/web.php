<?php

use App\Http\Controllers\AiIntegrationController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\CtiRepositoryController;
use App\Http\Controllers\ConfigurationController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\IocVerifierController;
use App\Http\Controllers\KeywordController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\PasswordChangeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QueryHubController;
use App\Http\Controllers\ReportTemplateController;
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

    // ===== Query Hub (Phase 3, spec Section 4) =====
    Route::get('/query-hub', [QueryHubController::class, 'index'])->name('queryhub.index');
    Route::post('/query-hub', [QueryHubController::class, 'store'])->name('queryhub.store');
    Route::post('/query-hub/{run}/cancel', [QueryHubController::class, 'cancel'])->name('queryhub.cancel');
    // ===== CTI Repository (Phase 4, spec Sections 6–8) =====
    Route::get('/cti-repository', [CtiRepositoryController::class, 'index'])->name('cti.index');
    Route::get('/cti-repository/{record}', [CtiRepositoryController::class, 'show'])->name('cti.show');
    Route::get('/cti-repository/{record}/edit', [CtiRepositoryController::class, 'edit'])->name('cti.edit')->middleware('permission:cti.edit');
    Route::patch('/cti-repository/{record}', [CtiRepositoryController::class, 'update'])->name('cti.update')->middleware('permission:cti.edit');
    Route::delete('/cti-repository/{record}', [CtiRepositoryController::class, 'destroy'])->name('cti.destroy')->middleware('permission:cti.delete');

    Route::middleware('permission:cti.bulk')->group(function () {
        Route::post('/cti-repository/bulk-status', [CtiRepositoryController::class, 'bulkStatus'])->name('cti.bulk-status');
        Route::post('/cti-repository/bulk-destroy', [CtiRepositoryController::class, 'bulkDestroy'])->name('cti.bulk-destroy');
        // GET so the browser handles the file download natively (ids via query).
        Route::get('/cti-repository/export/csv', [CtiRepositoryController::class, 'exportCsv'])->name('cti.export-csv');
        Route::get('/cti-repository/export/stix', [CtiRepositoryController::class, 'exportStix'])->name('cti.export-stix');
    });

    Route::post('/cti-repository/report/preview', [CtiRepositoryController::class, 'preview'])->name('cti.report-preview')->middleware('permission:report.generate');
    Route::post('/cti-repository/report/pdf', [CtiRepositoryController::class, 'generatePdf'])->name('cti.report-pdf')->middleware('permission:report.generate');
    Route::get('/cti-reports/{report}/download', [CtiRepositoryController::class, 'downloadReport'])->name('cti.report-download');
    Route::post('/cti-repository/{record}/quick-report', [CtiRepositoryController::class, 'quickReport'])->name('cti.quick-report')->middleware('permission:report.generate');
    Route::post('/cti-repository/{record}/reverify-iocs', [CtiRepositoryController::class, 'reverifyAll'])->name('cti.reverify');
    Route::post('/cti-repository/ioc/{ioc}/reverify', [CtiRepositoryController::class, 'reverifyOne'])->name('cti.ioc-reverify');
    Route::post('/cti-repository/{record}/notes', [CtiRepositoryController::class, 'storeNote'])->name('cti.notes.store')->middleware('permission:cti.comment');
    // ===== Configuration Center (Phase 2, spec Section 5) =====
    Route::get('/configuration', [ConfigurationController::class, 'index'])
        ->name('config.index')
        ->middleware('permission:config.feeds.manage');

    Route::middleware('permission:config.feeds.manage')->group(function () {
        Route::post('/configuration/feeds', [FeedController::class, 'store'])->name('config.feeds.store');
        Route::patch('/configuration/feeds/{feed}', [FeedController::class, 'update'])->name('config.feeds.update');
        Route::delete('/configuration/feeds/{feed}', [FeedController::class, 'destroy'])->name('config.feeds.destroy');
        Route::post('/configuration/feeds/{feed}/test', [FeedController::class, 'test'])->name('config.feeds.test');

        Route::post('/configuration/keywords', [KeywordController::class, 'store'])->name('config.keywords.store');
        Route::patch('/configuration/keywords/{keyword}', [KeywordController::class, 'update'])->name('config.keywords.update');
        Route::delete('/configuration/keywords/{keyword}', [KeywordController::class, 'destroy'])->name('config.keywords.destroy');
        Route::patch('/configuration/keyword-settings', [KeywordController::class, 'updateSettings'])->name('config.keywords.settings');

        Route::post('/configuration/templates', [ReportTemplateController::class, 'store'])->name('config.templates.store');
        Route::patch('/configuration/templates/{template}', [ReportTemplateController::class, 'update'])->name('config.templates.update');
        Route::delete('/configuration/templates/{template}', [ReportTemplateController::class, 'destroy'])->name('config.templates.destroy');
        Route::post('/configuration/templates/{template}/default', [ReportTemplateController::class, 'setDefault'])->name('config.templates.default');
        Route::post('/configuration/templates/{template}/preview', [ReportTemplateController::class, 'preview'])->name('config.templates.preview');
        Route::post('/configuration/templates/logo', [ReportTemplateController::class, 'uploadLogo'])->name('config.templates.logo');
    });

    // API key management is superadmin-only (spec Section 2 RBAC).
    Route::middleware('permission:config.apikeys.manage')->group(function () {
        Route::post('/configuration/ai', [AiIntegrationController::class, 'store'])->name('config.ai.store');
        Route::patch('/configuration/ai/{aiIntegration}', [AiIntegrationController::class, 'update'])->name('config.ai.update');
        Route::delete('/configuration/ai/{aiIntegration}', [AiIntegrationController::class, 'destroy'])->name('config.ai.destroy');
        Route::post('/configuration/ai/{aiIntegration}/test', [AiIntegrationController::class, 'test'])->name('config.ai.test');

        Route::patch('/configuration/ioc/{iocVerifier}', [IocVerifierController::class, 'update'])->name('config.ioc.update');
        Route::post('/configuration/ioc/{iocVerifier}/test', [IocVerifierController::class, 'test'])->name('config.ioc.test');
    });
});

require __DIR__.'/auth.php';
