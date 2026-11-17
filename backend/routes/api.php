<?php

use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SystemAdminController;
use App\Http\Controllers\Api\FinanceController;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\BepBarController;
use App\Http\Controllers\Api\CashierController;
use App\Http\Controllers\Api\ComplaintController;
use App\Http\Controllers\Api\DiningController;
use App\Http\Controllers\Api\HumanResourcesController;
use App\Http\Controllers\Api\MenuCategoryController;
use App\Http\Controllers\Api\MenuItemController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();

        return response()->json([
            'status' => 'ok',
            'database' => 'connected',
            'application' => 'Quan Nhau Tu Do',
        ]);
    } catch (Throwable $exception) {
        return response()->json([
            'status' => 'error',
            'database' => 'disconnected',
        ], 503);
    }
});


// Login session của Sanctum. /login khai báo trong routes/web.php.
Route::get('/auth/branches', [AuthController::class, 'branches']);

Route::middleware(['auth:sanctum', 'branch.scope'])->group(function () {
// ==========================================
// ĐỢT 1: CƠ SỞ VÀ THỰC ĐƠN
// ==========================================

Route::get('/branches', function (Request $request) {
    $query = DB::table('CO_SO')->where('TrangThai', 'Dang hoat dong');
    if ($request->user()->TenVaiTro !== 'Quan tri he thong') {
        $query->where('MaCS', $request->session()->get('qntd_branch_id'));
    }
    return response()->json($query->orderBy('MaCS')->get(['MaCS', 'TenCS', 'DiaChi', 'SDT']));
});

Route::get('/categories', [MenuCategoryController::class, 'index'])->middleware('uc:UC2.1,UC2.2,UC2.4');
Route::apiResource('/categories', MenuCategoryController::class)
    ->only(['store', 'update', 'destroy'])->middleware('uc:UC2.1');

Route::put(
    '/menu-items/{id}/branch-status',
    [MenuItemController::class, 'updateBranchStatus']
)->middleware('uc:UC2.1');

Route::get('/menu-items', [MenuItemController::class, 'index'])->middleware('uc:UC2.1,UC2.2,UC2.3');
Route::apiResource('/menu-items', MenuItemController::class)
    ->only(['store', 'update', 'destroy'])->middleware('uc:UC2.1');


// ==========================================
// ĐỢT 2: KHU VỰC VÀ BÀN
// ==========================================

Route::get('/areas', [DiningController::class, 'areas'])->middleware('uc:UC1.1,UC1.2,UC1.3,UC2.2');
Route::post('/areas', [DiningController::class, 'storeArea'])->middleware('uc:UC1.2');
Route::put('/areas/{id}', [DiningController::class, 'updateArea'])->middleware('uc:UC1.2');
Route::delete('/areas/{id}', [DiningController::class, 'destroyArea'])->middleware('uc:UC1.2');

Route::get('/tables', [DiningController::class, 'tables'])->middleware('uc:UC1.1,UC1.2,UC1.3,UC2.2,UC2.3');
Route::post('/tables', [DiningController::class, 'storeTable'])->middleware('uc:UC1.2');
Route::put('/tables/{id}', [DiningController::class, 'updateTable'])->middleware('uc:UC1.2');
Route::delete('/tables/{id}', [DiningController::class, 'destroyTable'])->middleware('uc:UC1.2');
Route::get('/available-tables', [DiningController::class, 'availableTables'])->middleware('uc:UC1.1,UC1.2,UC1.3,UC2.2');

Route::get('/employees', [DiningController::class, 'employees'])->middleware('uc:UC1.1,UC2.2,UC2.3,UC2.4,UC2.5,UC5.1,UC5.4,UC6.1,UC6.3,UC7.1,UC7.3,UC7.6,UC8.3');
Route::post('/employees/quick', [DiningController::class, 'quickStoreEmployee'])->middleware('uc:UC6.1');
Route::get('/customers', [DiningController::class, 'customers'])->middleware('uc:UC1.1');


// Đặt bàn.
Route::get('/reservations', [DiningController::class, 'reservations'])->middleware('uc:UC1.1');
Route::post('/reservations', [DiningController::class, 'storeReservation'])->middleware('uc:UC1.1');
Route::put('/reservations/{id}', [DiningController::class, 'updateReservation'])->middleware('uc:UC1.1');
Route::put('/reservations/{id}/status', [DiningController::class, 'updateReservationStatus'])->middleware('uc:UC1.1,UC1.3');
Route::delete('/reservations/{id}', [DiningController::class, 'cancelReservation'])->middleware('uc:UC1.1');


// ==========================================
// ĐỢT 2: ORDER / POS
// ==========================================

Route::get('/orders', [OrderController::class, 'index'])->middleware('uc:UC2.2,UC2.3,UC2.4');
Route::get('/orders/supervisors', [OrderController::class, 'supervisors'])->middleware('uc:UC2.2,UC2.3');
Route::post('/orders', [OrderController::class, 'store'])->middleware('uc:UC2.2');
Route::get('/orders/{id}', [OrderController::class, 'show'])->middleware('uc:UC2.2,UC2.3,UC2.4');
Route::post('/orders/{id}/items', [OrderController::class, 'addItem'])->middleware('uc:UC2.2');
Route::put('/orders/{id}/items/{detailId}', [OrderController::class, 'updateItem'])->middleware('uc:UC2.3');
Route::delete('/orders/{id}/items/{detailId}', [OrderController::class, 'removeItem'])->middleware('uc:UC2.3');
Route::post('/orders/{id}/send', [OrderController::class, 'send'])->middleware('uc:UC2.2,UC2.4');
Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])->middleware('uc:UC2.3');


// UC2.5 - Phiếu khiếu nại, xử lý sự cố.
Route::get('/complaints', [ComplaintController::class, 'index'])->middleware('uc:UC2.5');
Route::post('/complaints', [ComplaintController::class, 'store'])->middleware('uc:UC2.5');
Route::put('/complaints/{id}', [ComplaintController::class, 'update'])->middleware('uc:UC2.5');


// ==========================================
// ĐỢT 3: BẾP / BAR
// ==========================================

Route::get('/bep-bar/orders', [BepBarController::class, 'index'])->middleware('uc:UC3.1,UC3.2');
Route::get('/bep-bar/stock-requests', [BepBarController::class, 'stockRequests'])->middleware('uc:UC3.3');
Route::post('/bep-bar/stock-requests', [BepBarController::class, 'createStockRequest'])->middleware('uc:UC3.3');

Route::patch(
    '/bep-bar/items/{detailId}/status',
    [BepBarController::class, 'updateStatus']
)->middleware('uc:UC3.2');

Route::get('/service-notifications', [BepBarController::class, 'notifications'])->middleware('uc:UC2.4');

Route::put(
    '/service-notifications/{notificationId}/read',
    [BepBarController::class, 'markNotificationRead']
)->middleware('uc:UC2.4');


// ==========================================
// ĐỢT 4: KHO - NGUYÊN LIỆU - MUA HÀNG
// ==========================================

Route::get('/inventory/dashboard', [InventoryController::class, 'dashboard'])->middleware('uc:UC4.1,UC4.7');
Route::get('/inventory/stock', [InventoryController::class, 'stock'])->middleware('uc:UC4.1,UC4.7');

Route::get('/ingredients', [InventoryController::class, 'ingredients'])->middleware('uc:UC4.1,UC3.3,UC4.5,UC4.7');
Route::post('/ingredients', [InventoryController::class, 'storeIngredient'])->middleware('uc:UC4.1');
Route::put('/ingredients/{id}', [InventoryController::class, 'updateIngredient'])->middleware('uc:UC4.1');
Route::delete('/ingredients/{id}', [InventoryController::class, 'disableIngredient'])->middleware('uc:UC4.1');

Route::get('/suppliers', [InventoryController::class, 'suppliers'])->middleware('uc:UC4.6');
Route::post('/suppliers', [InventoryController::class, 'storeSupplier'])->middleware('uc:UC4.6');
Route::get('/suppliers/{id}/history', [InventoryController::class, 'supplierHistory'])->middleware('uc:UC4.6');
Route::put('/suppliers/{id}', [InventoryController::class, 'updateSupplier'])->middleware('uc:UC4.6');
Route::delete('/suppliers/{id}', [InventoryController::class, 'disableSupplier'])->middleware('uc:UC4.6');

Route::get('/inventory/purchase-orders', [InventoryController::class, 'purchaseOrders'])->middleware('uc:UC4.7');
Route::post('/inventory/purchase-orders', [InventoryController::class, 'storePurchaseOrder'])->middleware('uc:UC4.7');
Route::put('/inventory/purchase-orders/{id}', [InventoryController::class, 'updatePurchaseOrder'])->middleware('uc:UC4.7');

Route::post(
    '/inventory/purchase-orders/{id}/submit',
    [InventoryController::class, 'submitPurchaseOrder']
)->middleware('uc:UC4.7');

Route::post(
    '/inventory/purchase-orders/{id}/approve',
    [InventoryController::class, 'approvePurchaseOrder']
)->middleware('uc:UC4.7');

Route::post(
    '/inventory/purchase-orders/{id}/reject',
    [InventoryController::class, 'rejectPurchaseOrder']
)->middleware('uc:UC4.7');

Route::post(
    '/inventory/purchase-orders/{id}/send',
    [InventoryController::class, 'sendPurchaseOrder']
)->middleware('uc:UC4.7');

Route::get('/inventory/receipts', [InventoryController::class, 'receipts'])->middleware('uc:UC4.2');
Route::post('/inventory/receipts', [InventoryController::class, 'storeReceipt'])->middleware('uc:UC4.2');

Route::post(
    '/inventory/receipts/{id}/confirm',
    [InventoryController::class, 'confirmReceipt']
)->middleware('uc:UC4.2');

Route::post(
    '/inventory/receipts/{id}/cancel',
    [InventoryController::class, 'cancelReceipt']
)->middleware('uc:UC4.2');

Route::get('/inventory/issues', [InventoryController::class, 'issues'])->middleware('uc:UC4.3');
Route::post('/inventory/issues/{id}/confirm', [InventoryController::class, 'confirmIssue'])->middleware('uc:UC4.3');

Route::get('/inventory/stocktakes', [InventoryController::class, 'stocktakes'])->middleware('uc:UC4.4');
Route::post('/inventory/stocktakes', [InventoryController::class, 'storeStocktake'])->middleware('uc:UC4.4');

Route::post(
    '/inventory/stocktakes/{id}/confirm',
    [InventoryController::class, 'confirmStocktake']
)->middleware('uc:UC4.4');

Route::get('/inventory/recipes', [InventoryController::class, 'recipes'])->middleware('uc:UC4.5');

Route::put(
    '/inventory/recipes/{menuItemId}',
    [InventoryController::class, 'saveRecipe']
)->middleware('uc:UC4.5');


// ==========================================
// ĐỢT 5: THU NGÂN - HÓA ĐƠN - THANH TOÁN
// ==========================================

Route::get('/cashier/shifts', [CashierController::class, 'shifts'])->middleware('uc:UC5.6');

Route::get('/cashier/orders', [CashierController::class, 'orders'])->middleware('uc:UC5.1,UC5.2,UC5.4');
Route::get('/cashier/orders/{id}', [CashierController::class, 'showOrder'])->middleware('uc:UC5.1,UC5.2,UC5.4');

Route::get('/cashier/promotions', [CashierController::class, 'promotions'])->middleware('uc:UC5.3');

Route::get('/cashier/invoices', [CashierController::class, 'invoices'])->middleware('uc:UC5.1,UC5.2,UC5.4');
Route::post('/cashier/invoices', [CashierController::class, 'createInvoice'])->middleware('uc:UC5.2');
Route::get('/cashier/invoices/{id}', [CashierController::class, 'showInvoice'])->middleware('uc:UC5.1,UC5.2,UC5.4');

Route::post(
    '/cashier/invoices/{id}/promotions',
    [CashierController::class, 'applyPromotion']
)->middleware('uc:UC5.3');

Route::delete(
    '/cashier/invoices/{id}/promotions/{promotionId}',
    [CashierController::class, 'removePromotion']
)->middleware('uc:UC5.3');

Route::put(
    '/cashier/invoices/{id}/discount',
    [CashierController::class, 'setManualDiscount']
)->middleware('uc:UC5.3');

Route::post(
    '/cashier/invoices/{id}/payments',
    [CashierController::class, 'createPayment']
)->middleware('uc:UC5.4');

Route::post(
    '/cashier/invoices/{id}/split',
    [CashierController::class, 'splitInvoice']
)->middleware('uc:UC5.5');

Route::post(
    '/cashier/invoices/{id}/merge',
    [CashierController::class, 'mergeInvoices']
)->middleware('uc:UC5.5');

Route::patch(
    '/cashier/payments/{paymentId}/confirm',
    [CashierController::class, 'confirmPayment']
)->middleware('uc:UC5.4');

Route::get(
    '/cashier/shift-summary',
    [CashierController::class, 'shiftSummary']
)->middleware('uc:UC5.6');

Route::get(
    '/cashier/shift-reports',
    [CashierController::class, 'shiftReports']
)->middleware('uc:UC5.6');

Route::post(
    '/cashier/shift-reports',
    [CashierController::class, 'closeShift']
)->middleware('uc:UC5.6');

Route::post(
    '/cashier/shift-reports/{id}/confirm',
    [CashierController::class, 'confirmShiftReport']
)->middleware('uc:UC5.6');


// ==========================================
// ĐỢT 6: NHÂN SỰ - CHẤM CÔNG - TIỀN LƯƠNG
// ==========================================

Route::prefix('hr')->group(function () {

    // Tổng quan.
    Route::get('/dashboard', [HumanResourcesController::class, 'dashboard'])->middleware('uc:UC6.1,UC6.2,UC6.4');

    // Vị trí / chức danh.
    Route::get('/positions', [HumanResourcesController::class, 'positions'])->middleware('uc:UC6.1');
    Route::post('/positions', [HumanResourcesController::class, 'storePosition'])->middleware('uc:UC6.1');
    Route::put('/positions/{id}', [HumanResourcesController::class, 'updatePosition'])->middleware('uc:UC6.1');
    Route::delete('/positions/{id}', [HumanResourcesController::class, 'deletePosition'])->middleware('uc:UC6.1');

    // Hồ sơ nhân viên.
    Route::get('/employees', [HumanResourcesController::class, 'employees'])->middleware('uc:UC6.1,UC6.2,UC6.3,UC6.4,UC7.1,UC7.6,UC8.3');
    Route::post('/employees', [HumanResourcesController::class, 'storeEmployee'])->middleware('uc:UC6.1');
    Route::put('/employees/{id}', [HumanResourcesController::class, 'updateEmployee'])->middleware('uc:UC6.1');

    Route::patch(
        '/employees/{id}/status',
        [HumanResourcesController::class, 'changeEmployeeStatus']
    )->middleware('uc:UC6.1');

    // Hợp đồng lao động.
    Route::get('/contracts', [HumanResourcesController::class, 'contracts'])->middleware('uc:UC6.1');
    Route::post('/contracts', [HumanResourcesController::class, 'storeContract'])->middleware('uc:UC6.1');
    Route::put('/contracts/{id}', [HumanResourcesController::class, 'updateContract'])->middleware('uc:UC6.1');

    Route::post(
        '/contracts/{id}/end',
        [HumanResourcesController::class, 'endContract']
    )->middleware('uc:UC6.1');

    // Ca làm.
    Route::get('/shifts', [HumanResourcesController::class, 'shifts'])->middleware('uc:UC6.2,UC6.3');
    Route::post('/shifts', [HumanResourcesController::class, 'storeShift'])->middleware('uc:UC6.2');
    Route::put('/shifts/{id}', [HumanResourcesController::class, 'updateShift'])->middleware('uc:UC6.2');

    Route::post(
        '/shifts/{id}/disable',
        [HumanResourcesController::class, 'disableShift']
    )->middleware('uc:UC6.2');

    // Xếp ca / chấm công.
    Route::get('/attendance', [HumanResourcesController::class, 'attendance'])->middleware('uc:UC6.2,UC6.3,UC6.4');
    Route::post('/attendance', [HumanResourcesController::class, 'assignAttendance'])->middleware('uc:UC6.2,UC6.3');
    Route::patch('/attendance/{id}', [HumanResourcesController::class, 'updateAttendance'])->middleware('uc:UC6.2,UC6.3');

    Route::post(
        '/attendance/{id}/clock-in',
        [HumanResourcesController::class, 'clockIn']
    )->middleware('uc:UC6.2,UC6.3');

    Route::post(
        '/attendance/{id}/clock-out',
        [HumanResourcesController::class, 'clockOut']
    )->middleware('uc:UC6.2,UC6.3');

    Route::delete(
        '/attendance/{id}',
        [HumanResourcesController::class, 'deleteAttendance']
    )->middleware('uc:UC6.2,UC6.3');

    // Bảng lương.
    Route::get('/payrolls', [HumanResourcesController::class, 'payrolls'])->middleware('uc:UC6.4');

    Route::post(
        '/payrolls/generate',
        [HumanResourcesController::class, 'generatePayroll']
    )->middleware('uc:UC6.4');

    Route::put('/payrolls/{id}', [HumanResourcesController::class, 'updatePayroll'])->middleware('uc:UC6.4');

    Route::post(
        '/payrolls/{id}/submit',
        [HumanResourcesController::class, 'submitPayroll']
    )->middleware('uc:UC6.4');

    Route::post(
        '/payrolls/{id}/return',
        [HumanResourcesController::class, 'returnPayroll']
    )->middleware('uc:UC6.4');

    Route::post(
        '/payrolls/{id}/approve',
        [HumanResourcesController::class, 'approvePayroll']
    )->middleware('uc:UC6.4');
});

// TASK 8: Quản trị hệ thống, quyền theo UC và yêu cầu hỗ trợ.
Route::get('/auth/me', [AuthController::class, 'me']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
Route::prefix('system')->group(function () {
    Route::get('/roles', [SystemAdminController::class, 'roles'])->middleware('uc:UC8.3');
    Route::post('/roles', [SystemAdminController::class, 'createRole'])->middleware('uc:UC8.3');
    Route::get('/roles/{id}/permissions', [SystemAdminController::class, 'permissions'])->middleware('uc:UC8.3');
    Route::put('/roles/{id}/permissions', [SystemAdminController::class, 'savePermissions'])->middleware('uc:UC8.3');
    Route::get('/accounts', [SystemAdminController::class, 'accounts'])->middleware('uc:UC8.3');
    Route::post('/accounts', [SystemAdminController::class, 'createAccount'])->middleware('uc:UC8.3');
    Route::put('/accounts/{id}', [SystemAdminController::class, 'updateAccount'])->middleware('uc:UC8.3');
    Route::post('/accounts/{id}/reset-password', [SystemAdminController::class, 'resetPassword'])->middleware('uc:UC8.3');
    Route::get('/audit', [SystemAdminController::class, 'audits'])->middleware('uc:UC8.4');
    Route::get('/tickets', [SystemAdminController::class, 'tickets'])->middleware('uc:UC8.5');
    Route::post('/tickets', [SystemAdminController::class, 'createTicket'])->middleware('uc:UC8.5');
    Route::put('/tickets/{id}', [SystemAdminController::class, 'updateTicket'])->middleware('uc:UC8.5');
});

// Nếu đã cài Đợt 7, các route finance đang có cũng cần bảo vệ bằng UC.
Route::prefix('finance')->group(function () {
    Route::get('/dashboard', [FinanceController::class, 'dashboard'])->middleware('uc:UC7.5');
    Route::get('/reconciliation', [FinanceController::class, 'reconciliation'])->middleware('uc:UC7.2');
    Route::get('/vouchers', [FinanceController::class, 'vouchers'])->middleware('uc:UC7.1');
    Route::post('/vouchers', [FinanceController::class, 'storeVoucher'])->middleware('uc:UC7.1');
    Route::post('/vouchers/{id}/review', [FinanceController::class, 'reviewVoucher'])->middleware('uc:UC7.1');
    Route::get('/promotions', [FinanceController::class, 'promotions'])->middleware('uc:UC7.3');
    Route::post('/promotions', [FinanceController::class, 'storePromotion'])->middleware('uc:UC7.3');
    Route::put('/promotions/{id}', [FinanceController::class, 'updatePromotion'])->middleware('uc:UC7.3');
    Route::post('/promotions/{id}/status', [FinanceController::class, 'promotionStatus'])->middleware('uc:UC7.3');
    Route::get('/promotion-performance', [FinanceController::class, 'promotionPerformance'])->middleware('uc:UC7.4');
    Route::get('/budgets', [FinanceController::class, 'budgets'])->middleware('uc:UC7.6');
    Route::post('/budgets', [FinanceController::class, 'storeBudget'])->middleware('uc:UC7.6');
    Route::post('/budgets/{id}/status', [FinanceController::class, 'budgetStatus'])->middleware('uc:UC7.6');
});
});
