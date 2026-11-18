<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerBalanceTransaction;
use App\Services\CustomerBalanceService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/**
 * Prepaid customer balance: admins record deposits, view the ledger, and void
 * a deposit entered by mistake. Spending happens through PaymentController
 * with method = 'balance'.
 */
class CustomerBalanceController extends Controller implements HasMiddleware
{
	public function __construct(private CustomerBalanceService $balances) {}

	public static function middleware(): array
	{
		return [
			new Middleware('role:admin'),
		];
	}

	// GET /api/customers/{customer}/balance
	public function index(Request $request, Customer $customer)
	{
		$this->ensureSameBranch($request, $customer);

		$transactions = CustomerBalanceTransaction::where('customer_id', $customer->id)
			->with(['user:id,name', 'payment:id,order_id', 'payment.order:id,order_number'])
			->orderByDesc('created_at')
			->orderByDesc('id')
			->limit(200)
			->get();

		return response()->json([
			'balance'      => (float) $customer->balance,
			'transactions' => $transactions,
		]);
	}

	// POST /api/customers/{customer}/balance/deposits
	public function deposit(Request $request, Customer $customer)
	{
		$this->ensureSameBranch($request, $customer);

		$validated = $request->validate([
			'amount' => 'required|numeric|min:0.01|max:999999',
			'method' => 'required|in:cash,gcash',
			'note'   => 'nullable|string|max:500',
			'date'   => 'nullable|date|before_or_equal:today',
		]);

		DB::transaction(fn () => $this->balances->deposit(
			$customer,
			$this->branchId($request) ?? $customer->branch_id,
			(float) $validated['amount'],
			$validated['method'],
			$validated['note'] ?? null,
			$request->user()->id,
			$validated['date'] ?? null,
		));

		return $this->index($request, $customer->fresh());
	}

	// DELETE /api/customers/{customer}/balance/transactions/{transaction}
	public function void(Request $request, Customer $customer, CustomerBalanceTransaction $transaction)
	{
		$this->ensureSameBranch($request, $customer);

		if ($transaction->customer_id !== $customer->id) {
			abort(404);
		}

		DB::transaction(fn () => $this->balances->voidDeposit($transaction, $request->user()->id));

		return $this->index($request, $customer->fresh());
	}

	private function ensureSameBranch(Request $request, Customer $customer): void
	{
		$branchId = $this->branchId($request);

		if ($branchId !== null && $customer->branch_id !== null && $customer->branch_id !== $branchId) {
			abort(404);
		}
	}
}
