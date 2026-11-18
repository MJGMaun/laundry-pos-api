<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerBalanceTransaction;
use App\Models\Payment;
use Illuminate\Validation\ValidationException;

/**
 * Prepaid customer balance. Every change goes through apply(), which locks the
 * customer row so two cashiers can't spend the same peso, and writes a ledger
 * line carrying the resulting balance.
 *
 * Callers are expected to wrap these in a DB transaction.
 */
class CustomerBalanceService
{
	/** Money handed over now, to be used on later orders. */
	public function deposit(Customer $customer, int $branchId, float $amount, string $method, ?string $note, ?int $userId, ?string $date = null): CustomerBalanceTransaction
	{
		$tx = $this->apply($customer, $branchId, $amount, [
			'type'    => 'deposit',
			'method'  => $method,
			'note'    => $note,
			'user_id' => $userId,
		]);

		// Admin backdating, same convention as payments: keep the time of day.
		if ($date) {
			$tx->created_at = \Carbon\Carbon::parse($date)->setTimeFrom(now());
			$tx->save();
		}

		return $tx;
	}

	/**
	 * Undo a deposit entered by mistake. The reversal carries the same method
	 * so drawer and account totals drop back by the same amount.
	 */
	public function voidDeposit(CustomerBalanceTransaction $deposit, ?int $userId): CustomerBalanceTransaction
	{
		if ($deposit->type !== 'deposit' || $deposit->voided_at) {
			throw ValidationException::withMessages(['deposit' => 'Only an active deposit can be voided.']);
		}

		$reversal = $this->apply($deposit->customer, $deposit->branch_id, -(float) $deposit->amount, [
			'type'        => 'reversal',
			'method'      => $deposit->method,
			'reverses_id' => $deposit->id,
			'note'        => 'Voided deposit',
			'user_id'     => $userId,
		], 'This customer has already used part of this deposit, so it can no longer be voided.');

		$deposit->update(['voided_at' => now()]);

		return $reversal;
	}

	/**
	 * A payment or refund recorded with method = 'balance'. Payments draw the
	 * balance down; refunds credit the money back to it as store credit.
	 */
	public function recordPayment(Payment $payment, Customer $customer, int $branchId, ?int $userId): CustomerBalanceTransaction
	{
		$amount = (float) $payment->amount;
		$isRefund = $payment->type === 'refund';

		return $this->apply($customer, $branchId, $isRefund ? $amount : -$amount, [
			'type'       => $isRefund ? 'refund' : 'payment',
			'payment_id' => $payment->id,
			'user_id'    => $userId,
		]);
	}

	/** Undo the balance effect of a deleted balance payment/refund. */
	public function reversePayment(Payment $payment, Customer $customer, int $branchId, ?int $userId, string $note = 'Payment deleted'): ?CustomerBalanceTransaction
	{
		if ($payment->method !== 'balance') {
			return null;
		}

		$amount = (float) $payment->amount;

		return $this->apply($customer, $branchId, $payment->type === 'refund' ? -$amount : $amount, [
			'type'       => 'reversal',
			'payment_id' => $payment->id,
			'note'       => $note,
			'user_id'    => $userId,
		], 'The store credit from this refund has already been used, so it can no longer be removed.');
	}

	private function apply(Customer $customer, int $branchId, float $delta, array $attributes, ?string $insufficientMessage = null): CustomerBalanceTransaction
	{
		$locked  = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
		$current = (float) $locked->balance;
		$next    = round($current + $delta, 2);

		if ($next < 0) {
			throw ValidationException::withMessages([
				'amount' => $insufficientMessage
					?? 'Not enough customer balance. Available: ₱' . number_format($current, 2),
			]);
		}

		$locked->balance = $next;
		$locked->save();
		$customer->balance = $next;

		return CustomerBalanceTransaction::create($attributes + [
			'customer_id'   => $locked->id,
			'branch_id'     => $branchId,
			'amount'        => round($delta, 2),
			'balance_after' => $next,
		]);
	}
}
