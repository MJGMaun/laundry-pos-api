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
			new Middleware('role:super_admin'),
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
			'occurred_on' => 'required|date_format:Y-m-d',
			'recipient'   => 'nullable|string|max:100',
			'note'        => 'nullable|string|max:500',
		]);

		if ($validated['type'] !== 'transfer') {
			$validated['to_method'] = null;
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
	 * Balance for one account = its opening balance, plus every payment
	 * collected in that method, minus expenses paid from it, minus withdrawals,
	 * plus money put back in, adjusted for transfers between accounts.
	 *
	 * The opening balance is also the cutover: only activity from its date
	 * onward counts, so a branch that has been running for months can start
	 * from a real counted figure instead of replaying all history. Its date is
	 * inclusive — the opening balance is what was on hand at the START of that
	 * day. With no opening set, everything on record is summed and the payload
	 * flags it via has_opening.
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

		$since = $opening?->occurred_on?->toDateString();

		// Payments are stamped by created_at; expenses carry their own date.
		$paymentsIn = (float) DB::table('payments')
			->join('orders', 'payments.order_id', '=', 'orders.id')
			->where('orders.branch_id', $branchId)
			->where('payments.method', $method)
			->whereNull('payments.deleted_at')
			->whereNull('orders.deleted_at')
			->whereDate('payments.created_at', '<=', $asOf)
			->when($since, fn ($q) => $q->whereDate('payments.created_at', '>=', $since))
			->selectRaw("COALESCE(SUM(CASE WHEN payments.type = 'refund' THEN -payments.amount ELSE payments.amount END), 0) as total")
			->value('total');

		$expensesOut = (float) DB::table('expenses')
			->where('branch_id', $branchId)
			->where('payment_method', $method)
			->whereNull('deleted_at')
			->whereDate('expense_date', '<=', $asOf)
			->when($since, fn ($q) => $q->whereDate('expense_date', '>=', $since))
			->sum('amount');

		// Every movement touching this account, as source or as transfer target.
		$movements = AccountMovement::where('branch_id', $branchId)
			->whereDate('occurred_on', '<=', $asOf)
			->when($since, fn ($q) => $q->whereDate('occurred_on', '>=', $since))
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
			'opening_date' => $since,
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
