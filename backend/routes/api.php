<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BarcodeGroupController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\BusinessSettingController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SecuritySessionController;
use App\Http\Controllers\Api\SubcategoryController;
use App\Http\Controllers\Api\TenantEntitlementController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'ok' => true,
        'service' => 'bluepos',
    ]);
});

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');
    Route::post('/mfa/verify', [AuthController::class, 'verifyMfa'])->middleware('throttle:mfa');
    Route::post('/mfa/resend', [AuthController::class, 'resendMfa'])->middleware('throttle:mfa');

    Route::middleware(['auth:sanctum', 'throttle:auth'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
    });

    Route::middleware(['auth:sanctum', 'tenant', 'throttle:auth'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });
});

Route::post('/devices/enrollment', [DeviceController::class, 'enroll'])->middleware('throttle:devices');

Route::middleware(['auth:sanctum', 'tenant', 'throttle:auth'])->group(function () {
    Route::get('/branches', [BranchController::class, 'index']);
    Route::post('/branches', [BranchController::class, 'store']);
    Route::post('/branches/{branchUlid}/switch', [BranchController::class, 'switch']);
    Route::post('/warehouses', [WarehouseController::class, 'store']);

    Route::get('/permissions', [PermissionController::class, 'index']);

    Route::get('/roles', [RoleController::class, 'index']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::get('/roles/{roleUlid}', [RoleController::class, 'show']);
    Route::patch('/roles/{roleUlid}', [RoleController::class, 'update']);
    Route::delete('/roles/{roleUlid}', [RoleController::class, 'destroy']);
    Route::put('/roles/{roleUlid}/permissions', [RoleController::class, 'syncPermissions']);

    Route::get('/memberships', [MembershipController::class, 'index']);
    Route::post('/memberships', [MembershipController::class, 'store']);
    Route::get('/memberships/{membershipUlid}', [MembershipController::class, 'show']);
    Route::post('/memberships/{membershipUlid}/deactivate', [MembershipController::class, 'deactivate']);
    Route::post('/memberships/{membershipUlid}/activate', [MembershipController::class, 'activate']);
    Route::post('/memberships/{membershipUlid}/reset-password', [MembershipController::class, 'resetPassword']);
    Route::post('/memberships/{membershipUlid}/force-logout', [MembershipController::class, 'forceLogout']);
    Route::put('/memberships/{membershipUlid}/roles', [MembershipController::class, 'syncRoles']);
    Route::put('/memberships/{membershipUlid}/branches', [MembershipController::class, 'syncBranches']);

    Route::get('/devices', [DeviceController::class, 'index']);
    Route::post('/devices/{deviceUlid}/approve', [DeviceController::class, 'approve']);
    Route::post('/devices/{deviceUlid}/revoke', [DeviceController::class, 'revoke']);
    Route::patch('/devices/{deviceUlid}', [DeviceController::class, 'assign']);

    Route::get('/security/sessions', [SecuritySessionController::class, 'index']);
    Route::delete('/security/sessions/others', [SecuritySessionController::class, 'destroyOthers']);
    Route::delete('/security/sessions/{sessionUlid}', [SecuritySessionController::class, 'destroy']);

    Route::get('/settings/business', [BusinessSettingController::class, 'show']);
    Route::patch('/settings/business', [BusinessSettingController::class, 'update']);
    Route::get('/settings/entitlements', [TenantEntitlementController::class, 'show']);

    Route::middleware('entitled:catalog')->group(function () {
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::get('/categories/{categoryUlid}', [CategoryController::class, 'show']);
        Route::patch('/categories/{categoryUlid}', [CategoryController::class, 'update']);
        Route::delete('/categories/{categoryUlid}', [CategoryController::class, 'destroy']);

        Route::get('/subcategories', [SubcategoryController::class, 'index']);
        Route::post('/subcategories', [SubcategoryController::class, 'store']);
        Route::get('/subcategories/{subcategoryUlid}', [SubcategoryController::class, 'show']);
        Route::patch('/subcategories/{subcategoryUlid}', [SubcategoryController::class, 'update']);
        Route::delete('/subcategories/{subcategoryUlid}', [SubcategoryController::class, 'destroy']);

        Route::get('/brands', [BrandController::class, 'index']);
        Route::post('/brands', [BrandController::class, 'store']);
        Route::get('/brands/{brandUlid}', [BrandController::class, 'show']);
        Route::patch('/brands/{brandUlid}', [BrandController::class, 'update']);
        Route::delete('/brands/{brandUlid}', [BrandController::class, 'destroy']);

        Route::get('/units', [UnitController::class, 'index']);
        Route::post('/units', [UnitController::class, 'store']);
        Route::get('/units/{unitUlid}', [UnitController::class, 'show']);
        Route::patch('/units/{unitUlid}', [UnitController::class, 'update']);
        Route::delete('/units/{unitUlid}', [UnitController::class, 'destroy']);

        Route::get('/barcode-groups', [BarcodeGroupController::class, 'index']);
        Route::post('/barcode-groups', [BarcodeGroupController::class, 'store']);
        Route::get('/barcode-groups/{barcodeGroupUlid}', [BarcodeGroupController::class, 'show']);
        Route::patch('/barcode-groups/{barcodeGroupUlid}', [BarcodeGroupController::class, 'update']);
        Route::delete('/barcode-groups/{barcodeGroupUlid}', [BarcodeGroupController::class, 'destroy']);

        Route::get('/products', [ProductController::class, 'index']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::get('/products/{productUlid}', [ProductController::class, 'show']);
        Route::patch('/products/{productUlid}', [ProductController::class, 'update']);
        Route::delete('/products/{productUlid}', [ProductController::class, 'destroy']);
        Route::put('/products/{productUlid}/barcodes', [ProductController::class, 'syncBarcodes']);
        Route::put('/products/{productUlid}/prices', [ProductController::class, 'syncPrices']);
    });
});

require __DIR__.'/platform.php';
