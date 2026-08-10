<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchPageAccess;
use App\Services\PageAccessService;
use App\Support\PageRegistry;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PageAccessController extends Controller implements HasMiddleware
{
	public function __construct(private PageAccessService $access)
	{
	}

	public static function middleware(): array
	{
		return [
			// Editing another branch's matrix is a super-admin act; reading your
			// own is not — every role needs it to build its menu.
			new Middleware('role:super_admin', only: ['show', 'update']),
		];
	}

	/**
	 * GET /api/my-page-access
	 * What the signed-in user may reach at the active branch. Drives the
	 * sidebar and router guard, and is cached client-side for offline use.
	 */
	public function mine(Request $request)
	{
		$user = $request->user();

		return response()->json([
			'branch_id' => $this->branchId($request),
			'role'      => $user->role,
			'pages'     => $this->access->matrix($this->branchId($request), $user->role),
		]);
	}

	/**
	 * GET /api/branches/{branch}/page-access
	 * The full editable matrix for one branch, with the registry metadata the
	 * admin screen needs to render it.
	 */
	public function show(Branch $branch)
	{
		$roles = [];
		foreach (PageRegistry::ROLES as $role) {
			$roles[$role] = $this->access->matrix($branch->id, $role);
		}

		$pages = [];
		foreach (PageRegistry::all() as $key => $meta) {
			$pages[] = [
				'key'          => $key,
				'label'        => $meta['label'],
				'group'        => $meta['group'],
				'configurable' => $meta['configurable'],
			];
		}

		return response()->json([
			'branch_id' => $branch->id,
			'pages'     => $pages,
			'roles'     => $roles,
		]);
	}

	/**
	 * PUT /api/branches/{branch}/page-access
	 * Upserts one page/role cell. Storing a row that matches the shipped
	 * default would be noise, so that case deletes the override instead.
	 */
	public function update(Request $request, Branch $branch)
	{
		$validated = $request->validate([
			'page'     => ['required', 'string', 'in:' . implode(',', PageRegistry::keys())],
			'role'     => ['required', 'string', 'in:' . implode(',', PageRegistry::ROLES)],
			'can_view' => ['required', 'boolean'],
			'can_edit' => ['required', 'boolean'],
		]);

		if (! PageRegistry::isConfigurable($validated['page'])) {
			return response()->json([
				'message' => 'This page is super-admin only and cannot be granted per branch.',
			], 422);
		}

		$canView = $validated['can_view'];
		// Edit implies view: granting edit alone would be a state the guards
		// would then have to keep defending against.
		$canEdit = $canView && $validated['can_edit'];

		$default = PageRegistry::default($validated['page'], $validated['role']);

		if ($default['view'] === $canView && $default['edit'] === $canEdit) {
			BranchPageAccess::where('branch_id', $branch->id)
				->where('page', $validated['page'])
				->where('role', $validated['role'])
				->delete();
		} else {
			BranchPageAccess::updateOrCreate(
				[
					'branch_id' => $branch->id,
					'page'      => $validated['page'],
					'role'      => $validated['role'],
				],
				['can_view' => $canView, 'can_edit' => $canEdit]
			);
		}

		return $this->show($branch);
	}
}
