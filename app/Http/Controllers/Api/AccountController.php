<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountMovement;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/**
 * Running cash and GCash balances per branch.
 *
 * CashBalanceController answers "what should be in the drawer today"; this one
 * answers "how much money does this branch actually hold right now", carried
 * forward across days. The two differ by owner withdrawals, which live in
 * account_movements and never touch `expenses` — so nothing here changes the
 * revenue, net profit, or margin reported by ReportsController.
 */
class AccountController extends Controller implements HasMiddleware
{
	private const METHODS = ['cash', 'gcash'];

	public static function middleware(): array
	{
		return [
			new Middleware('page:accounts,view'),
			new Middleware('page:accounts,edit', only: ['store', 'destroy']),
		];
	}

	// GET /api/accounts?as_of=YYYY-MM-DD
	public function show(Request $request)
	{
		$branchId = $this->branchId($request);

		if ($branchId === null) {
			return response()->json([
				'message' => 'Select a branch to view its accounts.',
			], 422);
		}

		$asOf = $request->input('as_of', now()->toDateString());

		$accounts = array_map(
			fn ($method) => $this->buildAccount($branchId, $method, $asOf),
			self::METHODS
		);

		$movements = AccountMovement::with('user:id,name')
			->where('branch_id', $branchId)
			->whereDate('occurred_on', '<=', $asOf)
			->orderByDesc('occurred_on')
			->orderByDesc('id')
			->limit(100)
			->get();

		return response()->json([
			'as_of'         => $asOf,
			'branch_id'     => $branchId,
			'accounts'      => $accounts,
			'total_balance' => round(array_sum(array_column($accounts, 'balance')), 2),
			'months'        => $this->monthlyBreakdown($branchId),
			'movements'     => $movements,
		]);
	}

	// POST /api/account-movements
	public function store(Request $request)
	{
		$branchId = $this->branchId($request);

		if ($branchId === null) {
			return response()->json([
				'message' => 'Select a branch before recording a movement.',
			], 422);
		}

		$validated = $request->validate([
			'type'        => 'required|in:opening,withdrawal,deposit,transfer',
			'method'      => 'required|in:cash,gcash',
			// Only a transfer has a destination, and it must differ from the source.
			'to_method'   => 'required_if:type,transfer|nullable|in:cash,gcash|different:method',
			// An opening balance of zero is meaningful ("started empty"); every
			// other movement has to actually move something.
			'amount'      => ['required', 'numeric', $request->input('type') === 'opening' ? 'min:0' : 'min:0.01'],
			// An opening is always stamped with today below, so it needs no date.
			'occurred_on' => 'required_unless:type,opening|nullable|date_format:Y-m-d',
			'recipient'   => 'nullable|string|max:100',
			'note'        => 'nullable|string|max:500',
		]);

		if ($validated['type'] !== 'transfer') {
			$validated['to_method'] = null;
		}

		// An opening balance is counted here and now — it seals whatever is
		// already recorded, so it always carries today's date and the exact
		// instant it was saved. Any date the client sent is ignored.
		if ($validated['type'] === 'opening') {
			$validated['occurred_on']  = now()->toDateString();
			$validated['effective_at'] = now();
		}

		$validated['branch_id'] = $branchId;
		$validated['user_id']   = $request->user()->id;

		AccountMovement::create($validated);

		return $this->show($request);
	}

	// DELETE /api/account-movements/{movement}
	public function destroy(Request $request, AccountMovement $movement)
	{
		if ($movement->branch_id !== $this->branchId($request)) {
			return response()->json(['message' => 'This movement belongs to another branch.'], 403);
		}

		$movement->delete();

		return $this->show($request);
	}

	/**
	 * Month-by-month money in and out for the last 12 months, newest first, so
	 * comparing months takes no filtering at all. This is raw history and
	 * deliberately ignores the opening-balance cutover — that only exists to
	 * make today's balance match the drawer, and sealing it would erase the
	 * months you want to look back at.
	 *
	 * Withdrawals are reported in their own column and left out of `net`:
	 * taking profit out is a distribution, not a cost of the month.
	 */
	private function monthlyBreakdown(int $branchId): array
	{
		$start = now()->subMonths(11)->startOfMonth();
		$from  = $start->toDateString();
		$to    = now()->endOfMonth()->toDateString();

		$paymentMonth = $this->monthExpression('payments.created_at');
		$payments = DB::table('payments')
			->join('orders', 'payments.order_id', '=', 'orders.id')
			->where('orders.branch_id', $branchId)
			->whereNull('payments.deleted_at')
			->whereNull('orders.deleted_at')
			->whereDate('payments.created_at', '>=', $from)
			->whereDate('payments.created_at', '<=', $to)
			->selectRaw("{$paymentMonth} as month, payments.method as method, COALESCE(SUM(CASE WHEN payments.type = 'refund' THEN -payments.amount ELSE payments.amount END), 0) as total")
			->groupByRaw("{$paymentMonth}, payments.method")
			->get();

		$expenseMonth = $this->monthExpression('expense_date');
		$expenses = DB::table('expenses')
			->where('branch_id', $branchId)
			->whereNull('deleted_at')
			->whereDate('expense_date', '>=', $from)
			->whereDate('expense_date', '<=', $to)
			->selectRaw("{$expenseMonth} as month, SUM(amount) as total")
			->groupByRaw($expenseMonth)
			->pluck('total', 'month');

		$drawMonth = $this->monthExpression('occurred_on');
		$withdrawals = DB::table('account_movements')
			->where('branch_id', $branchId)
			->where('type', 'withdrawal')
			->whereNull('deleted_at')
			->whereDate('occurred_on', '>=', $from)
			->whereDate('occurred_on', '<=', $to)
			->selectRaw("{$drawMonth} as month, SUM(amount) as total")
			->groupByRaw($drawMonth)
			->pluck('total', 'month');

		$paymentsByMonth = [];
		foreach ($payments as $row) {
			$paymentsByMonth[$row->month][$row->method] = (float) $row->total;
		}

		$currentMonth = now()->format('Y-m');
		$rows = [];

		for ($i = 0; $i < 12; $i++) {
			$key     = $start->copy()->addMonths($i)->format('Y-m');
			$cashIn  = $paymentsByMonth[$key]['cash'] ?? 0.0;
			$gcashIn = $paymentsByMonth[$key]['gcash'] ?? 0.0;
			$spent   = (float) ($expenses[$key] ?? 0);
			$drawn   = (float) ($withdrawals[$key] ?? 0);

			// Skip dead months so a young branch isn't padded with empty rows,
			// but always keep the current one so the table is never blank.
			if ($key !== $currentMonth && !$cashIn && !$gcashIn && !$spent && !$drawn) {
				continue;
			}

			$rows[] = [
				'month'       => $key,
				'cash_in'     => round($cashIn, 2),
				'gcash_in'    => round($gcashIn, 2),
				'expenses'    => round($spent, 2),
				'withdrawals' => round($drawn, 2),
				'net'         => round($cashIn + $gcashIn - $spent, 2),
			];
		}

		return array_reverse($rows);
	}

	/**
	 * Balance for one account = its opening balance, plus every payment
	 * collected in that method, minus expenses paid from it, minus withdrawals,
	 * plus money put back in, adjusted for transfers between accounts.
	 *
	 * The opening balance is a clean slate: it seals everything recorded up to
	 * the instant it was saved, so the moment it lands the balance equals the
	 * counted figure exactly and every other line reads zero. Only records
	 * entered afterwards move it. With no opening set, everything on record is
	 * summed and the payload flags it via has_opening.
	 */
	private function buildAccount(int $branchId, string $method, string $asOf): array
	{
		$opening = AccountMovement::where('branch_id', $branchId)
			->where('method', $method)
			->where('type', 'opening')
			->whereDate('occurred_on', '<=', $asOf)
			->orderByDesc('occurred_on')
			->orderByDesc('id')
			->first();

		// The cutover is when the opening was saved, not the day it covers: a
		// figure counted at 3pm must not have that morning's payments added
		// back on top of it.
		$cutover = $opening?->effective_at;

		// Payments are stamped by created_at; expenses carry their own date.
		$paymentsIn = (float) DB::table('payments')
			->join('orders', 'payments.order_id', '=', 'orders.id')
			->where('orders.branch_id', $branchId)
			->where('payments.method', $method)
			->whereNull('payments.deleted_at')
			->whereNull('orders.deleted_at')
			->whereDate('payments.created_at', '<=', $asOf)
			->when($cutover, fn ($q) => $q->where('payments.created_at', '>', $cutover))
			->selectRaw("COALESCE(SUM(CASE WHEN payments.type = 'refund' THEN -payments.amount ELSE payments.amount END), 0) as total")
			->value('total');

		$expensesOut = (float) DB::table('expenses')
			->where('branch_id', $branchId)
			->where('payment_method', $method)
			->whereNull('deleted_at')
			->whereDate('expense_date', '<=', $asOf)
			->when($cutover, fn ($q) => $q->where('expenses.created_at', '>', $cutover))
			->sum('amount');

		// Every movement touching this account, as source or as transfer target.
		$movements = AccountMovement::where('branch_id', $branchId)
			->whereDate('occurred_on', '<=', $asOf)
			->when($cutover, fn ($q) => $q->where('created_at', '>', $cutover))
			->where(fn ($q) => $q->where('method', $method)->orWhere('to_method', $method))
			->get();

		$withdrawals = 0.0;
		$deposits    = 0.0;
		$transferIn  = 0.0;
		$transferOut = 0.0;

		foreach ($movements as $movement) {
			$amount = (float) $movement->amount;

			if ($movement->type === 'transfer') {
				if ($movement->method === $method) {
					$transferOut += $amount;
				}
				if ($movement->to_method === $method) {
					$transferIn += $amount;
				}
				continue;
			}

			// The opening row is the starting figure, not a movement on top of it.
			if ($movement->method !== $method || $movement->type === 'opening') {
				continue;
			}

			if ($movement->type === 'withdrawal') {
				$withdrawals += $amount;
			} elseif ($movement->type === 'deposit') {
				$deposits += $amount;
			}
		}

		$openingAmount = (float) ($opening?->amount ?? 0);

		$balance = $openingAmount
			+ $paymentsIn
			- $expensesOut
			- $withdrawals
			+ $deposits
			+ $transferIn
			- $transferOut;

		return [
			'method'       => $method,
			'has_opening'  => $opening !== null,
			'opening'      => round($openingAmount, 2),
			'opening_date' => $opening?->occurred_on?->toDateString(),
			'cutover_at'   => $cutover?->toIso8601String(),
			'payments_in'  => round($paymentsIn, 2),
			'expenses'     => round($expensesOut, 2),
			'withdrawals'  => round($withdrawals, 2),
			'deposits'     => round($deposits, 2),
			'transfer_in'  => round($transferIn, 2),
			'transfer_out' => round($transferOut, 2),
			'balance'      => round($balance, 2),
		];
	}
}
