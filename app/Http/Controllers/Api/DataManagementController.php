<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DailyCashBalance;
use App\Models\Expense;
use App\Models\Load;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyStamp;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class DataManagementController extends Controller implements HasMiddleware
{
	public static function middleware(): array
	{
		return [
			new Middleware('role:super_admin'),
		];
	}

	/**
	 * GET /api/branches/{branch}/data-counts
	 * Returns row counts for each purgeable category.
	 */
	public function counts(Branch $branch)
	{
		$branchId = $branch->id;

		// Order IDs for this branch (including soft-deleted so counts are accurate)
		$orderIds = Order::withTrashed()->where('branch_id', $branchId)->pluck('id');

		// Customer IDs for this branch (including soft-deleted)
		$customerIds = Customer::withTrashed()->where('branch_id', $branchId)->pluck('id');

		return response()->json([
			'orders'        => Order::withTrashed()->where('branch_id', $branchId)->count(),
			'payments'      => Payment::whereIn('order_id', $orderIds)->count(),
			'loads'         => Load::withTrashed()->whereIn('order_id', $orderIds)->count(),
			'customers'     => Customer::withTrashed()->where('branch_id', $branchId)->count(),
			'expenses'      => Expense::withTrashed()->where('branch_id', $branchId)->count(),
			'cash_balances' => DailyCashBalance::where('branch_id', $branchId)->count(),
			'loyalty_transactions' => LoyaltyTransaction::whereIn('customer_id', $customerIds)->count(),
			'loyalty_stamps'       => LoyaltyStamp::where('branch_id', $branchId)->count(),
			'loyalty_rewards'      => LoyaltyReward::where('branch_id', $branchId)->count(),
		]);
	}

	/**
	 * DELETE /api/branches/{branch}/purge
	 * Body: { types: ['orders', 'customers', 'expenses', 'cash_balances'] }
	 *
	 * Deletion order respects FK constraints:
	 *   loyalty_stamps/rewards/transactions → payments → loads → orders → customers → expenses → cash_balances
	 */
	public function purge(Request $request, Branch $branch)
	{
		$validated = $request->validate([
			'types'   => 'required|array|min:1',
			'types.*' => 'required|string|in:orders,customers,expenses,cash_balances',
			'confirm' => 'required|string|in:DELETE',
		]);

		$types    = $validated['types'];
		$branchId = $branch->id;
		$deleted  = [];

		DB::transaction(function () use ($types, $branchId, &$deleted) {

			$orderIds    = Order::withTrashed()->where('branch_id', $branchId)->pluck('id');
			$customerIds = Customer::withTrashed()->where('branch_id', $branchId)->pluck('id');

			// ── 1. Orders & Payments ──────────────────────────────────────────
			if (in_array('orders', $types) && $orderIds->isNotEmpty()) {

				// Loyalty data tied to these orders
				$ls = LoyaltyStamp::whereIn('order_id', $orderIds)->count();
				LoyaltyStamp::whereIn('order_id', $orderIds)->delete();
				$deleted['loyalty_stamps_from_orders'] = $ls;

				$lr = LoyaltyReward::whereIn('redeemed_on_order_id', $orderIds)->count();
				LoyaltyReward::whereIn('redeemed_on_order_id', $orderIds)->delete();
				$deleted['loyalty_rewards_redeemed_on_orders'] = $lr;

				$lt = LoyaltyTransaction::whereIn('order_id', $orderIds)->count();
				LoyaltyTransaction::whereIn('order_id', $orderIds)->delete();
				$deleted['loyalty_transactions'] = $lt;

				// Payments
				$py = Payment::whereIn('order_id', $orderIds)->count();
				Payment::whereIn('order_id', $orderIds)->delete();
				$deleted['payments'] = $py;

				// Loads (with soft-deletes)
				$lo = Load::withTrashed()->whereIn('order_id', $orderIds)->count();
				Load::withTrashed()->whereIn('order_id', $orderIds)->forceDelete();
				$deleted['loads'] = $lo;

				// Orders
				$ord = Order::withTrashed()->where('branch_id', $branchId)->count();
				Order::withTrashed()->where('branch_id', $branchId)->forceDelete();
				$deleted['orders'] = $ord;

				// Reset customer aggregate stats derived from orders
				if ($customerIds->isNotEmpty()) {
					Customer::withTrashed()->whereIn('id', $customerIds)->update([
						'loyalty_points' => 0,
						'total_visits'   => 0,
						'total_spent'    => 0,
						'loyalty_tier_id' => 1,
					]);
					$deleted['customer_stats_reset'] = $customerIds->count();
				}
			}

			// ── 2. Customers ──────────────────────────────────────────────────
			if (in_array('customers', $types) && $customerIds->isNotEmpty()) {

				// Remaining loyalty data tied to customers (not already deleted above)
				$ls2 = LoyaltyStamp::where('branch_id', $branchId)->count();
				LoyaltyStamp::where('branch_id', $branchId)->delete();
				$deleted['loyalty_stamps'] = ($deleted['loyalty_stamps_from_orders'] ?? 0) + $ls2;

				$lr2 = LoyaltyReward::where('branch_id', $branchId)->count();
				LoyaltyReward::where('branch_id', $branchId)->delete();
				$deleted['loyalty_rewards'] = ($deleted['loyalty_rewards_redeemed_on_orders'] ?? 0) + $lr2;

				$lt2 = LoyaltyTransaction::whereIn('customer_id', $customerIds)->count();
				LoyaltyTransaction::whereIn('customer_id', $customerIds)->delete();
				// Don't double count if orders were also deleted
				if (!isset($deleted['loyalty_transactions'])) {
					$deleted['loyalty_transactions'] = $lt2;
				}

				$cust = Customer::withTrashed()->where('branch_id', $branchId)->count();
				Customer::withTrashed()->where('branch_id', $branchId)->forceDelete();
				$deleted['customers'] = $cust;
			}

			// ── 3. Expenses ───────────────────────────────────────────────────
			if (in_array('expenses', $types)) {
				$exp = Expense::withTrashed()->where('branch_id', $branchId)->count();
				Expense::withTrashed()->where('branch_id', $branchId)->forceDelete();
				$deleted['expenses'] = $exp;
			}

			// ── 4. Cash Balances ──────────────────────────────────────────────
			if (in_array('cash_balances', $types)) {
				$cb = DailyCashBalance::where('branch_id', $branchId)->count();
				DailyCashBalance::where('branch_id', $branchId)->delete();
				$deleted['cash_balances'] = $cb;
			}
		});

		return response()->json([
			'message' => 'Branch data purged successfully.',
			'branch'  => $branch->name,
			'deleted' => $deleted,
		]);
	}
}
