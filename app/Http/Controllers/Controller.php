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

	/**
	 * Today's calendar date in the shop's timezone (Philippines, UTC+8, no DST).
	 * Timestamps are stored in UTC, so "today" must be resolved in business time.
	 */
	protected function businessToday(): string
	{
		return now()->setTimezone('Asia/Manila')->toDateString();
	}

	/** SQL: a UTC datetime column shifted to business time (+08:00). */
	protected function bizTime(string $column): string
	{
		return "CONVERT_TZ($column, '+00:00', '+08:00')";
	}

	/** SQL: the business-time calendar DATE of a UTC datetime column. */
	protected function bizDateExpr(string $column): string
	{
		return 'DATE(' . $this->bizTime($column) . ')';
	}
}
