<?php

namespace Tests\Feature\ApiCoverage;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The 100% API coverage gate.
 *
 * Walks every registered /api/v1 route and asserts it is listed in the
 * coverage manifest below. Any new endpoint added to the API without a
 * corresponding contract test FAILS this test.
 *
 * Manifest key: "<PRIMARY_METHOD> api/v1/<uri>" -> test class covering it.
 */
class RouteCoverageTest extends TestCase
{
    /** Route key => owning test class. */
    private const MANIFEST = [
        // --- Public (6) ---
        'GET api/v1/config/app' => 'Tests\Feature\Api\V1\HealthCheckTest',
        'GET api/v1/public/receipts/{receiptNumber}' => 'Tests\Feature\ApiCoverage\VarganiContractTest',
        'POST api/v1/public/account-deletion-request' => 'Tests\Feature\AuthTest',
        'POST api/v1/auth/register' => 'Tests\Feature\AuthTest',
        'POST api/v1/auth/login' => 'Tests\Feature\AuthTest',
        'POST api/v1/auth/token/refresh' => 'Tests\Feature\ApiCoverage\AuthContractTest',
        'POST api/v1/auth/forgot-password' => 'Tests\Feature\ApiCoverage\AuthContractTest',
        'POST api/v1/auth/reset-password' => 'Tests\Feature\ApiCoverage\AuthContractTest',

        // --- Auth & Profile (6) ---
        'POST api/v1/auth/logout' => 'Tests\Feature\ApiCoverage\AuthContractTest',
        'GET api/v1/auth/me' => 'Tests\Feature\ApiCoverage\AuthContractTest',
        'PUT api/v1/auth/me' => 'Tests\Feature\ApiCoverage\AuthContractTest',
        'PUT api/v1/auth/security-pin' => 'Tests\Feature\ApiCoverage\AuthContractTest',
        'PUT api/v1/auth/password' => 'Tests\Feature\ApiCoverage\AuthContractTest',
        'DELETE api/v1/auth/me' => 'Tests\Feature\AuthTest',

        // --- Mandals (5) ---
        'GET api/v1/mandals' => 'Tests\Feature\ApiCoverage\MandalFestivalContractTest',
        'POST api/v1/mandals' => 'Tests\Feature\ApiCoverage\MandalFestivalContractTest',
        'GET api/v1/mandals/{mandal}' => 'Tests\Feature\ApiCoverage\MandalFestivalContractTest',
        'PUT api/v1/mandals/{mandal}' => 'Tests\Feature\ApiCoverage\MandalFestivalContractTest',
        'DELETE api/v1/mandals/{mandal}' => 'Tests\Feature\ApiCoverage\MandalFestivalContractTest',

        // --- Festivals (4) ---
        'GET api/v1/mandals/{mandal}/festivals' => 'Tests\Feature\FestivalTest',
        'POST api/v1/mandals/{mandal}/festivals' => 'Tests\Feature\FestivalTest',
        'GET api/v1/festivals/{festival}' => 'Tests\Feature\ApiCoverage\MandalFestivalContractTest',
        'PUT api/v1/festivals/{festival}' => 'Tests\Feature\ApiCoverage\MandalFestivalContractTest',

        // --- Dashboard (1) ---
        'GET api/v1/festivals/{festival}/dashboard/summary' => 'Tests\Feature\ApiCoverage\MandalFestivalContractTest',

        // --- Vargani (7) ---
        'GET api/v1/festivals/{festival}/vargani' => 'Tests\Feature\ApiCoverage\VarganiContractTest',
        'GET api/v1/festivals/{festival}/vargani/export' => 'Tests\Feature\ApiCoverage\VarganiContractTest',
        'GET api/v1/festivals/{festival}/vargani/{vargani}' => 'Tests\Feature\ApiCoverage\VarganiContractTest',
        'GET api/v1/festivals/{festival}/vargani/{vargani}/pdf' => 'Tests\Feature\ApiCoverage\VarganiContractTest',
        'POST api/v1/festivals/{festival}/vargani' => 'Tests\Feature\VarganiTest',
        'POST api/v1/festivals/{festival}/vargani/{vargani}/cancel' => 'Tests\Feature\ApiCoverage\VarganiContractTest',
        'POST api/v1/festivals/{festival}/vargani/{vargani}/signature' => 'Tests\Feature\ApiCoverage\VarganiContractTest',

        // --- Receipt Books (5) ---
        'GET api/v1/festivals/{festival}/receipt-books' => 'Tests\Feature\ApiCoverage\ReceiptBookContractTest',
        'GET api/v1/receipt-books/{book}' => 'Tests\Feature\ApiCoverage\ReceiptBookContractTest',
        'POST api/v1/festivals/{festival}/receipt-books' => 'Tests\Feature\ApiCoverage\ReceiptBookContractTest',
        'POST api/v1/receipt-books/{book}/assign' => 'Tests\Feature\ApiCoverage\ReceiptBookContractTest',
        'PATCH api/v1/receipt-books/{book}/status' => 'Tests\Feature\ApiCoverage\ReceiptBookContractTest',

        // --- Expenses (7) ---
        'GET api/v1/festivals/{festival}/expenses' => 'Tests\Feature\ApiCoverage\ExpenseContractTest',
        'GET api/v1/festivals/{festival}/expenses/export' => 'Tests\Feature\ApiCoverage\ExpenseContractTest',
        'GET api/v1/festivals/{festival}/expenses/{expense}' => 'Tests\Feature\ApiCoverage\ExpenseContractTest',
        'POST api/v1/festivals/{festival}/expenses' => 'Tests\Feature\ApiCoverage\ExpenseContractTest',
        'PATCH api/v1/festivals/{festival}/expenses/{expense}' => 'Tests\Feature\ApiCoverage\ExpenseContractTest',
        'POST api/v1/festivals/{festival}/expenses/{expense}/bill' => 'Tests\Feature\ApiCoverage\ExpenseContractTest',
        'PATCH api/v1/festivals/{festival}/expenses/{expense}/mark-paid' => 'Tests\Feature\ApiCoverage\ExpenseContractTest',

        // --- Funds (12) ---
        'GET api/v1/festivals/{festival}/funds/summary' => 'Tests\Feature\FundFlowTest',
        'GET api/v1/festivals/{festival}/funds/money-trail' => 'Tests\Feature\ApiCoverage\FundContractTest',
        'GET api/v1/festivals/{festival}/funds/handovers' => 'Tests\Feature\ApiCoverage\FundContractTest',
        'GET api/v1/funds/handovers/{handover}' => 'Tests\Feature\ApiCoverage\FundContractTest',
        'GET api/v1/festivals/{festival}/funds/bank-accounts' => 'Tests\Feature\ApiCoverage\FundContractTest',
        'GET api/v1/festivals/{festival}/other-income' => 'Tests\Feature\ApiCoverage\FundContractTest',
        'POST api/v1/festivals/{festival}/funds/handovers' => 'Tests\Feature\FundFlowTest',
        'POST api/v1/funds/handovers/{handover}/verify' => 'Tests\Feature\FundFlowTest',
        'POST api/v1/festivals/{festival}/funds/bank-accounts' => 'Tests\Feature\ApiCoverage\FundContractTest',
        'PATCH api/v1/funds/bank-accounts/{account}' => 'Tests\Feature\ApiCoverage\FundContractTest',
        'POST api/v1/festivals/{festival}/funds/transfers' => 'Tests\Feature\FundFlowTest',
        'POST api/v1/festivals/{festival}/other-income' => 'Tests\Feature\ApiCoverage\FundContractTest',

        // --- Members (6) ---
        'GET api/v1/mandals/{mandal}/members' => 'Tests\Feature\ApiCoverage\MemberContractTest',
        'POST api/v1/mandals/{mandal}/members' => 'Tests\Feature\ApiCoverage\MemberContractTest',
        'GET api/v1/members/{member}' => 'Tests\Feature\ApiCoverage\MemberContractTest',
        'PUT api/v1/members/{member}' => 'Tests\Feature\ApiCoverage\MemberContractTest',
        'GET api/v1/mandals/{mandal}/members/{member}/financial-summary' => 'Tests\Feature\ApiCoverage\MemberContractTest',
        'POST api/v1/mandals/{mandal}/members/{member}/deactivate' => 'Tests\Feature\ApiCoverage\MemberContractTest',

        // --- Reports (6) ---
        'GET api/v1/festivals/{festival}/reports/overview' => 'Tests\Feature\ApiCoverage\ReportContractTest',
        'GET api/v1/festivals/{festival}/reports/final-hisab' => 'Tests\Feature\ApiCoverage\ReportContractTest',
        'POST api/v1/festivals/{festival}/reports/final-hisab/sign' => 'Tests\Feature\ApiCoverage\ReportContractTest',
        'GET api/v1/festivals/{festival}/reports/final-hisab/pdf' => 'Tests\Feature\ApiCoverage\ReportContractTest',
        'GET api/v1/festivals/{festival}/reports/{reportType}' => 'Tests\Feature\ApiCoverage\ReportContractTest',

        // --- Devices & Notifications (4) ---
        'PUT api/v1/devices/token' => 'Tests\Feature\ApiCoverage\DeviceNotificationContractTest',
        'GET api/v1/notifications' => 'Tests\Feature\ApiCoverage\DeviceNotificationContractTest',
        'PATCH api/v1/notifications/{notification}/read' => 'Tests\Feature\ApiCoverage\DeviceNotificationContractTest',
        'POST api/v1/notifications/read-all' => 'Tests\Feature\ApiCoverage\DeviceNotificationContractTest',

        // --- Sync & Events (3) ---
        'POST api/v1/sync/batch' => 'Tests\Feature\ApiCoverage\SyncContractTest',
        'GET api/v1/sync/pull' => 'Tests\Feature\ApiCoverage\SyncContractTest',
        'GET api/v1/stream/events' => 'Tests\Feature\ApiCoverage\SyncContractTest',
    ];

    public function test_every_api_route_is_covered_by_a_contract_suite(): void
    {
        $apiRoutes = collect(Route::getRoutes())->filter(function ($route) {
            return str_starts_with($route->uri(), 'api/v1/');
        });

        $this->assertGreaterThanOrEqual(70, $apiRoutes->count(), 'Expected at least 70 api/v1 routes');

        $uncovered = [];
        foreach ($apiRoutes as $route) {
            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');
            $key = strtoupper($method) . ' ' . $route->uri();

            if (! isset(self::MANIFEST[$key])) {
                $uncovered[] = $key . '  ->  ' . implode('|', $route->methods());
            }
        }

        $this->assertEmpty(
            $uncovered,
            "API routes without test coverage:\n" . implode("\n", $uncovered)
        );
    }

    public function test_manifest_does_not_reference_missing_routes(): void
    {
        // Guard against stale manifest entries (route removed but manifest kept).
        $registered = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/'))
            ->map(fn ($r) => strtoupper(collect($r->methods())->first(fn ($m) => $m !== 'HEAD')) . ' ' . $r->uri())
            ->all();

        $stale = array_diff(array_keys(self::MANIFEST), $registered);

        $this->assertEmpty($stale, 'Manifest entries with no matching route: ' . implode(', ', $stale));
    }
}