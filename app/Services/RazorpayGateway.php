<?php

namespace App\Services;

use Razorpay\Api\Api;
use Throwable;

class RazorpayGateway
{
    /**
     * Create a Razorpay order. Amount is always in paise.
     *
     * @return array{id: string, amount: int, currency: string, status: string}
     */
    public function createOrder(int $amountPaise, string $currency, string $receipt): array
    {
        $keyId = (string) config('services.razorpay.key_id');
        $keySecret = (string) config('services.razorpay.key_secret');

        if ($keyId === '' || $keySecret === '') {
            throw new \RuntimeException('Razorpay is not configured');
        }

        try {
            $api = new Api($keyId, $keySecret);
            $order = $api->order->create([
                'amount' => $amountPaise,
                'currency' => $currency,
                'receipt' => $receipt,
                'payment_capture' => 1,
            ]);
        } catch (Throwable $e) {
            throw new \RuntimeException('Razorpay order creation failed: '.$e->getMessage(), 0, $e);
        }

        $payload = is_array($order) ? $order : $order->toArray();

        return [
            'id' => (string) ($payload['id'] ?? ''),
            'amount' => (int) ($payload['amount'] ?? $amountPaise),
            'currency' => (string) ($payload['currency'] ?? $currency),
            'status' => (string) ($payload['status'] ?? 'created'),
        ];
    }
}
