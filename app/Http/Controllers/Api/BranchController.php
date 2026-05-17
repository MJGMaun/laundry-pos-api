<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller implements HasMiddleware
{
	public static function middleware(): array
	{
		return [
			new Middleware('role:super_admin', only: ['store', 'update', 'destroy']),
			new Middleware('role:admin',       only: ['index', 'show', 'users', 'assignUser', 'removeUser', 'branchServices', 'createBranchService', 'updateBranchService', 'deleteBranchService']),
		];
	}

	// Ensures an admin belongs to the branch they're trying to manage.
	// super_admin bypasses this check entirely (already passed middleware).
	private function authorizeAdminForBranch(Branch $branch): void
	{
		$user = auth()->user();
		if (!$user->isSuperAdmin() && !$branch->users()->where('users.id', $user->id)->exists()) {
			abort(403, 'You are not assigned to this branch.');
		}
	}

	// GET /api/branches
	public function index()
	{
		return response()->json(Branch::withCount('users')->latest()->get());
	}

	// POST /api/branches
	public function store(Request $request)
	{
		$validated = $request->validate([
			'name'    => 'required|string|max:255',
			'address' => 'nullable|string|max:500',
			'phone'   => 'nullable|string|max:20',
			'email'   => 'nullable|email|max:255',
			'tin'     => 'nullable|string|max:50',
		]);

		$branch = Branch::create($validated);

		Setting::create([
			'key'       => 'shop_name',
			'value'     => $branch->name,
			'group'     => 'shop',
			'branch_id' => $branch->id,
		]);

		return response()->json($branch, 201);
	}

	// PUT /api/branches/{branch}
	public function update(Request $request, Branch $branch)
	{
		$validated = $request->validate([
			'name'      => 'sometimes|string|max:255',
			'address'   => 'sometimes|nullable|string|max:500',
			'phone'     => 'sometimes|nullable|string|max:20',
			'email'     => 'sometimes|nullable|email|max:255',
			'tin'       => 'sometimes|nullable|string|max:50',
			'is_active' => 'sometimes|boolean',
		]);

		$branch->fill($validated)->save();

		// Keep shop_name setting in sync with branch name
		if (isset($validated['name'])) {
			Setting::updateOrCreate(
				['key' => 'shop_name', 'branch_id' => $branch->id],
				['value' => $validated['name'], 'group' => 'shop']
			);
		}

		return response()->json($branch);
	}

	// DELETE /api/branches/{branch} — deactivates, does not hard delete
	public function destroy(Branch $branch)
	{
		$branch->is_active = false;
		$branch->save();

		return response()->json(['message' => 'Branch deactivated successfully.']);
	}

	// GET /api/branches/{branch}/users
	public function users(Branch $branch)
	{
		$this->authorizeAdminForBranch($branch);
		$users = $branch->users()->select('users.id', 'users.name', 'users.username', 'users.email', 'users.role')
			->get()
			->map(fn($u) => [
				'id'         => $u->id,
				'name'       => $u->name,
				'username'   => $u->username,
				'email'      => $u->email,
				'role'       => $u->role,
				'is_primary' => (bool) $u->pivot->is_primary,
			]);

		return response()->json(['data' => $users]);
	}

	// POST /api/branches/{branch}/users
	public function assignUser(Request $request, Branch $branch)
	{
		$this->authorizeAdminForBranch($branch);
		$validated = $request->validate([
			'user_id'    => 'required|exists:users,id',
			'is_primary' => 'boolean',
		]);

		$userId    = $validated['user_id'];
		$isPrimary = $validated['is_primary'] ?? false;

		DB::transaction(function () use ($branch, $userId, $isPrimary) {
			// If setting as primary, clear existing primary for this user
			if ($isPrimary) {
				DB::table('branch_user')
					->where('user_id', $userId)
					->update(['is_primary' => false]);
			}

			$branch->users()->syncWithoutDetaching([
				$userId => ['is_primary' => $isPrimary],
			]);
		});

		return response()->json(['message' => 'User assigned to branch.']);
	}

	// DELETE /api/branches/{branch}/users/{user}
	public function removeUser(Branch $branch, User $user)
	{
		$this->authorizeAdminForBranch($branch);

		$branch->users()->detach($user->id);

		return response()->json(['message' => 'User removed from branch.']);
	}

	// GET /api/branches/{branch}/services
	public function branchServices(Branch $branch)
	{
		$this->authorizeAdminForBranch($branch);
		$services = $branch->services()->whereNull('deleted_at')->get();

		return response()->json(['data' => $services]);
	}

	// POST /api/branches/{branch}/services
	public function createBranchService(Request $request, Branch $branch)
	{
		$this->authorizeAdminForBranch($branch);
		$validated = $request->validate([
			'name'         => 'required|string|max:255',
			'pricing_type' => 'required|in:per_kilo,per_piece,flat_rate',
			'price'        => 'required|numeric|min:0',
			'is_active'    => 'sometimes|boolean',
		]);

		$service = $branch->services()->create($validated);

		return response()->json($service, 201);
	}

	// PUT /api/branches/{branch}/services/{service}
	public function updateBranchService(Request $request, Branch $branch, Service $service)
	{
		$this->authorizeAdminForBranch($branch);
		if ((int) $service->branch_id !== (int) $branch->id) {
			return response()->json(['message' => 'Service not found in this branch.'], 404);
		}

		$validated = $request->validate([
			'name'         => 'sometimes|string|max:255',
			'pricing_type' => 'sometimes|in:per_kilo,per_piece,flat_rate',
			'price'        => 'sometimes|numeric|min:0',
			'is_active'    => 'sometimes|boolean',
		]);

		$service->fill($validated)->save();

		return response()->json($service);
	}

	// DELETE /api/branches/{branch}/services/{service}
	public function deleteBranchService(Branch $branch, Service $service)
	{
		$this->authorizeAdminForBranch($branch);
		if ((int) $service->branch_id !== (int) $branch->id) {
			return response()->json(['message' => 'Service not found in this branch.'], 404);
		}

		$service->delete();

		return response()->json(['message' => 'Service removed from branch.']);
	}
}
