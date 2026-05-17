<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller implements HasMiddleware
{
	public static function middleware(): array
	{
		return [
			new Middleware('role:super_admin,admin'),
		];
	}

	public function index(Request $request)
	{
		$branchId = $this->branchId($request);

		$query = User::query()->with('branches');

		// Scope to branch: only users assigned to the current branch
		if ($branchId !== null) {
			$query->whereHas('branches', fn($q) => $q->where('branches.id', $branchId));
		}

		if ($request->has('search')) {
			$search = $request->search;
			$query->where(function ($q) use ($search) {
				$q->where('name', 'like', "%{$search}%")
					->orWhere('username', 'like', "%{$search}%")
					->orWhere('email', 'like', "%{$search}%");
			});
		}

		if ($request->has('role')) {
			$query->where('role', $request->role);
		}

		return response()->json($query->latest()->paginate(20));
	}

	public function store(Request $request)
	{
		$contextBranchId = $this->branchId($request);

		$validated = $request->validate([
			'name'      => 'required|string|max:255',
			'username'  => 'required|string|max:255|unique:users',
			'email'     => 'nullable|email|max:255|unique:users',
			'password'  => 'required|string|min:8',
			'role'      => 'required|string|in:super_admin,admin,cashier,staff',
			'branch_id' => 'nullable|exists:branches,id',
		]);

		$assignBranchId = $validated['branch_id'] ?? $contextBranchId;
		unset($validated['branch_id']);

		$validated['password'] = Hash::make($validated['password']);

		$user = User::create($validated);

		if ($assignBranchId !== null) {
			$user->branches()->attach($assignBranchId, ['is_primary' => true]);
		}

		return response()->json($user, 201);
	}

	public function show(Request $request, User $user)
	{
		$this->authorizeBranchAccess($request, $user);

		return response()->json($user->load('branches'));
	}

	public function update(Request $request, User $user)
	{
		$this->authorizeBranchAccess($request, $user);

		$validated = $request->validate([
			'name'     => 'sometimes|string|max:255',
			'username' => ['sometimes', 'string', 'max:255', Rule::unique('users')->ignore($user->id)],
			'email'    => ['nullable', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
			'password' => 'nullable|string|min:8',
			'role'     => 'sometimes|string|in:super_admin,admin,cashier,staff',
		]);

		if (!empty($validated['password'])) {
			$validated['password'] = Hash::make($validated['password']);
		} else {
			unset($validated['password']);
		}

		$user->update($validated);

		return response()->json($user);
	}

	public function destroy(Request $request, User $user)
	{
		$this->authorizeBranchAccess($request, $user);

		if ($user->id === auth()->id()) {
			return response()->json(['message' => 'You cannot delete your own account.'], 403);
		}

		$user->delete();

		return response()->json(['message' => 'User deleted successfully']);
	}

	private function authorizeBranchAccess(Request $request, User $user): void
	{
		$branchId = $this->branchId($request);

		if ($branchId !== null) {
			$inBranch = $user->branches()->where('branches.id', $branchId)->exists();
			abort_if(!$inBranch, 403, 'This user does not belong to your branch.');
		}
	}
}
