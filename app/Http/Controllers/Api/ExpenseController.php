<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller implements HasMiddleware
{
	public static function middleware(): array
	{
		return [
			new Middleware('role:admin'),
		];
	}

	public function index(Request $request)
	{
		$query = Expense::with('category', 'user');

		if ($request->filled('category_id')) {
			$query->where('expense_category_id', $request->category_id);
		}

		// ?month=2026-04 shorthand
		if ($request->filled('month')) {
			$query->whereYear('expense_date', substr($request->month, 0, 4))
				->whereMonth('expense_date', substr($request->month, 5, 2));
		} else {
			if ($request->filled('date_from')) {
				$query->whereDate('expense_date', '>=', $request->date_from);
			}

			if ($request->filled('date_to')) {
				$query->whereDate('expense_date', '<=', $request->date_to);
			}
		}

		$expenses = $query->orderBy('expense_date', 'desc')->paginate(20);

		// Monthly totals for the same filters (without pagination)
		$totalsQuery = Expense::query();

		if ($request->filled('category_id')) {
			$totalsQuery->where('expense_category_id', $request->category_id);
		}

		if ($request->filled('month')) {
			$totalsQuery->whereYear('expense_date', substr($request->month, 0, 4))
				->whereMonth('expense_date', substr($request->month, 5, 2));
		} else {
			if ($request->filled('date_from')) {
				$totalsQuery->whereDate('expense_date', '>=', $request->date_from);
			}

			if ($request->filled('date_to')) {
				$totalsQuery->whereDate('expense_date', '<=', $request->date_to);
			}
		}

		$monthlyTotals = $totalsQuery
			->selectRaw("DATE_FORMAT(expense_date, '%Y-%m') as month, SUM(amount) as total")
			->groupByRaw("DATE_FORMAT(expense_date, '%Y-%m')")
			->orderBy('month', 'desc')
			->get();

		return response()->json([
			'expenses'       => $expenses,
			'monthly_totals' => $monthlyTotals,
		]);
	}

	public function store(Request $request)
	{
		$validated = $request->validate([
			'expense_category_id' => 'required|exists:expense_categories,id',
			'amount'              => 'required|numeric|min:0.01',
			'expense_date'        => 'required|date',
			'description'         => 'nullable|string|max:500',
			'receipt_reference'   => 'nullable|string|max:255',
		]);

		$validated['user_id'] = $request->user()->id;

		$expense = Expense::create($validated);

		return response()->json($expense->load('category', 'user'), 201);
	}

	public function update(Request $request, Expense $expense)
	{
		$validated = $request->validate([
			'expense_category_id' => 'sometimes|exists:expense_categories,id',
			'amount'              => 'sometimes|numeric|min:0.01',
			'expense_date'        => 'sometimes|date',
			'description'         => 'sometimes|nullable|string|max:500',
			'receipt_reference'   => 'sometimes|nullable|string|max:255',
		]);

		$expense->fill($validated)->save();

		return response()->json($expense->load('category', 'user'));
	}

	public function destroy(Expense $expense)
	{
		$expense->delete();

		return response()->json(['message' => 'Deleted successfully']);
	}
}
