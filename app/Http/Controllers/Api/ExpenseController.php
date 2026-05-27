<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

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
		$branchId = $this->branchId($request);

		$applyFilters = function ($query) use ($request, $branchId) {
			if ($branchId !== null) {
				$query->where('branch_id', $branchId);
			}

			if ($request->filled('category_id')) {
				$query->where('expense_category_id', $request->category_id);
			}

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
		};

		$expensesQuery = Expense::with('category', 'user');
		$applyFilters($expensesQuery);
		$expenses = $expensesQuery->orderBy('expense_date', 'desc')->paginate(20);

		$totalsQuery = Expense::query();
		$applyFilters($totalsQuery);
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
			'payment_method'      => 'nullable|in:cash,gcash',
			'expense_date'        => 'required|date',
			'description'         => 'nullable|string|max:500',
			'receipt_reference'   => 'nullable|string|max:255',
		]);

		$validated['payment_method'] = $validated['payment_method'] ?? 'cash';
		$validated['user_id']        = $request->user()->id;
		$validated['branch_id']      = $this->branchId($request);

		return response()->json(
			Expense::create($validated)->load('category', 'user'),
			201
		);
	}

	public function update(Request $request, Expense $expense)
	{
		$validated = $request->validate([
			'expense_category_id' => 'sometimes|exists:expense_categories,id',
			'amount'              => 'sometimes|numeric|min:0.01',
			'payment_method'      => 'sometimes|nullable|in:cash,gcash',
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
