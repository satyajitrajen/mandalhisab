<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class AppleGateway
{
    protected const PRODUCTION_URL = 'https://api.storekit.itunes.apple.com';

    protected const SANDBOX_URL = 'https://api.storekit-sandbox.itunes.apple.com';

    public function isConfigured(): bool
    {
        return (bool) filter_var(config('services.apple_iap.enabled', false), FILTER_VALIDATE_BOOLEAN)
            && (string) config('services.apple_iap.bundle_id') !== ''
            && (string) config('services.apple_iap.issuer_id') !== ''
            && (string) config('services.apple_iap.key_id') !== ''
            && (string) config('services.apple_iap.private_key_path') !== '';
    }

    /**
     * Fetch a transaction from the App Store Server API (authoritative source).
     *
     * @return array{transactionId: string, originalTransactionId: string, productId: string, bundleId: string, environment: string, purchaseDate: ?Carbon, expiresDate: ?Carbon, revocationDate: ?Carbon, price: ?int, currency: ?string}
     */
    public function fetchTransaction(string $transactionId): array
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('Apple IAP is not configured');
        }

        try {
            $response = Http::withToken($this->apiToken())
                ->acceptJson()
                ->timeout(15)
                ->get($this->baseUrl().'/inApps/v1/transactions/'.rawurlencode($transactionId));
        } catch (Throwable $e) {
            throw new \RuntimeException('Apple transaction lookup failed: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new \RuntimeException('Apple transaction lookup failed with HTTP '.$response->status());
        }

        $signed = (string) $response->json('signedTransactionInfo', '');
        $info = self::decodeJwsPayload($signed);
        if ($info === null) {
            throw new \RuntimeException('Apple returned an unreadable transaction');
        }

        return [
            'transactionId' => (string) ($info['transactionId'] ?? ''),
            'originalTransactionId' => (string) ($info['originalTransactionId'] ?? $info['transactionId'] ?? ''),
            'productId' => (string) ($info['productId'] ?? ''),
            'bundleId' => (string) ($info['bundleId'] ?? ''),
            'environment' => (string) ($info['environment'] ?? ''),
            'purchaseDate' => self::msToDate($info['purchaseDate'] ?? null),
            'expiresDate' => self::msToDate($info['expiresDate'] ?? null),
            'revocationDate' => self::msToDate($info['revocationDate'] ?? null),
            'price' => isset($info['price']) ? (int) $info['price'] : null,
            'currency' => isset($info['currency']) ? (string) $info['currency'] : null,
        ];
    }

    /**
     * Read the payload of an Apple JWS without verifying it. Only use on data
     * fetched from Apple over TLS, or to extract an ID that is then re-fetched.
     */
    public static function decodeJwsPayload(string $jws): ?array
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            return null;
        }

        try {
            $decoded = json_decode(JWT::urlsafeB64Decode($parts[1]), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    public function environment(): string
    {
        return config('services.apple_iap.environment') === 'production' ? 'Production' : 'Sandbox';
    }

    protected function baseUrl(): string
    {
        return $this->environment() === 'Production' ? self::PRODUCTION_URL : self::SANDBOX_URL;
    }

    protected function apiToken(): string
    {
        $path = (string) config('services.apple_iap.private_key_path');
        $fullPath = str_starts_with($path, '/') ? $path : base_path($path);
        $key = is_file($fullPath) ? file_get_contents($fullPath) : false;

        if ($key === false || $key === '') {
            throw new \RuntimeException('Apple IAP private key is missing');
        }

        $now = time();

        return JWT::encode([
            'iss' => (string) config('services.apple_iap.issuer_id'),
            'iat' => $now,
            'exp' => $now + 1800,
            'aud' => 'appstoreconnect-v1',
            'bid' => (string) config('services.apple_iap.bundle_id'),
        ], $key, 'ES256', (string) config('services.apple_iap.key_id'));
    }

    protected static function msToDate(mixed $ms): ?Carbon
    {
        return is_numeric($ms) ? Carbon::createFromTimestampMs((int) $ms) : null;
    }
}
