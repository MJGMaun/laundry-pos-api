<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Super-admin reconciliation of GCash payments against the GCash app's
 * transaction history: list every GCash payment in a date range with its
 * proof, and tick each off once found in the app. Whatever stays unticked
 * is the gap to chase.
 */
class GcashMatchingController extends Controller implements HasMiddleware
{
	public static function middleware(): array
	{
		return [
			new Middleware('role:super_admin'),
		];
	}

	// GET /api/gcash-matching?date_from=&date_to=&status=all|matched|unmatched
	public function index(Request $request)
	{
		$branchId = $this->branchId($request);
		$dateFrom = $request->date_from ?? now()->toDateString();
		$dateTo   = $request->date_to ?? $dateFrom;
		if ($dateTo < $dateFrom) {
			[$dateFrom, $dateTo] = [$dateTo, $dateFrom];
		}

		$base = Payment::query()
			->join('orders', 'payments.order_id', '=', 'orders.id')
			->leftJoin('customers', 'orders.customer_id', '=', 'customers.id')
			->leftJoin('branches', 'orders.branch_id', '=', 'branches.id')
			->leftJoin('users as matcher', 'payments.matched_by', '=', 'matcher.id')
			->leftJoin('users as changer', 'payments.method_changed_by', '=', 'changer.id')
			->whereNull('orders.deleted_at')
			->whereDate('payments.created_at', '>=', $dateFrom)
			->whereDate('payments.created_at', '<=', $dateTo)
			->when($branchId !== null, fn ($q) => $q->where('orders.branch_id', $branchId));

		// Payments that were GCash but corrected to cash: shown separately so
		// the super admin sees why the GCash total moved.
		$changedAway = (clone $base)
			->where('payments.method', '!=', 'gcash')
			->where('payments.method_changed_from', 'gcash')
			->orderByDesc('payments.created_at')
			->get($this->columns());

		$gcash = (clone $base)->where('payments.method', 'gcash');

		$all = (clone $gcash)->get(['payments.type', 'payments.amount', 'payments.tip_amount', 'payments.matched_at', 'payments.reference_number', 'payments.receipt_path']);
		// What the GCash app shows: the amount plus any tip sent with it.
		$signed = fn ($p) => $p->type === 'refund' ? -(float) $p->amount : (float) $p->amount + (float) $p->tip_amount;

		$summary = [
			'total'           => round($all->sum($signed), 2),
			'matched'         => round($all->whereNotNull('matched_at')->sum($signed), 2),
			'unmatched'       => round($all->whereNull('matched_at')->sum($signed), 2),
			'count'           => $all->count(),
			'matched_count'   => $all->whereNotNull('matched_at')->count(),
			'unmatched_count' => $all->whereNull('matched_at')->count(),
			'tips'            => round((float) $all->sum('tip_amount'), 2),
			'no_proof_count'  => $all->filter(fn ($p) => strlen(trim((string) $p->reference_number)) < 4 && ! $p->receipt_path)->count(),
		];

		$status = $request->input('status', 'all');
		if ($status === 'matched') {
			$gcash->whereNotNull('payments.matched_at');
		} elseif ($status === 'unmatched') {
			$gcash->whereNull('payments.matched_at');
		}

		$payments = $gcash
			->orderBy('payments.created_at')
			->get($this->columns());

		return response()->json([
			'date_from'    => $dateFrom,
			'date_to'      => $dateTo,
			'summary'      => $summary,
			'payments'     => $payments,
			'changed_away' => $changedAway,
		]);
	}

	// PATCH /api/payments/{payment}/matched  { matched: bool }
	public function setMatched(Request $request, Payment $payment)
	{
		$validated = $request->validate(['matched' => 'required|boolean']);

		$order = $payment->order;
		$branchId = $this->branchId($request);
		if (! $order || ($branchId !== null && $order->branch_id !== $branchId)) {
			abort(404);
		}
		if ($payment->method !== 'gcash') {
			abort(422, 'Only GCash payments can be matched.');
		}

		$payment->update($validated['matched']
			? ['matched_at' => now(), 'matched_by' => $request->user()->id]
			: ['matched_at' => null, 'matched_by' => null]);

		return response()->json(['data' => [
			'id'              => $payment->id,
			'matched_at'      => $payment->matched_at,
			'matched_by_name' => $validated['matched'] ? $request->user()->name : null,
		]]);
	}

	private function columns(): array
	{
		return [
			'payments.id',
			'payments.method',
			'payments.type',
			'payments.amount',
			'payments.tip_amount',
			'payments.reference_number',
			'payments.receipt_path',
			'payments.matched_at',
			'payments.method_changed_from',
			'payments.method_changed_at',
			'payments.created_at',
			'orders.id as order_id',
			'orders.order_number',
			'customers.name as customer_name',
			'branches.name as branch_name',
			'matcher.name as matched_by_name',
			'changer.name as method_changed_by_name',
		];
	}
}
