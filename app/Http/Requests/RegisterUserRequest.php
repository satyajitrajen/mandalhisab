<?php

namespace App\Http\Requests;

use App\Services\AuthService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $raw = (string) $this->input('usernameOrPhone', '');
        $phone = app(AuthService::class)->extractPhone($raw);

        $this->merge([
            'usernameOrPhone' => $phone ?? trim($raw),
            'email' => $this->filled('email')
                ? strtolower(trim((string) $this->input('email')))
                : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'fullName' => ['required', 'string', 'min:2', 'max:80'],
            'usernameOrPhone' => [
                'required',
                'string',
                'regex:/^[6-9]\d{9}$/',
                Rule::unique('users', 'phone'),
            ],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'mandalName' => ['nullable', 'string', 'max:255'],
            'deviceToken' => ['nullable', 'string'],
            'platform' => ['nullable', 'string', 'in:android,ios,web,windows'],
        ];
    }

    public function messages(): array
    {
        return [
            'usernameOrPhone.regex' => 'Enter a valid 10-digit Indian mobile number starting with 6, 7, 8, or 9.',
            'usernameOrPhone.unique' => 'This mobile number is already registered.',
            'email.unique' => 'This email is already registered.',
        ];
    }
}
