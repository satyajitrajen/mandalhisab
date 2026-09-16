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
            'minSupportedVersion' => config('app.min_supported_version', '1.0.0'),
            'latestVersion' => config('app.latest_version', '1.0.0'),
            'forceUpdate' => (bool) config('app.force_update', false),
            'maintenanceMode' => false,
            'maintenanceMessage' => null,
            'supportPhone' => '+91-XXXXXXXXXX',
            'supportEmail' => 'support@mandalhisab.in',
            'apiBaseUrl' => config('app.url').'/api/v1',
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
            'razorpay' => [
                'keyId' => config('services.razorpay.key_id'),
                'registrationAmountPaise' => (int) config('services.razorpay.registration_amount_paise', 10100),
                'currency' => config('services.razorpay.currency', 'INR'),
            ],
            'fcm' => [
                'enabled' => (bool) filter_var(config('services.fcm.enabled', false), FILTER_VALIDATE_BOOLEAN),
                'projectId' => config('services.fcm.project_id'),
                'credentialsPresent' => is_file(base_path((string) config('services.fcm.credentials', ''))),
            ],
        ];

        return $this->success($config, 'Application configuration');
    }
}
