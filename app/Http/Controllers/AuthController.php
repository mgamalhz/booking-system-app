<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, AuthService $authService): JsonResponse
    {
        $auth = $authService->register($request->validated());

        return response()->json([
            'customer' => $auth['customer'],
            'success' => true,
            'token' => $auth['token'],
        ], 201);
    }

    public function login(LoginRequest $request, AuthService $authService): JsonResponse
    {
        $auth = $authService->login($request->validated());

        return response()->json([
            'customer' => $auth['customer'],
            'success' => true,
            'token' => $auth['token'],
        ]);
    }
}
