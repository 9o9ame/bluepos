<?php

use App\Http\Controllers\Platform\PlatformAdminController;
use App\Http\Controllers\Platform\PlatformAuditController;
use App\Http\Controllers\Platform\PlatformAuthController;
use App\Http\Controllers\Platform\PlatformDashboardController;
use App\Http\Controllers\Platform\PlatformPlanController;
use App\Http\Controllers\Platform\PlatformTenantController;
use Illuminate\Support\Facades\Route;

Route::prefix('platform')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/login', [PlatformAuthController::class, 'login'])->middleware('throttle:platform-login');
        Route::post('/mfa/verify', [PlatformAuthController::class, 'verifyMfa'])->middleware('throttle:platform-mfa');
        Route::post('/mfa/resend', [PlatformAuthController::class, 'resendMfa'])->middleware('throttle:platform-mfa');

        Route::middleware(['platform', 'throttle:platform-auth'])->group(function () {
            Route::get('/me', [PlatformAuthController::class, 'me']);
            Route::post('/logout', [PlatformAuthController::class, 'logout']);
            Route::post('/change-password', [PlatformAuthController::class, 'changePassword']);
            Route::get('/sessions', [PlatformAuthController::class, 'sessions']);
            Route::delete('/sessions/others', [PlatformAuthController::class, 'destroyOtherSessions']);
            Route::delete('/sessions/{sessionUlid}', [PlatformAuthController::class, 'destroySession']);
            Route::post('/logout-all', [PlatformAuthController::class, 'logoutAll']);
        });
    });

    Route::middleware(['platform', 'throttle:platform-auth'])->group(function () {
        Route::get('/dashboard', [PlatformDashboardController::class, 'show'])
            ->middleware('platform.can:platform.dashboard.view');

        Route::get('/tenants', [PlatformTenantController::class, 'index'])
            ->middleware('platform.can:platform.tenants.view');
        Route::post('/tenants', [PlatformTenantController::class, 'store'])
            ->middleware(['platform.can:platform.tenants.create', 'platform.recent-mfa']);
        Route::get('/tenants/{tenantUlid}', [PlatformTenantController::class, 'show'])
            ->name('platform.tenants.show')
            ->middleware('platform.can:platform.tenants.view');
        Route::patch('/tenants/{tenantUlid}', [PlatformTenantController::class, 'update'])
            ->middleware('platform.can:platform.tenants.edit');
        Route::post('/tenants/{tenantUlid}/activate', [PlatformTenantController::class, 'activate'])
            ->middleware(['platform.can:platform.tenants.activate', 'platform.recent-mfa']);
        Route::post('/tenants/{tenantUlid}/suspend', [PlatformTenantController::class, 'suspend'])
            ->middleware(['platform.can:platform.tenants.suspend', 'platform.recent-mfa']);
        Route::put('/tenants/{tenantUlid}/subscription', [PlatformTenantController::class, 'assignSubscription'])
            ->middleware(['platform.can:platform.subscriptions.assign', 'platform.recent-mfa']);
        Route::put('/tenants/{tenantUlid}/features/{featureKey}', [PlatformTenantController::class, 'upsertFeature'])
            ->middleware(['platform.can:platform.features.manage', 'platform.recent-mfa']);
        Route::delete('/tenants/{tenantUlid}/features/{featureKey}', [PlatformTenantController::class, 'deleteFeature'])
            ->middleware(['platform.can:platform.features.manage', 'platform.recent-mfa']);
        Route::put('/tenants/{tenantUlid}/limits/{limitKey}', [PlatformTenantController::class, 'upsertLimit'])
            ->middleware(['platform.can:platform.limits.manage', 'platform.recent-mfa']);
        Route::delete('/tenants/{tenantUlid}/limits/{limitKey}', [PlatformTenantController::class, 'deleteLimit'])
            ->middleware(['platform.can:platform.limits.manage', 'platform.recent-mfa']);
        Route::post('/tenants/{tenantUlid}/admins/reset', [PlatformTenantController::class, 'resetAdmin'])
            ->middleware(['platform.can:platform.tenants.reset_admin_access', 'platform.recent-mfa']);

        Route::get('/plans', [PlatformPlanController::class, 'index'])
            ->middleware('platform.can:platform.plans.view');
        Route::get('/features', [PlatformPlanController::class, 'catalog'])
            ->middleware('platform.can:platform.features.view');
        Route::post('/plans', [PlatformPlanController::class, 'store'])
            ->middleware('platform.can:platform.plans.create');
        Route::get('/plans/{planUlid}', [PlatformPlanController::class, 'show'])
            ->middleware('platform.can:platform.plans.view');
        Route::patch('/plans/{planUlid}', [PlatformPlanController::class, 'update'])
            ->middleware('platform.can:platform.plans.edit');
        Route::put('/plans/{planUlid}/features', [PlatformPlanController::class, 'syncFeatures'])
            ->middleware(['platform.can:platform.plans.edit', 'platform.recent-mfa']);
        Route::put('/plans/{planUlid}/limits', [PlatformPlanController::class, 'syncLimits'])
            ->middleware(['platform.can:platform.plans.edit', 'platform.recent-mfa']);

        Route::get('/security/audit', [PlatformAuditController::class, 'index'])
            ->middleware('platform.can:platform.audit.view');

        Route::get('/admins', [PlatformAdminController::class, 'index'])
            ->middleware('platform.can:platform.admins.view');
        Route::post('/admins', [PlatformAdminController::class, 'store'])
            ->middleware(['platform.can:platform.admins.manage', 'platform.recent-mfa']);
        Route::post('/admins/{adminUlid}/deactivate', [PlatformAdminController::class, 'deactivate'])
            ->middleware(['platform.can:platform.admins.manage', 'platform.recent-mfa']);
    });
});
