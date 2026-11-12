<?php

use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BepBarController;
use App\Http\Controllers\Api\CashierController;
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

// ==========================================
// ĐỢT 1: CƠ SỞ VÀ THỰC ĐƠN
// ==========================================

Route::get('/branches', [BranchController::class, 'index']);

Route::apiResource('/categories', MenuCategoryController::class)
    ->only(['index', 'store', 'update', 'destroy']);

Route::put(
    '/menu-items/{id}/branch-status',
    [MenuItemController::class, 'updateBranchStatus']
);

Route::apiResource('/menu-items', MenuItemController::class)
    ->only(['index', 'store', 'update', 'destroy']);


// ==========================================
// ĐỢT 2: KHU VỰC VÀ BÀN
// ==========================================

Route::get('/areas', [DiningController::class, 'areas']);
Route::post('/areas', [DiningController::class, 'storeArea']);
Route::put('/areas/{id}', [DiningController::class, 'updateArea']);
Route::delete('/areas/{id}', [DiningController::class, 'destroyArea']);

Route::get('/tables', [DiningController::class, 'tables']);
Route::post('/tables', [DiningController::class, 'storeTable']);
Route::put('/tables/{id}', [DiningController::class, 'updateTable']);
Route::delete('/tables/{id}', [DiningController::class, 'destroyTable']);
Route::get('/available-tables', [DiningController::class, 'availableTables']);

Route::get('/employees', [DiningController::class, 'employees']);
Route::post('/employees/quick', [DiningController::class, 'quickStoreEmployee']);
Route::get('/customers', [DiningController::class, 'customers']);


// Đặt bàn.
Route::get('/reservations', [DiningController::class, 'reservations']);
Route::post('/reservations', [DiningController::class, 'storeReservation']);
Route::put('/reservations/{id}', [DiningController::class, 'updateReservation']);
Route::put('/reservations/{id}/status', [DiningController::class, 'updateReservationStatus']);
Route::delete('/reservations/{id}', [DiningController::class, 'cancelReservation']);


// ==========================================
// ĐỢT 2: ORDER / POS
// ==========================================

Route::get('/orders', [OrderController::class, 'index']);
Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/{id}', [OrderController::class, 'show']);
Route::post('/orders/{id}/items', [OrderController::class, 'addItem']);
Route::put('/orders/{id}/items/{detailId}', [OrderController::class, 'updateItem']);
Route::delete('/orders/{id}/items/{detailId}', [OrderController::class, 'removeItem']);
Route::post('/orders/{id}/send', [OrderController::class, 'send']);
Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel']);


// ==========================================
// ĐỢT 3: BẾP / BAR
// ==========================================

Route::get('/bep-bar/orders', [BepBarController::class, 'index']);

Route::patch(
    '/bep-bar/items/{detailId}/status',
    [BepBarController::class, 'updateStatus']
);

Route::get('/service-notifications', [BepBarController::class, 'notifications']);

Route::put(
    '/service-notifications/{notificationId}/read',
    [BepBarController::class, 'markNotificationRead']
);


// ==========================================
// ĐỢT 4: KHO - NGUYÊN LIỆU - MUA HÀNG
// ==========================================

Route::get('/inventory/dashboard', [InventoryController::class, 'dashboard']);
Route::get('/inventory/stock', [InventoryController::class, 'stock']);

Route::get('/ingredients', [InventoryController::class, 'ingredients']);
Route::post('/ingredients', [InventoryController::class, 'storeIngredient']);
Route::put('/ingredients/{id}', [InventoryController::class, 'updateIngredient']);
Route::delete('/ingredients/{id}', [InventoryController::class, 'disableIngredient']);

Route::get('/suppliers', [InventoryController::class, 'suppliers']);
Route::post('/suppliers', [InventoryController::class, 'storeSupplier']);
Route::get('/suppliers/{id}/history', [InventoryController::class, 'supplierHistory']);
Route::put('/suppliers/{id}', [InventoryController::class, 'updateSupplier']);
Route::delete('/suppliers/{id}', [InventoryController::class, 'disableSupplier']);

Route::get('/inventory/purchase-orders', [InventoryController::class, 'purchaseOrders']);
Route::post('/inventory/purchase-orders', [InventoryController::class, 'storePurchaseOrder']);
Route::put('/inventory/purchase-orders/{id}', [InventoryController::class, 'updatePurchaseOrder']);

Route::post(
    '/inventory/purchase-orders/{id}/submit',
    [InventoryController::class, 'submitPurchaseOrder']
);

Route::post(
    '/inventory/purchase-orders/{id}/approve',
    [InventoryController::class, 'approvePurchaseOrder']
);

Route::post(
    '/inventory/purchase-orders/{id}/reject',
    [InventoryController::class, 'rejectPurchaseOrder']
);

Route::post(
    '/inventory/purchase-orders/{id}/send',
    [InventoryController::class, 'sendPurchaseOrder']
);

Route::get('/inventory/receipts', [InventoryController::class, 'receipts']);
Route::post('/inventory/receipts', [InventoryController::class, 'storeReceipt']);

Route::post(
    '/inventory/receipts/{id}/confirm',
    [InventoryController::class, 'confirmReceipt']
);

Route::post(
    '/inventory/receipts/{id}/cancel',
    [InventoryController::class, 'cancelReceipt']
);

Route::get('/inventory/issues', [InventoryController::class, 'issues']);
Route::post('/inventory/issues', [InventoryController::class, 'storeIssue']);

Route::get('/inventory/stocktakes', [InventoryController::class, 'stocktakes']);
Route::post('/inventory/stocktakes', [InventoryController::class, 'storeStocktake']);

Route::post(
    '/inventory/stocktakes/{id}/confirm',
    [InventoryController::class, 'confirmStocktake']
);

Route::get('/inventory/recipes', [InventoryController::class, 'recipes']);

Route::put(
    '/inventory/recipes/{menuItemId}',
    [InventoryController::class, 'saveRecipe']
);


// ==========================================
// ĐỢT 5: THU NGÂN - HÓA ĐƠN - THANH TOÁN
// ==========================================

Route::get('/cashier/shifts', [CashierController::class, 'shifts']);

Route::get('/cashier/orders', [CashierController::class, 'orders']);
Route::get('/cashier/orders/{id}', [CashierController::class, 'showOrder']);

Route::get('/cashier/promotions', [CashierController::class, 'promotions']);

Route::get('/cashier/invoices', [CashierController::class, 'invoices']);
Route::post('/cashier/invoices', [CashierController::class, 'createInvoice']);
Route::get('/cashier/invoices/{id}', [CashierController::class, 'showInvoice']);

Route::post(
    '/cashier/invoices/{id}/promotions',
    [CashierController::class, 'applyPromotion']
);

Route::delete(
    '/cashier/invoices/{id}/promotions/{promotionId}',
    [CashierController::class, 'removePromotion']
);

Route::put(
    '/cashier/invoices/{id}/discount',
    [CashierController::class, 'setManualDiscount']
);

Route::post(
    '/cashier/invoices/{id}/payments',
    [CashierController::class, 'createPayment']
);

Route::post(
    '/cashier/invoices/{id}/split',
    [CashierController::class, 'splitInvoice']
);

Route::post(
    '/cashier/invoices/{id}/merge',
    [CashierController::class, 'mergeInvoices']
);

Route::patch(
    '/cashier/payments/{paymentId}/confirm',
    [CashierController::class, 'confirmPayment']
);

Route::get(
    '/cashier/shift-summary',
    [CashierController::class, 'shiftSummary']
);

Route::get(
    '/cashier/shift-reports',
    [CashierController::class, 'shiftReports']
);

Route::post(
    '/cashier/shift-reports',
    [CashierController::class, 'closeShift']
);

Route::post(
    '/cashier/shift-reports/{id}/confirm',
    [CashierController::class, 'confirmShiftReport']
);


// ==========================================
// ĐỢT 6: NHÂN SỰ - CHẤM CÔNG - TIỀN LƯƠNG
// ==========================================

Route::prefix('hr')->group(function () {

    // Tổng quan.
    Route::get('/dashboard', [HumanResourcesController::class, 'dashboard']);

    // Vị trí / chức danh.
    Route::get('/positions', [HumanResourcesController::class, 'positions']);
    Route::post('/positions', [HumanResourcesController::class, 'storePosition']);
    Route::put('/positions/{id}', [HumanResourcesController::class, 'updatePosition']);
    Route::delete('/positions/{id}', [HumanResourcesController::class, 'deletePosition']);

    // Hồ sơ nhân viên.
    Route::get('/employees', [HumanResourcesController::class, 'employees']);
    Route::post('/employees', [HumanResourcesController::class, 'storeEmployee']);
    Route::put('/employees/{id}', [HumanResourcesController::class, 'updateEmployee']);

    Route::patch(
        '/employees/{id}/status',
        [HumanResourcesController::class, 'changeEmployeeStatus']
    );

    // Hợp đồng lao động.
    Route::get('/contracts', [HumanResourcesController::class, 'contracts']);
    Route::post('/contracts', [HumanResourcesController::class, 'storeContract']);
    Route::put('/contracts/{id}', [HumanResourcesController::class, 'updateContract']);

    Route::post(
        '/contracts/{id}/end',
        [HumanResourcesController::class, 'endContract']
    );

    // Ca làm.
    Route::get('/shifts', [HumanResourcesController::class, 'shifts']);
    Route::post('/shifts', [HumanResourcesController::class, 'storeShift']);
    Route::put('/shifts/{id}', [HumanResourcesController::class, 'updateShift']);

    Route::post(
        '/shifts/{id}/disable',
        [HumanResourcesController::class, 'disableShift']
    );

    // Xếp ca / chấm công.
    Route::get('/attendance', [HumanResourcesController::class, 'attendance']);
    Route::post('/attendance', [HumanResourcesController::class, 'assignAttendance']);
    Route::patch('/attendance/{id}', [HumanResourcesController::class, 'updateAttendance']);

    Route::post(
        '/attendance/{id}/clock-in',
        [HumanResourcesController::class, 'clockIn']
    );

    Route::post(
        '/attendance/{id}/clock-out',
        [HumanResourcesController::class, 'clockOut']
    );

    Route::delete(
        '/attendance/{id}',
        [HumanResourcesController::class, 'deleteAttendance']
    );

    // Bảng lương.
    Route::get('/payrolls', [HumanResourcesController::class, 'payrolls']);

    Route::post(
        '/payrolls/generate',
        [HumanResourcesController::class, 'generatePayroll']
    );

    Route::put('/payrolls/{id}', [HumanResourcesController::class, 'updatePayroll']);

    Route::post(
        '/payrolls/{id}/submit',
        [HumanResourcesController::class, 'submitPayroll']
    );

    Route::post(
        '/payrolls/{id}/return',
        [HumanResourcesController::class, 'returnPayroll']
    );

    Route::post(
        '/payrolls/{id}/approve',
        [HumanResourcesController::class, 'approvePayroll']
    );
});