<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\DeleteAccountRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\RegisterUserRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\AccountDeletionService;
use App\Services\AuthService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class AuthController
{
    use ApiResponse;

    public function __construct(
        protected AuthService $authService,
        protected AccountDeletionService $deletionService,
    ) {}

    /**
     * POST /api/v1/auth/register
     */
    public function register(RegisterUserRequest $request)
    {
        $validated = $request->validated();

        try {
            $result = $this->authService->register($validated);

            return $this->success([
                'accessToken' => $result['access_token'],
                'refreshToken' => $result['refresh_token'],
                'expiresIn' => $result['expires_in'],
                'user' => $result['user'],
            ], 'Account created successfully', 201);
        } catch (\Exception $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        }
    }

    /**
     * POST /api/v1/auth/login
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'usernameOrPhone' => ['required', 'string'],
            'password' => ['required', 'string'],
            'deviceToken' => ['nullable', 'string'],
            'platform' => ['nullable', 'string', 'in:android,ios,web,windows'],
        ]);

        try {
            $result = $this->authService->login($validated);

            return $this->success([
                'accessToken' => $result['access_token'],
                'refreshToken' => $result['refresh_token'],
                'expiresIn' => $result['expires_in'],
                'user' => $result['user'],
            ], 'Login successful');
        } catch (\Exception $e) {
            return $this->error('UNAUTHORIZED', $e->getMessage(), 401);
        }
    }

    /**
     * POST /api/v1/auth/token/refresh
     */
    public function refresh(Request $request)
    {
        $validated = $request->validate([
            'refreshToken' => ['required', 'string'],
        ]);

        try {
            $result = $this->authService->refresh($validated['refreshToken']);

            return $this->success([
                'accessToken' => $result['access_token'],
                'refreshToken' => $result['refresh_token'],
                'expiresIn' => config('jwt.ttl', 60) * 60,
            ], 'Token refreshed');
        } catch (\Exception $e) {
            return $this->error('UNAUTHORIZED', $e->getMessage(), 401);
        }
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request)
    {
        $validated = $request->validate([
            'refreshToken' => ['nullable', 'string'],
            'deviceToken' => ['nullable', 'string'],
            'allSessions' => ['nullable', 'boolean'],
        ]);

        $this->authService->logout(
            $validated['refreshToken'] ?? null,
            $validated['allSessions'] ?? false
        );

        return $this->success(null, 'Logged out successfully');
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return $this->error('UNAUTHORIZED', 'Not authenticated', 401);
        }

        $formatted = $this->authService->formatUser($user);
        $formatted['email'] = $user->email;
        $formatted['avatarUrl'] = $user->avatar_url;

        return $this->success($formatted, 'Profile retrieved');
    }

    /**
     * PUT /api/v1/auth/me
     */
    public function updateMe(Request $request)
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'defaultLanguage' => ['nullable', 'string', 'in:en,mr'],
            'isBiometricEnabled' => ['nullable', 'boolean'],
            'activeFestivalId' => ['nullable', 'string'],
            'avatarBase64' => ['nullable', 'string'],
        ]);

        $user = $request->user();

        try {
            $updated = $this->authService->updateProfile($user, $validated);
        } catch (\InvalidArgumentException $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        }

        return $this->success([
            'id' => $updated->id,
            'name' => $updated->full_name,
            'email' => $updated->email,
            'defaultLanguage' => $updated->default_language,
            'isBiometricEnabled' => $updated->is_biometric_enabled,
            'activeFestivalId' => $updated->active_festival_id,
            'avatarUrl' => $updated->avatar_url,
        ], 'Profile updated');
    }

    /**
     * PUT /api/v1/auth/security-pin
     */
    public function setPin(Request $request)
    {
        $validated = $request->validate([
            'pin' => ['required', 'string', 'digits:4'],
            'currentPassword' => ['required_without:currentPin', 'string'],
            'currentPin' => ['required_without:currentPassword', 'string', 'digits:4'],
        ]);

        $user = $request->user();

        try {
            $this->authService->setPin($user, $validated['pin'], [
                'currentPassword' => $validated['currentPassword'] ?? null,
                'currentPin' => $validated['currentPin'] ?? null,
            ]);

            return $this->success(null, 'PIN set successfully');
        } catch (\Exception $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        }
    }

    /**
     * PUT /api/v1/auth/password
     */
    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:8'],
        ]);

        $user = $request->user();

        try {
            $this->authService->changePassword($user, $validated['currentPassword'], $validated['newPassword']);

            return $this->success(null, 'Password changed successfully');
        } catch (\Exception $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        }
    }

    /**
     * POST /api/v1/auth/forgot-password (public, rate-limited)
     */
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        try {
            $data = $this->authService->forgotPassword($request->validated('usernameOrPhone'));

            return $this->success($data, 'Password reset link sent to your email');
        } catch (\InvalidArgumentException $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error('INTERNAL_ERROR', $e->getMessage(), 500);
        } catch (\Exception $e) {
            return $this->error('NOT_FOUND', $e->getMessage(), 404);
        }
    }

    /**
     * POST /api/v1/auth/reset-password (public, rate-limited)
     */
    public function resetPassword(ResetPasswordRequest $request)
    {
        $validated = $request->validated();

        try {
            $this->authService->resetPassword(
                $validated['token'],
                $validated['newPassword']
            );

            return $this->success(null, 'Password reset successfully');
        } catch (\Exception $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        }
    }

    /**
     * DELETE /api/v1/auth/me
     *
     * Schedule account deletion after the 7-day wait. Requires password.
     */
    public function deleteMe(DeleteAccountRequest $request)
    {
        $user = $request->user();

        try {
            $scheduled = $this->deletionService->scheduleAuthenticatedDeletion(
                $user,
                $request->validated()['password']
            );
        } catch (\Exception $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        }

        return $this->success($scheduled, 'Account deletion scheduled. You have 7 days to cancel.');
    }

    /**
     * POST /api/v1/auth/me/cancel-deletion
     */
    public function cancelDeletion(Request $request)
    {
        $user = $request->user();

        try {
            $this->deletionService->cancelAuthenticatedDeletion($user);
        } catch (\Exception $e) {
            return $this->error('VALIDATION_FAILED', $e->getMessage(), 422);
        }

        return $this->success(null, 'Account deletion cancelled');
    }

    /**
     * POST /api/v1/public/account-deletion-request
     *
     * Public web-portal submission. No auth required.
     * Creates a PENDING deletion request record.
     */
    public function publicAccountDeletionRequest(Request $request)
    {
        $validated = $request->validate([
            'phoneOrUsername' => ['required', 'string', 'max:255'],
            'mandalName' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->deletionService->submitPublicRequest(
            $validated['phoneOrUsername'],
            $validated['mandalName'] ?? null,
            $validated['reason'] ?? null
        );

        return $this->success(null, 'Request submitted');
    }
}
