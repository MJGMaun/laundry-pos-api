<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
	/**
	 * Returns the branch ID from the resolved branch context.
	 * null = no filter (super admin viewing all branches).
	 */
	protected function branchId(Request $request): ?int
	{
		return $request->attributes->get('branch')?->id;
	}

	/**
	 * Applies WHERE branch_id = ? when a branch context exists.
	 * If branch is null (super admin, no filter), returns query unmodified.
	 */
	protected function scopeToBranch($query, Request $request): mixed
	{
		$id = $this->branchId($request);

		return $id !== null ? $query->where('branch_id', $id) : $query;
	}
}
