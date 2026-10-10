<?php

use App\Http\Controllers\Api\ColumnPreferenceController;
use App\Http\Controllers\Api\CoaChartController;
use App\Http\Controllers\Api\AccountMainHeadController;
use App\Http\Controllers\Api\AccountSubHeadController;
use App\Http\Controllers\Api\AccountTypeController;
use App\Http\Controllers\Api\Accounting\VoucherController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BarcodeGroupController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\BusinessSettingController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\Inventory\OpeningBalanceController;
use App\Http\Controllers\Api\Inventory\StockController;
use App\Http\Controllers\Api\Inventory\StockTakeController;
use App\Http\Controllers\Api\MembershipController;
use App\Http\Controllers\Api\PartyBankAccountController;
use App\Http\Controllers\Api\PartyBulkController;
use App\Http\Controllers\Api\PartyController;
use App\Http\Controllers\Api\PartyLedgerController;
use App\Http\Controllers\Api\PartyOpeningBalanceController;
use App\Http\Controllers\Api\PartyProfileController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\Purchases\PurchaseInvoiceController;
use App\Http\Controllers\Api\Purchases\PurchaseOrderController;
use App\Http\Controllers\Api\Purchases\PurchaseReturnController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SaleOfferController;
use App\Http\Controllers\Api\SaleSchemeController;
use App\Http\Controllers\Api\Sales\ExpenseController;
use App\Http\Controllers\Api\Sales\SaleController;
use App\Http\Controllers\Api\Sales\SaleHoldController;
use App\Http\Controllers\Api\Sales\SaleReturnController;
use App\Http\Controllers\Api\Sales\SaleQuotationController;
use App\Http\Controllers\Api\SecuritySessionController;
use App\Http\Controllers\Api\SubcategoryController;
use App\Http\Controllers\Api\SupplierController;
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
        Route::patch('/appearance', [AuthController::class, 'updateAppearance']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
    });
});

Route::post('/devices/enrollment', [DeviceController::class, 'enroll'])->middleware('throttle:devices');

Route::middleware(['auth:sanctum', 'tenant', 'throttle:auth'])->group(function () {
    Route::get('/branches', [BranchController::class, 'index']);
    Route::post('/branches', [BranchController::class, 'store']);
    Route::post('/branches/{branchUlid}/switch', [BranchController::class, 'switch']);
    Route::get('/warehouses', [WarehouseController::class, 'index']);
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
    Route::get('/vouchers/accounts', [VoucherController::class, 'accounts']);
    Route::get('/vouchers/summary', [VoucherController::class, 'summary']);
    Route::get('/vouchers', [VoucherController::class, 'index']);
    Route::post('/vouchers', [VoucherController::class, 'store']);
    Route::get('/vouchers/{voucherUlid}', [VoucherController::class, 'show']);
    Route::put('/vouchers/{voucherUlid}', [VoucherController::class, 'update']);
    Route::match(['DELETE'], '/vouchers/{voucherUlid}', [VoucherController::class, 'destroy']);
    Route::post('/vouchers/{voucherUlid}/post', [VoucherController::class, 'post']);

    Route::get('/column-preferences/{screenKey}', [ColumnPreferenceController::class, 'show']);
    Route::put('/column-preferences/{screenKey}', [ColumnPreferenceController::class, 'upsert']);

    Route::get('/party-profiles', [PartyProfileController::class, 'index']);
    Route::post('/party-profiles', [PartyProfileController::class, 'store']);
    Route::get('/party-profiles/{profileUlid}', [PartyProfileController::class, 'show']);
    Route::patch('/party-profiles/{profileUlid}', [PartyProfileController::class, 'update']);

    Route::get('/parties', [PartyController::class, 'index']);
    Route::post('/parties', [PartyController::class, 'store']);
    Route::patch('/parties/bulk', [PartyBulkController::class, 'update']);
    Route::get('/parties/excel-template', [PartyBulkController::class, 'template']);
    Route::post('/parties/excel/preview', [PartyBulkController::class, 'preview']);
    Route::post('/parties/excel/import', [PartyBulkController::class, 'import']);
    Route::get('/parties/{partyUlid}', [PartyController::class, 'show']);
    Route::patch('/parties/{partyUlid}', [PartyController::class, 'update']);
    Route::delete('/parties/{partyUlid}', [PartyController::class, 'destroy']);
    Route::get('/parties/{partyUlid}/image', [PartyController::class, 'image']);
    Route::post('/parties/{partyUlid}/image', [PartyController::class, 'uploadImage']);
    Route::delete('/parties/{partyUlid}/image', [PartyController::class, 'deleteImage']);
    Route::get('/parties/{partyUlid}/bank-accounts', [PartyBankAccountController::class, 'index']);
    Route::post('/parties/{partyUlid}/bank-accounts', [PartyBankAccountController::class, 'store']);
    Route::patch('/parties/{partyUlid}/bank-accounts/{bankUlid}', [PartyBankAccountController::class, 'update']);
    Route::delete('/parties/{partyUlid}/bank-accounts/{bankUlid}', [PartyBankAccountController::class, 'destroy']);
    Route::get('/parties/{partyUlid}/ledger', [PartyLedgerController::class, 'show']);
    Route::post('/parties/{partyUlid}/ensure-leaf-account', [PartyLedgerController::class, 'ensureLeafAccount']);
    Route::get('/parties/{partyUlid}/opening-balances', [PartyOpeningBalanceController::class, 'index']);
    Route::post('/parties/{partyUlid}/opening-balances', [PartyOpeningBalanceController::class, 'store']);
    Route::patch('/parties/{partyUlid}/opening-balances/{openingUlid}', [PartyOpeningBalanceController::class, 'update']);
    Route::delete('/parties/{partyUlid}/opening-balances/{openingUlid}', [PartyOpeningBalanceController::class, 'destroy']);
    Route::post('/parties/{partyUlid}/opening-balances/{openingUlid}/post', [PartyOpeningBalanceController::class, 'post']);

    Route::get('/coa/chart', [CoaChartController::class, 'show']);
    Route::get('/coa/leaf-accounts', [CoaChartController::class, 'leafAccounts']);
    Route::get('/coa/tree', [AccountTypeController::class, 'tree']);
    Route::get('/coa/main-heads', [AccountMainHeadController::class, 'index']);
    Route::post('/coa/main-heads', [AccountMainHeadController::class, 'store']);
    Route::get('/coa/main-heads/{mainHeadUlid}', [AccountMainHeadController::class, 'show']);
    Route::patch('/coa/main-heads/{mainHeadUlid}', [AccountMainHeadController::class, 'update']);
    Route::delete('/coa/main-heads/{mainHeadUlid}', [AccountMainHeadController::class, 'destroy']);
    Route::get('/coa/sub-heads', [AccountSubHeadController::class, 'index']);
    Route::post('/coa/sub-heads', [AccountSubHeadController::class, 'store']);
    Route::get('/coa/sub-heads/{subHeadUlid}', [AccountSubHeadController::class, 'show']);
    Route::patch('/coa/sub-heads/{subHeadUlid}', [AccountSubHeadController::class, 'update']);
    Route::delete('/coa/sub-heads/{subHeadUlid}', [AccountSubHeadController::class, 'destroy']);
    Route::get('/coa/account-types', [AccountTypeController::class, 'index']);
    Route::post('/coa/account-types', [AccountTypeController::class, 'store']);
    Route::get('/coa/account-types/{accountTypeUlid}', [AccountTypeController::class, 'show']);
    Route::patch('/coa/account-types/{accountTypeUlid}', [AccountTypeController::class, 'update']);
    Route::delete('/coa/account-types/{accountTypeUlid}', [AccountTypeController::class, 'destroy']);

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

        Route::get('/suppliers', [SupplierController::class, 'index']);
        Route::post('/suppliers', [SupplierController::class, 'store']);
        Route::get('/suppliers/{supplierUlid}', [SupplierController::class, 'show']);
        Route::patch('/suppliers/{supplierUlid}', [SupplierController::class, 'update']);
        Route::delete('/suppliers/{supplierUlid}', [SupplierController::class, 'destroy']);

        Route::get('/products', [ProductController::class, 'index']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::patch('/products/bulk', [ProductController::class, 'bulkUpdate']);
        Route::get('/products/tabular-export', [ProductController::class, 'tabularExport']);
        Route::get('/products/{productUlid}', [ProductController::class, 'show']);
        Route::patch('/products/{productUlid}', [ProductController::class, 'update']);
        Route::delete('/products/{productUlid}', [ProductController::class, 'destroy']);
        Route::get('/products/{productUlid}/image', [ProductController::class, 'image']);
        Route::post('/products/{productUlid}/image', [ProductController::class, 'uploadImage']);
        Route::delete('/products/{productUlid}/image', [ProductController::class, 'deleteImage']);
        Route::put('/products/{productUlid}/barcodes', [ProductController::class, 'syncBarcodes']);
        Route::put('/products/{productUlid}/prices', [ProductController::class, 'syncPrices']);
        Route::get('/products/{productUlid}/stock', [StockController::class, 'forProduct']);

        Route::get('/sale-schemes', [SaleSchemeController::class, 'index']);
        Route::post('/sale-schemes', [SaleSchemeController::class, 'store']);
        Route::get('/sale-schemes/{schemeUlid}', [SaleSchemeController::class, 'show']);
        Route::patch('/sale-schemes/{schemeUlid}', [SaleSchemeController::class, 'update']);
        Route::delete('/sale-schemes/{schemeUlid}', [SaleSchemeController::class, 'destroy']);
        Route::post('/sale-offers/evaluate', [SaleOfferController::class, 'evaluate']);
    });

    Route::middleware('entitled:sales')->group(function () {
        Route::get('/sales', [SaleController::class, 'index']);
        Route::get('/sales/product-wise', [SaleController::class, 'productWise']);
        Route::get('/sales/quotations', [SaleQuotationController::class, 'index']);
        Route::post('/sales/quotations', [SaleQuotationController::class, 'store']);
        Route::get('/sales/quotations/{quotationUlid}', [SaleQuotationController::class, 'show']);
        Route::get('/sales/expenses', [ExpenseController::class, 'index']);
        Route::get('/sales/expenses/accounts', [ExpenseController::class, 'accounts']);
        Route::post('/sales/expenses', [ExpenseController::class, 'store']);
        Route::get('/sales/salesmen', [SaleController::class, 'salesmen']);
        Route::get('/sales/holds', [SaleHoldController::class, 'index']);
        Route::post('/sales/holds', [SaleHoldController::class, 'store']);
        Route::get('/sales/holds/{holdUlid}', [SaleHoldController::class, 'show']);
        Route::delete('/sales/holds/{holdUlid}', [SaleHoldController::class, 'destroy']);
        Route::post('/sales', [SaleController::class, 'store']);
        Route::get('/sales/{saleUlid}', [SaleController::class, 'show']);
        Route::get('/sales/{saleUlid}/payments', [SaleController::class, 'payments']);
        Route::post('/sales/{saleUlid}/payments', [SaleController::class, 'storePayment']);

        Route::get('/sales/{saleUlid}/returnable-lines', [SaleReturnController::class, 'returnableLines']);
        Route::get('/sales-returns', [SaleReturnController::class, 'index']);
        Route::post('/sales-returns', [SaleReturnController::class, 'store']);
        Route::get('/sales-returns/product-wise', [SaleReturnController::class, 'productWise']);
        Route::get('/sales-returns/{returnUlid}', [SaleReturnController::class, 'show']);
        Route::patch('/sales-returns/{returnUlid}', [SaleReturnController::class, 'update']);
        Route::post('/sales-returns/{returnUlid}/lines', [SaleReturnController::class, 'storeLine']);
        Route::patch('/sales-returns/{returnUlid}/lines/{lineUlid}', [SaleReturnController::class, 'updateLine']);
        Route::delete('/sales-returns/{returnUlid}/lines/{lineUlid}', [SaleReturnController::class, 'destroyLine']);
        Route::post('/sales-returns/{returnUlid}/post', [SaleReturnController::class, 'post']);
        Route::post('/sales-returns/{returnUlid}/refunds', [SaleReturnController::class, 'storeRefund']);
    });

    Route::middleware('entitled:inventory')->group(function () {
        Route::get('/inventory/stock', [StockController::class, 'index']);

        Route::get('/inventory/opening-balances', [OpeningBalanceController::class, 'index']);
        Route::post('/inventory/opening-balances', [OpeningBalanceController::class, 'store']);
        Route::get('/inventory/opening-balances/{openingBalanceUlid}', [OpeningBalanceController::class, 'show']);
        Route::patch('/inventory/opening-balances/{openingBalanceUlid}', [OpeningBalanceController::class, 'update']);
        Route::delete('/inventory/opening-balances/{openingBalanceUlid}', [OpeningBalanceController::class, 'destroy']);
        Route::post('/inventory/opening-balances/{openingBalanceUlid}/lines', [OpeningBalanceController::class, 'storeLine']);
        Route::patch('/inventory/opening-balances/{openingBalanceUlid}/lines/{lineUlid}', [OpeningBalanceController::class, 'updateLine']);
        Route::delete('/inventory/opening-balances/{openingBalanceUlid}/lines/{lineUlid}', [OpeningBalanceController::class, 'destroyLine']);
        Route::post('/inventory/opening-balances/{openingBalanceUlid}/post', [OpeningBalanceController::class, 'post']);

        Route::get('/inventory/stock-takes', [StockTakeController::class, 'index']);
        Route::post('/inventory/stock-takes', [StockTakeController::class, 'store']);
        Route::get('/inventory/stock-takes/{stockTakeUlid}', [StockTakeController::class, 'show']);
        Route::patch('/inventory/stock-takes/{stockTakeUlid}', [StockTakeController::class, 'update']);
        Route::delete('/inventory/stock-takes/{stockTakeUlid}', [StockTakeController::class, 'destroy']);
        Route::post('/inventory/stock-takes/{stockTakeUlid}/lines', [StockTakeController::class, 'storeLine']);
        Route::patch('/inventory/stock-takes/{stockTakeUlid}/lines/{lineUlid}', [StockTakeController::class, 'updateLine']);
        Route::delete('/inventory/stock-takes/{stockTakeUlid}/lines/{lineUlid}', [StockTakeController::class, 'destroyLine']);
        Route::post('/inventory/stock-takes/{stockTakeUlid}/post', [StockTakeController::class, 'post']);
    });

    Route::middleware('entitled:purchases')->group(function () {
        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
        Route::get('/purchase-orders/status', [PurchaseOrderController::class, 'status']);
        Route::post('/purchase-orders/generate', [PurchaseOrderController::class, 'generate']);
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
        Route::get('/purchase-orders/{purchaseOrderUlid}', [PurchaseOrderController::class, 'show']);

        Route::get('/purchases', [PurchaseInvoiceController::class, 'index']);
        Route::post('/purchases', [PurchaseInvoiceController::class, 'store']);
        Route::get('/purchases/{purchaseUlid}', [PurchaseInvoiceController::class, 'show']);
        Route::patch('/purchases/{purchaseUlid}', [PurchaseInvoiceController::class, 'update']);
        Route::post('/purchases/{purchaseUlid}/lines', [PurchaseInvoiceController::class, 'storeLine']);
        Route::patch('/purchases/{purchaseUlid}/lines/{lineUlid}', [PurchaseInvoiceController::class, 'updateLine']);
        Route::delete('/purchases/{purchaseUlid}/lines/{lineUlid}', [PurchaseInvoiceController::class, 'destroyLine']);
        Route::post('/purchases/{purchaseUlid}/post', [PurchaseInvoiceController::class, 'post']);
        Route::get('/purchases/{purchaseUlid}/payments', [PurchaseInvoiceController::class, 'payments']);
        Route::post('/purchases/{purchaseUlid}/payments', [PurchaseInvoiceController::class, 'storePayment']);
        Route::get('/purchases/{purchaseUlid}/returnable-lines', [PurchaseReturnController::class, 'returnableLines']);
    });

    Route::middleware('entitled:purchase_returns')->group(function () {
        Route::get('/purchase-returns', [PurchaseReturnController::class, 'index']);
        Route::post('/purchase-returns', [PurchaseReturnController::class, 'store']);
        Route::get('/purchase-returns/{returnUlid}', [PurchaseReturnController::class, 'show']);
        Route::patch('/purchase-returns/{returnUlid}', [PurchaseReturnController::class, 'update']);
        Route::post('/purchase-returns/{returnUlid}/lines', [PurchaseReturnController::class, 'storeLine']);
        Route::patch('/purchase-returns/{returnUlid}/lines/{lineUlid}', [PurchaseReturnController::class, 'updateLine']);
        Route::delete('/purchase-returns/{returnUlid}/lines/{lineUlid}', [PurchaseReturnController::class, 'destroyLine']);
        Route::post('/purchase-returns/{returnUlid}/post', [PurchaseReturnController::class, 'post']);
    });
});

require __DIR__.'/platform.php';
