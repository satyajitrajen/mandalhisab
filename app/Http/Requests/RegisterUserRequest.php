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

        $rawContact = (string) $this->input('mandalContactNumber', '');
        $mandalContact = app(AuthService::class)->extractPhone($rawContact);

        $this->merge([
            'usernameOrPhone' => $phone ?? trim($raw),
            'email' => $this->filled('email')
                ? strtolower(trim((string) $this->input('email')))
                : null,
            'mandalContactNumber' => $mandalContact ?? ($rawContact !== '' ? trim($rawContact) : null),
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
            'mandalContactNumber' => ['nullable', 'string', 'regex:/^[6-9]\d{9}$/'],
            'deviceToken' => ['nullable', 'string'],
            'platform' => ['nullable', 'string', 'in:android,ios,web,windows'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $userPhone = $this->input('usernameOrPhone');
            $mandalContact = $this->input('mandalContactNumber');
            if (! empty($userPhone) && ! empty($mandalContact)) {
                $p1 = substr(preg_replace('/\D/', '', (string) $userPhone), -10);
                $p2 = substr(preg_replace('/\D/', '', (string) $mandalContact), -10);
                if ($p1 !== '' && $p1 === $p2) {
                    $validator->errors()->add(
                        'mandalContactNumber',
                        'Mandal registered contact number cannot be the same as your personal mobile number.'
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'usernameOrPhone.regex' => 'Enter a valid 10-digit Indian mobile number starting with 6, 7, 8, or 9.',
            'usernameOrPhone.unique' => 'This mobile number is already registered.',
            'email.unique' => 'This email is already registered.',
            'mandalContactNumber.regex' => 'Enter a valid 10-digit Indian mobile number for mandal contact.',
        ];
    }
}
