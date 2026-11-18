<?php

namespace App\Services;

use App\Models\AccountMovement;
use Illuminate\Support\Facades\DB;

/**
 * Running cash / GCash balance of a branch, shared by the Accounts page and
 * the end-of-day account check so both always agree on "expected".
 */
class AccountBalanceService
{
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
	public function buildAccount(int $branchId, string $method, string $asOf): array
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
		$paymentsRow = DB::table('payments')
			->join('orders', 'payments.order_id', '=', 'orders.id')
			->where('orders.branch_id', $branchId)
			->where('payments.method', $method)
			->whereNull('payments.deleted_at')
			->whereNull('orders.deleted_at')
			->whereDate('payments.created_at', '<=', $asOf)
			->when($cutover, fn ($q) => $q->where('payments.created_at', '>', $cutover))
			->selectRaw("COALESCE(SUM(CASE WHEN payments.type = 'refund' THEN -payments.amount ELSE payments.amount END), 0) as total, COALESCE(SUM(payments.tip_amount), 0) as tips")
			->first();
		$tipsIn     = (float) ($paymentsRow->tips ?? 0);
		$paymentsIn = (float) ($paymentsRow->total ?? 0);

		// Customer deposits (prepaid balance) received into this account, net
		// of voided ones (whose reversal rows carry the same method).
		$customerDeposits = (float) DB::table('customer_balance_transactions')
			->where('branch_id', $branchId)
			->where('method', $method)
			->whereDate('created_at', '<=', $asOf)
			->when($cutover, fn ($q) => $q->where('created_at', '>', $cutover))
			->sum('amount');

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
		$tipPayouts  = 0.0;
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
			} elseif ($movement->type === 'tip_payout') {
				$tipPayouts += $amount;
			} elseif ($movement->type === 'deposit') {
				$deposits += $amount;
			}
		}

		$openingAmount = (float) ($opening?->amount ?? 0);

		$balance = $openingAmount
			+ $paymentsIn
			+ $tipsIn
			+ $customerDeposits
			- $expensesOut
			- $withdrawals
			- $tipPayouts
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
			'tips'         => round($tipsIn, 2),
			'customer_deposits' => round($customerDeposits, 2),
			'expenses'     => round($expensesOut, 2),
			'withdrawals'  => round($withdrawals, 2),
			'tip_payouts'  => round($tipPayouts, 2),
			'deposits'     => round($deposits, 2),
			'transfer_in'  => round($transferIn, 2),
			'transfer_out' => round($transferOut, 2),
			'balance'      => round($balance, 2),
		];
	}
}
