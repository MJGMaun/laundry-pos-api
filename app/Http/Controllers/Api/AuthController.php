<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
	public function register(Request $request)
	{
		$request->validate([
			'name'     => 'required|string|max:255',
			'username' => 'required|string|max:255|unique:users',
			'email'    => 'nullable|string|email|max:255|unique:users',
			'password' => 'required|string|min:8|confirmed',
			'role'     => 'sometimes|string|in:admin,cashier,staff',
		]);

		$user = User::create([
			'name'     => $request->name,
			'username' => $request->username,
			'email'    => $request->email,
			'password' => Hash::make($request->password),
			'role'     => $request->role ?? 'staff',
		]);

		$token = $user->createToken('auth_token')->plainTextToken;

		return response()->json([
			'access_token' => $token,
			'token_type' => 'Bearer',
			'user' => $user
		]);
	}

	public function login(Request $request)
	{
		$request->validate([
			'username' => 'required|string',
			'password' => 'required',
		]);

		$user = User::where('username', $request->username)->first();

		if (!$user || !Hash::check($request->password, $user->password)) {
			throw ValidationException::withMessages([
				'username' => ['The provided credentials are incorrect.'],
			]);
		}

		$token = $user->createToken('auth_token')->plainTextToken;

		return response()->json([
			'access_token' => $token,
			'token_type' => 'Bearer',
			'user' => $user->load(['branches' => function ($q) {
				$q->withPivot('is_primary');
			}])
		]);
	}

	public function logout(Request $request)
	{
		$request->user()->currentAccessToken()->delete();

		return response()->json(['message' => 'Logged out successfully']);
	}
}
