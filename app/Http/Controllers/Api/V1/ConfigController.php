<?php

namespace App\Http\Controllers\Api\V1;

use App\Traits\ApiResponse;

class ConfigController
{
    use ApiResponse;

    /**
     * GET /api/v1/config/app
     *
     * Public endpoint: application configuration.
     */
    public function appConfig()
    {
        $config = [
            'appName' => 'MandalHisab',
            'minSupportedVersion' => '2.0.0',
            'latestVersion' => '2.1.0',
            'forceUpdate' => false,
            'maintenanceMode' => false,
            'maintenanceMessage' => null,
            'supportPhone' => '+91-XXXXXXXXXX',
            'supportEmail' => 'support@mandalhisab.in',
            'apiBaseUrl' => config('app.url') . '/api/v1',
            'features' => [
                'offlineSyncEnabled' => true,
                'biometricSecurityEnabled' => true,
                'whatsappReceiptsEnabled' => false,
                'upiPaymentsEnabled' => true,
                'pdfReceiptsEnabled' => true,
                'signatureCaptureEnabled' => true,
                'multiLanguageEnabled' => true,
                'darkModeEnabled' => true,
            ],
            'limits' => [
                'maxReceiptBooksPerFestival' => 50,
                'maxMembersPerMandal' => 100,
                'maxVarganiPerBatchSync' => 500,
                'maxExpensePerBatchSync' => 200,
            ],
            'defaults' => [
                'currency' => 'INR',
                'currencySymbol' => '₹',
                'language' => 'en',
                'dateFormat' => 'd MMM yyyy',
                'timeFormat' => 'hh:mm a',
            ],
        ];

        return $this->success($config, 'Application configuration');
    }
}
