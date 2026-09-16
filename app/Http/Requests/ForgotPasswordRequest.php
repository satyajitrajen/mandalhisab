<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $value = trim((string) $this->input('usernameOrPhone', ''));
        if (str_contains($value, '@')) {
            $value = strtolower($value);
        }

        $this->merge(['usernameOrPhone' => $value]);
    }

    public function rules(): array
    {
        return [
            'usernameOrPhone' => ['required', 'string', 'max:255'],
        ];
    }
}
