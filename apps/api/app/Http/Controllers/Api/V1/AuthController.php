<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\MultiFactorAuthenticationService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request, MultiFactorAuthenticationService $multiFactor): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string', 'max:1000'],
            'remember' => ['sometimes', 'boolean'],
            'mfa_code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:100'],
        ]);
        $remember = (bool) ($credentials['remember'] ?? false);
        $mfaCode = $credentials['mfa_code'] ?? null;
        $recoveryCode = $credentials['recovery_code'] ?? null;
        unset($credentials['remember'], $credentials['mfa_code'], $credentials['recovery_code']);

        if (! Auth::guard('web')->attempt($credentials, $remember)) {
            throw ValidationException::withMessages(['email' => 'The provided credentials are invalid.']);
        }

        if (! Auth::guard('web')->user()?->hasVerifiedEmail()) {
            Auth::guard('web')->logout();
            throw ValidationException::withMessages(['email' => 'Verify your email address before signing in.']);
        }

        $user = Auth::guard('web')->user();
        if ($user !== null && $multiFactor->enabled($user) && ! $multiFactor->verify($user, $mfaCode, $recoveryCode)) {
            Auth::guard('web')->logout();

            return response()->json([
                'message' => 'Multi-factor authentication is required.',
                'mfa_required' => true,
                'errors' => ['mfa_code' => ['Enter a valid authenticator or recovery code.']],
            ], 422);
        }

        $request->session()->regenerate();

        return $this->userPayload($request);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->userPayload($request);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Signed out.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc']]);
        Password::sendResetLink($data);

        return response()->json(['message' => 'If the account exists, a password reset link is on its way.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        $status = Password::reset($data, function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();
            event(new PasswordReset($user));
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => 'Password reset. You can now sign in.']);
    }

    private function userPayload(Request $request): JsonResponse
    {
        $user = $request->user() ?? Auth::guard('web')->user();

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'tenants' => $user->tenants()->where('tenants.is_active', true)->get(['tenants.id', 'tenants.name', 'tenants.slug']),
        ]]);
    }
}
