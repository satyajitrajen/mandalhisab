<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MandalController;
use App\Http\Controllers\Api\V1\FestivalController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\VarganiController;
use App\Http\Controllers\Api\V1\ReceiptBookController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\FundController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\EventStreamController;
use App\Http\Controllers\Api\V1\AreaController;

/*
|--------------------------------------------------------------------------
| API Routes — MandalHisab v1
|--------------------------------------------------------------------------
| Base: /api/v1
| All paths prefixed automatically by RouteServiceProvider.
*/

Route::prefix('v1')->group(function () {

    // Public
    Route::get('config/app', [ConfigController::class, 'appConfig']);
    Route::get('public/receipts/{receiptNumber}', [VarganiController::class, 'publicReceipt']);
    Route::post('public/account-deletion-request', [AuthController::class, 'publicAccountDeletionRequest']);

    // Auth (public, rate-limited 5/min/IP via rate.limit)
    Route::middleware('rate.limit')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/token/refresh', [AuthController::class, 'refresh']);
        Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);
    });

    // Protected
    Route::middleware(['jwt.auth', 'tenant.scope', 'rate.limit'])->group(function () {

        // Auth & Profile
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::put('auth/me', [AuthController::class, 'updateMe']);
        Route::put('auth/security-pin', [AuthController::class, 'setPin']);
        Route::put('auth/password', [AuthController::class, 'changePassword']);
        Route::delete('auth/me', [AuthController::class, 'deleteMe']);

        // Mandals & Areas
        Route::apiResource('mandals', MandalController::class);
        Route::get('mandals/{mandal}/areas', [AreaController::class, 'index']);
        Route::post('mandals/{mandal}/areas', [AreaController::class, 'store']);
        Route::put('areas/{area}', [AreaController::class, 'update']);
        Route::delete('areas/{area}', [AreaController::class, 'destroy']);

        // Festivals (nested + shallow)
        Route::apiResource('mandals.festivals', FestivalController::class)->shallow()->only(['index', 'store']);
        Route::apiResource('festivals', FestivalController::class)->only(['show', 'update']);

        // Dashboard
        Route::get('festivals/{festival}/dashboard/summary', [DashboardController::class, 'summary']);

        // Vargani (reads; export before generic show to avoid shadowing)
        Route::get('festivals/{festival}/vargani', [VarganiController::class, 'index']);
        Route::get('festivals/{festival}/vargani/export', [VarganiController::class, 'export']);
        Route::get('festivals/{festival}/vargani/{vargani}', [VarganiController::class, 'show']);
        Route::get('festivals/{festival}/vargani/{vargani}/pdf', [VarganiController::class, 'pdf']);

        // Vargani (writes, blocked once final hisab is locked, idempotent)
        Route::middleware(['hisab.locked', 'idempotency'])->group(function () {
            Route::post('festivals/{festival}/vargani', [VarganiController::class, 'store']);
            Route::post('festivals/{festival}/vargani/{vargani}/cancel', [VarganiController::class, 'cancel']);
            Route::post('festivals/{festival}/vargani/{vargani}/signature', [VarganiController::class, 'uploadSignature']);
        });

        // Receipt Books (reads)
        Route::get('festivals/{festival}/receipt-books', [ReceiptBookController::class, 'index']);
        Route::get('receipt-books/{book}', [ReceiptBookController::class, 'show']);

        // Receipt Books (writes, blocked once final hisab is locked, idempotent)
        Route::middleware(['hisab.locked', 'idempotency'])->group(function () {
            Route::post('festivals/{festival}/receipt-books', [ReceiptBookController::class, 'store']);
            Route::post('receipt-books/{book}/assign', [ReceiptBookController::class, 'assign']);
            Route::patch('receipt-books/{book}/status', [ReceiptBookController::class, 'updateStatus']);
        });

        // Expenses (reads; export before generic show to avoid shadowing)
        Route::get('festivals/{festival}/expenses', [ExpenseController::class, 'index']);
        Route::get('festivals/{festival}/expenses/export', [ExpenseController::class, 'export']);
        Route::get('festivals/{festival}/expenses/{expense}', [ExpenseController::class, 'show']);

        // Expenses (writes, blocked once final hisab is locked, idempotent)
        Route::middleware(['hisab.locked', 'idempotency'])->group(function () {
            Route::post('festivals/{festival}/expenses', [ExpenseController::class, 'store']);
            Route::patch('festivals/{festival}/expenses/{expense}', [ExpenseController::class, 'update']);
            Route::post('festivals/{festival}/expenses/{expense}/bill', [ExpenseController::class, 'uploadBill']);
            Route::patch('festivals/{festival}/expenses/{expense}/mark-paid', [ExpenseController::class, 'markPaid']);
        });

        // Funds, Handovers, Bank Accounts, Transfers
        // Reads are ADMIN/TREASURER only (matches controller checks)
        Route::get('festivals/{festival}/funds/summary', [FundController::class, 'summary'])->middleware('role:ADMIN,TREASURER');
        Route::get('festivals/{festival}/funds/money-trail', [FundController::class, 'moneyTrail'])->middleware('role:ADMIN,TREASURER');
        Route::get('festivals/{festival}/funds/handovers', [FundController::class, 'indexHandovers'])->middleware('role:ADMIN,TREASURER');
        Route::get('funds/handovers/{handover}', [FundController::class, 'showHandover'])->middleware('role:ADMIN,TREASURER');
        Route::get('festivals/{festival}/funds/bank-accounts', [FundController::class, 'indexBankAccounts'])->middleware('role:ADMIN,TREASURER');
        Route::get('festivals/{festival}/other-income', [FundController::class, 'indexOtherIncome'])->middleware('role:ADMIN,TREASURER');

        // Funds (writes, blocked once final hisab is locked, idempotent)
        Route::middleware(['hisab.locked', 'idempotency'])->group(function () {
            Route::post('festivals/{festival}/funds/handovers', [FundController::class, 'storeHandover']);
            Route::post('funds/handovers/{handover}/verify', [FundController::class, 'verifyHandover'])->middleware('role:ADMIN,TREASURER');
            Route::post('festivals/{festival}/funds/bank-accounts', [FundController::class, 'storeBankAccount'])->middleware('role:ADMIN,TREASURER');
            Route::patch('funds/bank-accounts/{account}', [FundController::class, 'updateBankAccount'])->middleware('role:ADMIN,TREASURER');
            Route::post('festivals/{festival}/funds/transfers', [FundController::class, 'transfer'])->middleware('role:ADMIN,TREASURER');
            Route::post('festivals/{festival}/other-income', [FundController::class, 'storeOtherIncome'])->middleware('role:ADMIN,TREASURER');
        });

        // Members (writes are idempotent so offline retries can't duplicate)
        Route::apiResource('mandals.members', MemberController::class)->only(['index', 'store']);
        Route::apiResource('members', MemberController::class)->only(['show', 'update']);
        Route::get('mandals/{mandal}/members/{member}/financial-summary', [MemberController::class, 'financialSummary']);
        Route::post('mandals/{mandal}/members/{member}/deactivate', [MemberController::class, 'deactivate'])->middleware('idempotency');

        // Reports (specific routes must come before generic {reportType})
        Route::get('festivals/{festival}/reports/overview', [ReportController::class, 'overview']);
        Route::get('festivals/{festival}/reports/final-hisab', [ReportController::class, 'finalHisab']);
        Route::post('festivals/{festival}/reports/final-hisab/sign', [ReportController::class, 'signFinalHisab']);
        Route::get('festivals/{festival}/reports/final-hisab/pdf', [ReportController::class, 'finalHisabPdf']);
        Route::get('festivals/{festival}/reports/{reportType}', [ReportController::class, 'typedReport']);

        // Devices & Notifications
        Route::put('devices/token', [DeviceController::class, 'register']);
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);

        // Sync (idempotent: offline retries with the same client UUID must not duplicate)
        Route::post('sync/batch', [SyncController::class, 'batchPush'])->middleware(['hisab.locked', 'idempotency']);
        Route::get('sync/pull', [SyncController::class, 'pullDelta']);

        // SSE
        Route::get('stream/events', [EventStreamController::class, 'stream']);
    });
});
