<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetBranchContext
{
	public function handle(Request $request, Closure $next): Response
	{
		$user = $request->user();

		if (!$user) {
			return $next($request);
		}

		$branchId = $request->header('X-Branch-Id') ?? $request->query('branch_id');

		// Super admin: optional — omit to see all, or pass a branch_id to scope down
		if ($user->isSuperAdmin()) {
			$branch = null;

			if ($branchId) {
				$branch = Branch::find($branchId);
				if (!$branch) {
					return response()->json(['message' => 'Branch not found.'], 404);
				}
			}

			$request->attributes->set('branch', $branch);
			return $next($request);
		}

		// Regular users: validate against their assigned branches
		if ($branchId) {
			$branch = $user->branches()->where('branches.id', $branchId)->first();

			if (!$branch) {
				return response()->json(['message' => 'You are not assigned to this branch.'], 403);
			}

			$request->attributes->set('branch', $branch);
			return $next($request);
		}

		// No header — auto-resolve from assigned branches
		$branches = $user->branches;

		if ($branches->count() === 1) {
			$request->attributes->set('branch', $branches->first());
		} elseif ($branches->count() > 1) {
			return response()->json([
				'message'  => 'X-Branch-Id header is required. You are assigned to multiple branches.',
				'branches' => $branches->map(fn($b) => ['id' => $b->id, 'name' => $b->name]),
			], 422);
		} else {
			// No branch assigned yet (initial setup) — no scoping applied
			$request->attributes->set('branch', null);
		}

		return $next($request);
	}
}
