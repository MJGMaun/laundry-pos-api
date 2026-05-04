<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ExpenseCategoryController extends Controller implements HasMiddleware
{
	public static function middleware(): array
	{
		return [
			new Middleware('role:admin'),
		];
	}

	public function index()
	{
		return response()->json(ExpenseCategory::orderBy('name')->get());
	}

	public function store(Request $request)
	{
		$validated = $request->validate([
			'name'        => 'required|string|max:255|unique:expense_categories,name',
			'description' => 'nullable|string|max:500',
		]);

		$category = ExpenseCategory::create($validated);

		return response()->json($category, 201);
	}

	public function destroy(ExpenseCategory $expenseCategory)
	{
		if ($expenseCategory->expenses()->exists()) {
			return response()->json([
				'message' => 'Cannot delete a category that has expenses linked to it.',
			], 422);
		}

		$expenseCategory->delete();

		return response()->json(['message' => 'Deleted successfully']);
	}
}
