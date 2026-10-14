<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyApplePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'transactionId' => ['required', 'string', 'max:64'],
            'mandalId' => ['nullable', 'string', 'max:32'],
        ];
    }
}
