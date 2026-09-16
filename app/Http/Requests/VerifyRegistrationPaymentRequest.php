<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyRegistrationPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'razorpayOrderId' => ['required', 'string', 'max:64'],
            'razorpayPaymentId' => ['required', 'string', 'max:64'],
            'razorpaySignature' => ['required', 'string', 'max:128'],
        ];
    }
}
