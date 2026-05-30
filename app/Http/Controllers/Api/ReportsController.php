<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class ReportsController extends Controller implements HasMiddleware
{
	public static function middleware(): array
	{
		return [
			new Middleware('role:super_admin', only: ['branchComparison']),
			new Middleware('role:admin'),
		];
	}

	// GET /api/reports/sales-summary?date=2026-05-04
	public function salesSummary(Request $request)
	{
		$date     = $request->input('date', today()->toDateString());
		$branchId = $this->branchId($request);

		// Orders recognized today (accrual basis): the full order amount counts
		// on the day the order is created, regardless of when/whether it's paid.
		$ordersQuery = Order::whereDate('created_at', $date);

		if ($branchId) {
			$ordersQuery->where('branch_id', $branchId);
		} else {
			$ordersQuery->whereNotIn('branch_id', Branch::where('is_test', true)->pluck('id'));
		}

		$orderIds      = $ordersQuery->pluck('id');
		$revenue       = Order::whereIn('id', $orderIds)->sum('total_amount');
		$orderCount    = $orderIds->count();
		$avgOrderValue = $orderCount > 0 ? round($revenue / $orderCount, 2) : 0;

		// Uncollected: pending/ready/claimed orders not yet fully paid — counting
		// only the outstanding balance (total minus what's already been paid), so
		// a fully-paid order drops to zero and partial payments reduce it.
		$uncollectedQuery = Order::whereDate('created_at', $date)
			->whereIn('status', ['pending', 'ready', 'claimed'])
			->whereRaw($this->unpaidCondition());

		if ($branchId) {
			$uncollectedQuery->where('branch_id', $branchId);
		} else {
			$uncollectedQuery->whereNotIn('branch_id', Branch::where('is_test', true)->pluck('id'));
		}

		$uncollectedRevenue = (float) $uncollectedQuery
			->selectRaw($this->outstandingBalanceSum())
			->value('outstanding');

		$topService = DB::table('loads')
			->whereIn('order_id', $orderIds)
			->whereNull('deleted_at')
			->selectRaw('service_name_snapshot, SUM(line_total) as revenue')
			->groupBy('service_name_snapshot')
			->orderByDesc('revenue')
			->first();

		// Loads today: total laundry loads (sum of quantity) across today's orders.
		$loadCount = (int) DB::table('loads')
			->whereIn('order_id', $orderIds)
			->whereNull('deleted_at')
			->sum('quantity');

		return response()->json([
			'date'                => $date,
			'branch_id'           => $branchId,
			'revenue'             => round($revenue, 2),
			'uncollected_revenue' => round($uncollectedRevenue, 2),
			'order_count'         => $orderCount,
			'load_count'          => $loadCount,
			'avg_order_value'     => $avgOrderValue,
			'top_service'         => $topService ? [
				'name'    => $topService->service_name_snapshot,
				'revenue' => $topService->revenue,
			] : null,
		]);
	}

	// GET /api/reports/revenue?period=monthly&date_from=&date_to=
	public function revenue(Request $request)
	{
		$period   = $request->input('period', 'monthly');
		$branchId = $this->branchId($request);

		$dateFrom = $request->input('date_from', match ($period) {
			'daily'  => now()->subDays(29)->toDateString(),
			'weekly' => now()->subWeeks(11)->startOfWeek()->toDateString(),
			default  => now()->subMonths(11)->startOfMonth()->toDateString(),
		});

		$dateTo = $request->input('date_to', today()->toDateString());

		$format = match ($period) {
			'daily'  => '%Y-%m-%d',
			'weekly' => '%x-W%v',
			default  => '%Y-%m',
		};

		$query = Order::whereDate('created_at', '>=', $dateFrom)
			->whereDate('created_at', '<=', $dateTo);

		if ($branchId) {
			$query->where('branch_id', $branchId);
		} else {
			$query->whereNotIn('branch_id', Branch::where('is_test', true)->pluck('id'));
		}

		$data = $query
			->selectRaw("DATE_FORMAT(created_at, '{$format}') as period, COUNT(*) as order_count, SUM(total_amount) as revenue")
			->groupByRaw("DATE_FORMAT(created_at, '{$format}')")
			->orderBy('period')
			->get();

		return response()->json([
			'period'    => $period,
			'date_from' => $dateFrom,
			'date_to'   => $dateTo,
			'branch_id' => $branchId,
			'data'      => $data,
		]);
	}

	// GET /api/reports/top-customers?limit=10&date_from=&date_to=
	public function topCustomers(Request $request)
	{
		$limit    = min((int) $request->input('limit', 10), 50);
		$branchId = $this->branchId($request);

		$query = DB::table('orders')
			->join('customers', 'orders.customer_id', '=', 'customers.id')
			->whereNull('orders.deleted_at')
			->whereNull('customers.deleted_at');

		if ($branchId) {
			$query->where('orders.branch_id', $branchId);
		} else {
			$query->whereNotIn('orders.branch_id', Branch::where('is_test', true)->pluck('id'));
		}

		if ($request->filled('date_from')) {
			$query->whereDate('orders.created_at', '>=', $request->date_from);
		}

		if ($request->filled('date_to')) {
			$query->whereDate('orders.created_at', '<=', $request->date_to);
		}

		$customers = $query
			->selectRaw('customers.id, customers.name, customers.phone, COUNT(orders.id) as total_visits, SUM(orders.total_amount) as total_spent')
			->groupBy('customers.id', 'customers.name', 'customers.phone')
			->orderByDesc('total_spent')
			->limit($limit)
			->get();

		return response()->json(['data' => $customers]);
	}

	// GET /api/reports/services?month=2026-04
	public function services(Request $request)
	{
		$branchId = $this->branchId($request);

		$query = DB::table('loads')
			->join('orders', 'loads.order_id', '=', 'orders.id')
			->whereNull('loads.deleted_at')
			->whereNull('orders.deleted_at');

		if ($branchId) {
			$query->where('orders.branch_id', $branchId);
		} else {
			$query->whereNotIn('orders.branch_id', Branch::where('is_test', true)->pluck('id'));
		}

		$this->applyDateFilters($query, $request, 'orders.created_at');

		$data = $query
			->selectRaw('service_name_snapshot as service_name, SUM(loads.quantity) as total_quantity, SUM(loads.line_total) as total_revenue, COUNT(DISTINCT orders.id) as order_count')
			->groupBy('service_name_snapshot')
			->orderByDesc('total_revenue')
			->get();

		return response()->json(['data' => $data]);
	}

	// GET /api/reports/profit-loss?month=2026-04
	public function profitLoss(Request $request)
	{
		[$dateFrom, $dateTo] = $this->resolveDateRange($request);
		$branchId = $this->branchId($request);

		// Revenue: accrual — every order recognized in the period, regardless of payment
		$revenueQuery = Order::whereDate('created_at', '>=', $dateFrom)
			->whereDate('created_at', '<=', $dateTo);

		if ($branchId) {
			$revenueQuery->where('branch_id', $branchId);
		} else {
			$revenueQuery->whereNotIn('branch_id', Branch::where('is_test', true)->pluck('id'));
		}

		$revenue = $revenueQuery->sum('total_amount');

		// Uncollected: pending/ready/claimed orders not yet fully paid — only the
		// outstanding balance (total minus what's already been paid).
		$uncollectedQuery = Order::whereIn('status', ['pending', 'ready', 'claimed'])
			->whereRaw($this->unpaidCondition())
			->whereDate('created_at', '>=', $dateFrom)
			->whereDate('created_at', '<=', $dateTo);

		if ($branchId) {
			$uncollectedQuery->where('branch_id', $branchId);
		} else {
			$uncollectedQuery->whereNotIn('branch_id', Branch::where('is_test', true)->pluck('id'));
		}

		$uncollectedRevenue = (float) $uncollectedQuery
			->selectRaw($this->outstandingBalanceSum())
			->value('outstanding');

		$testBranchIds = Branch::where('is_test', true)->pluck('id');

		$expenseQuery = Expense::whereDate('expense_date', '>=', $dateFrom)
			->whereDate('expense_date', '<=', $dateTo);

		if ($branchId) {
			$expenseQuery->where('branch_id', $branchId);
		} else {
			$expenseQuery->whereNotIn('branch_id', $testBranchIds);
		}

		$expenseTotal = $expenseQuery->sum('amount');

		$categoryQuery = DB::table('expenses')
			->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
			->whereDate('expenses.expense_date', '>=', $dateFrom)
			->whereDate('expenses.expense_date', '<=', $dateTo);

		if ($branchId) {
			$categoryQuery->where('expenses.branch_id', $branchId);
		} else {
			$categoryQuery->whereNotIn('expenses.branch_id', $testBranchIds);
		}

		$expensesByCategory = $categoryQuery
			->selectRaw('expense_categories.name as category, SUM(expenses.amount) as total')
			->groupBy('expense_categories.name')
			->orderByDesc('total')
			->get();

		$netProfit    = $revenue - $expenseTotal;
		$profitMargin = $revenue > 0 ? round(($netProfit / $revenue) * 100, 2) : 0;

		return response()->json([
			'date_from'           => $dateFrom,
			'date_to'             => $dateTo,
			'branch_id'           => $branchId,
			'revenue'             => round($revenue, 2),
			'uncollected_revenue' => round($uncollectedRevenue, 2),
			'expenses'            => [
				'total'       => round($expenseTotal, 2),
				'by_category' => $expensesByCategory,
			],
			'net_profit'        => round($netProfit, 2),
			'profit_margin_pct' => $profitMargin,
		]);
	}

	// GET /api/reports/branches?month=2026-05
	// super_admin only — revenue, orders, expenses, and net profit per branch + grand totals
	public function branchComparison(Request $request)
	{
		[$dateFrom, $dateTo] = $this->resolveDateRange($request);

		$revenueRows = DB::table('orders')
			->join('branches', 'orders.branch_id', '=', 'branches.id')
			->where('branches.is_test', false)
			->whereNull('orders.deleted_at')
			->whereDate('orders.created_at', '>=', $dateFrom)
			->whereDate('orders.created_at', '<=', $dateTo)
			->selectRaw('branches.id as branch_id, branches.name as branch_name, COUNT(orders.id) as order_count, SUM(orders.total_amount) as revenue')
			->groupBy('branches.id', 'branches.name')
			->orderByDesc('revenue')
			->get();

		$expensesByBranch = DB::table('expenses')
			->join('branches', 'expenses.branch_id', '=', 'branches.id')
			->where('branches.is_test', false)
			->whereNull('expenses.deleted_at')
			->whereDate('expenses.expense_date', '>=', $dateFrom)
			->whereDate('expenses.expense_date', '<=', $dateTo)
			->selectRaw('expenses.branch_id, SUM(expenses.amount) as expenses')
			->groupBy('expenses.branch_id')
			->pluck('expenses', 'branch_id');

		$branches = $revenueRows->map(function ($row) use ($expensesByBranch) {
			$expenses  = (float) ($expensesByBranch[$row->branch_id] ?? 0);
			$revenue   = (float) $row->revenue;
			$netProfit = $revenue - $expenses;

			return [
				'branch_id'   => $row->branch_id,
				'branch_name' => $row->branch_name,
				'order_count' => $row->order_count,
				'revenue'     => round($revenue, 2),
				'expenses'    => round($expenses, 2),
				'net_profit'  => round($netProfit, 2),
			];
		});

		$totalRevenue  = round($branches->sum('revenue'), 2);
		$totalExpenses = round(
			DB::table('expenses')
				->join('branches', 'expenses.branch_id', '=', 'branches.id')
				->where('branches.is_test', false)
				->whereNull('expenses.deleted_at')
				->whereDate('expenses.expense_date', '>=', $dateFrom)
				->whereDate('expenses.expense_date', '<=', $dateTo)
				->sum('expenses.amount'),
			2
		);

		return response()->json([
			'date_from' => $dateFrom,
			'date_to'   => $dateTo,
			'branches'  => $branches,
			'totals'    => [
				'order_count' => $branches->sum('order_count'),
				'revenue'     => $totalRevenue,
				'expenses'    => $totalExpenses,
				'net_profit'  => round($totalRevenue - $totalExpenses, 2),
			],
		]);
	}

	// --- Helpers ---

	/** Orders where sum of payments < total_amount (not yet fully paid) */
	private function unpaidCondition(): string
	{
		return 'COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id = orders.id), 0) < orders.total_amount';
	}

	/** Sums the still-outstanding balance (total minus payments) as `outstanding`. */
	private function outstandingBalanceSum(): string
	{
		return 'COALESCE(SUM(orders.total_amount - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.order_id = orders.id), 0)), 0) as outstanding';
	}

	private function applyDateFilters($query, Request $request, string $column): void
	{
		if ($request->filled('month')) {
			$query->whereYear($column, substr($request->month, 0, 4))
				->whereMonth($column, substr($request->month, 5, 2));
		} else {
			if ($request->filled('date_from')) {
				$query->whereDate($column, '>=', $request->date_from);
			}
			if ($request->filled('date_to')) {
				$query->whereDate($column, '<=', $request->date_to);
			}
		}
	}

	private function resolveDateRange(Request $request): array
	{
		if ($request->filled('month')) {
			$start = Carbon::parse($request->month . '-01');
			return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
		}

		if ($request->filled('year')) {
			return ["{$request->year}-01-01", "{$request->year}-12-31"];
		}

		return [
			$request->input('date_from', now()->startOfMonth()->toDateString()),
			$request->input('date_to', today()->toDateString()),
		];
	}
}
