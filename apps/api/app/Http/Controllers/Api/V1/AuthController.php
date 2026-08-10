<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string', 'max:1000'],
            'remember' => ['sometimes', 'boolean'],
        ]);
        $remember = (bool) ($credentials['remember'] ?? false);
        unset($credentials['remember']);

        if (! Auth::guard('web')->attempt($credentials, $remember)) {
            throw ValidationException::withMessages(['email' => 'The provided credentials are invalid.']);
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
