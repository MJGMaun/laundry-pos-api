<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\Service;
use App\Services\LoyaltyService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller implements HasMiddleware
{
	public function __construct(private LoyaltyService $loyaltyService) {}

	public static function middleware(): array
	{
		return [
			new Middleware('role:admin,cashier,staff', only: ['store', 'update']),
			new Middleware('role:admin,cashier,staff', only: ['updateStatus']),
			new Middleware('role:admin,cashier,staff', only: ['destroy']),
			new Middleware('role:admin,super_admin', only: ['markDelivered']),
		];
	}

	public function index(Request $request)
	{
		$query = Order::with(['customer', 'loads'])
			->withSum(['payments as paid_amount' => fn($q) => $q->where('type', 'payment')], 'amount');

		$this->scopeToBranch($query, $request);

		if ($request->has('status')) {
			$query->where('status', $request->status);
		}

		if ($request->filled('search')) {
			$search = $request->search;
			$query->where(function ($q) use ($search) {
				$q->where('order_number', 'like', "%{$search}%")
				  ->orWhereHas('customer', fn($cq) => $cq->where('name', 'like', "%{$search}%"));
			});
		}

		if ($request->filled('date_from')) {
			$query->whereDate('created_at', '>=', $request->date_from);
		}

		if ($request->filled('date_to')) {
			$query->whereDate('created_at', '<=', $request->date_to);
		}

		if ($request->has('customer_id')) {
			$query->where('customer_id', $request->customer_id);
		}

		if ($request->boolean('unpaid')) {
			$query->unpaid();
		}

		if ($request->filled('delivery_date')) {
			$query->whereDate('delivery_scheduled_at', $request->delivery_date);
		}

		$perPage = min((int) $request->get('per_page', 15), 500);
		$orders = $query->latest()->paginate($perPage);

		return response()->json($orders);
	}

	public function store(Request $request)
	{
		$validated = $request->validate([
			'client_id'            => 'nullable|string|max:36',
			'customer_id'          => 'required|exists:customers,id',
			'loads'                => 'required|array|min:1',
			'loads.*.service_id'   => 'required|exists:services,id',
			'loads.*.quantity'     => 'required|numeric|min:0.01',
			'loads.*.notes'        => 'nullable|string',
			'extra_fees'           => 'nullable|numeric|min:0',
			'discount_amount'      => 'nullable|numeric|min:0',
			'loyalty_free_loads'   => 'nullable|integer|min:0',
			'notes'                => 'nullable|string',
			'estimated_ready_at'   => 'nullable|date',
		]);

		if (!empty($validated['client_id'])) {
			$existing = Order::where('client_id', $validated['client_id'])->first();
			if ($existing) {
				return response()->json($existing->load(['customer', 'loads']), 200);
			}
		}

		$attempt = 0;
		$order   = null;
		while (true) {
			try {
				$order = $this->attemptCreateOrder($validated, $request);
				break;
			} catch (QueryException $e) {
				// MySQL 1062 = duplicate entry — order number collision, retry
				if ($e->errorInfo[1] === 1062 && ++$attempt < 5) {
					usleep(random_int(10_000, 80_000)); // 10–80 ms jitter
					continue;
				}
				throw $e;
			}
		}

		return response()->json($order->load(['customer', 'loads']), 201);
	}

	private function attemptCreateOrder(array $validated, Request $request): Order
	{
		// Advisory lock serialises order-number generation across concurrent requests.
		// GET_LOCK is connection-level (not transaction-level), so it truly blocks
		// other requests until this one commits and releases.
		$lockName = 'laundry_order_number_' . now()->format('Ymd');
		DB::statement("SELECT GET_LOCK('{$lockName}', 10)");

		try {
			return DB::transaction(function () use ($validated, $request) {
			$loadsData = [];
			$subtotal  = 0;

			foreach ($validated['loads'] as $loadInput) {
				$service = Service::findOrFail($loadInput['service_id']);

				$lineTotal = round($service->price * $loadInput['quantity'], 2);
				$subtotal += $lineTotal;

				$loadsData[] = [
					'service_id'            => $service->id,
					'service_name_snapshot' => $service->name,
					'unit_price_snapshot'   => $service->price,
					'quantity'              => $loadInput['quantity'],
					'line_total'            => $lineTotal,
					'notes'                 => $loadInput['notes'] ?? null,
				];
			}

			$extraFees      = $validated['extra_fees'] ?? 0;
			$discountAmount = $validated['discount_amount'] ?? 0;
			$totalAmount    = round($subtotal + $extraFees - $discountAmount, 2);

			$order = Order::create([
				'branch_id'        => $this->branchId($request),
				'client_id'        => $validated['client_id'] ?? null,
				'customer_id'      => $validated['customer_id'],
				'user_id'          => $request->user()->id,
				'order_number'     => $this->generateOrderNumber(),
				'subtotal'         => $subtotal,
				'extra_fees'       => $extraFees,
				'discount_amount'  => $discountAmount,
				'total_amount'     => $totalAmount,
				'notes'            => $validated['notes'] ?? null,
				'estimated_ready_at' => $validated['estimated_ready_at'] ?? null,
			]);

			$order->loads()->createMany($loadsData);

			$this->loyaltyService->recordStamps($order->load('loads'));

			$freeLoadsToRedeem = (int) ($validated['loyalty_free_loads'] ?? 0);
			if ($freeLoadsToRedeem > 0 && $order->customer_id) {
				LoyaltyReward::where('customer_id', $order->customer_id)
					->where('branch_id', $order->branch_id)
					->whereNull('redeemed_at')
					->whereHas('rule', fn($q) => $q->where('reward_type', 'free_load'))
					->latest('earned_at')
					->limit($freeLoadsToRedeem)
					->get()
					->each(fn($r) => $this->loyaltyService->redeemReward($r, $order->id));
			}

			return $order;
		});
		} finally {
			DB::statement("SELECT RELEASE_LOCK('{$lockName}')");
		}
	}

	public function show(Order $order)
	{
		$order->load(['customer', 'loads', 'payments', 'user']);

		if ($order->customer) {
			$order->customer->loyalty_stamp_count = $this->loyaltyService->currentStampCount(
				$order->customer_id,
				$order->branch_id,
			);
			$activeRule = LoyaltyRule::where('branch_id', $order->branch_id)
				->active()
				->orderBy('every_n_stamps')
				->first();
			$order->customer->loyalty_cycle_size = $activeRule?->every_n_stamps;
		}

		return response()->json($order);
	}

	public function update(Request $request, Order $order)
	{
		$validated = $request->validate([
			'discount_amount'      => 'sometimes|numeric|min:0',
			'extra_fees'           => 'sometimes|numeric|min:0',
			'notes'                => 'sometimes|nullable|string',
			'status'               => 'sometimes|in:pending,ready,claimed,completed',
			'estimated_ready_at'   => 'sometimes|nullable|date',
			'delivery_scheduled_at' => 'sometimes|nullable|date',
		]);

		DB::transaction(function () use ($validated, $order) {
			$recalculate = isset($validated['discount_amount']) || isset($validated['extra_fees']);

			$order->fill($validated);

			if ($recalculate) {
				$order->total_amount = round(
					$order->subtotal + $order->extra_fees - $order->discount_amount,
					2
				);
			}

			$order->save();
		});

		return response()->json($order->load(['customer', 'loads', 'payments']));
	}

	public function updateStatus(Request $request, Order $order)
	{
		$validated = $request->validate([
			'status'             => 'required|in:pending,ready,claimed,completed',
			'estimated_ready_at' => 'sometimes|nullable|date',
		]);

		// Setting the status to what it already is is a no-op success — keeps
		// redundant calls (e.g. an auto-completed order, or a synced offline
		// queue replay) from failing.
		if ($validated['status'] === $order->status) {
			return response()->json($order->load(['customer', 'loads', 'payments']));
		}

		// Delivered orders skip "claimed" — driver already brought it to the customer.
		$forward = $order->delivered_at
			? ['pending' => 'ready', 'ready' => 'completed']
			: ['pending' => 'ready', 'ready' => 'claimed', 'claimed' => 'completed'];

		$backward = array_flip($forward);

		$next    = $forward[$order->status] ?? null;
		$prev    = $backward[$order->status] ?? null;
		$isAdmin = in_array($request->user()->role, ['super_admin', 'admin']);

		$isForward  = $validated['status'] === $next;
		$isBackward = $validated['status'] === $prev;

		if ($isBackward && ! $isAdmin) {
			return response()->json(['message' => 'Only admins can revert order status.'], 403);
		}

		if (! $isForward && ! $isBackward) {
			$message = $next
				? "Order is '{$order->status}', next allowed status is '{$next}'."
				: "Order is already at its final status '{$order->status}'.";

			return response()->json(['message' => $message], 422);
		}

		DB::transaction(function () use ($validated, $order, $isForward) {
			$order->fill($validated)->save();

			// Advancing forward to "claimed" on an already fully-paid order skips
			// the extra "Complete" click. Gated to forward moves so an admin
			// reverting completed -> claimed isn't bounced straight back.
			if ($isForward && $order->status === 'claimed') {
				$payments = $order->payments()->get();
				$netPaid  = $payments->where('type', 'payment')->sum('amount')
						  - $payments->where('type', 'refund')->sum('amount');
				if ($netPaid >= $order->total_amount) {
					$order->update(['status' => 'completed']);
				}
			}
		});

		return response()->json($order->load(['customer', 'loads', 'payments']));
	}

	public function destroy(Order $order)
	{
		$user = request()->user();

		if (! in_array($user->role, ['super_admin', 'admin'])) {
			if ($order->created_at->diffInMinutes(now()) > 15) {
				return response()->json([
					'message' => 'Orders can only be deleted within 15 minutes of creation.',
				], 403);
			}
		}

		DB::transaction(function () use ($order, $user) {
			// Reverse any loyalty stamps this order earned before removing it.
			$this->loyaltyService->reverseOrderStamps($order, $user->id);

			// Reverse total_spent: net of payments minus refunds.
			if ($order->customer_id) {
				$payments  = $order->payments()->get();
				$netPaid   = $payments->where('type', 'payment')->sum('amount')
				           - $payments->where('type', 'refund')->sum('amount');
				if ($netPaid > 0 && $order->customer) {
					$order->customer->decrement('total_spent', $netPaid);
				}
			}

			$order->loads()->each(fn($load) => $load->delete());
			$order->payments()->delete();
			$order->delete();
		});

		return response()->json(['message' => 'Order deleted.']);
	}

	public function markDelivered(Order $order)
	{
		if ($order->delivered_at) {
			return response()->json($order->load(['customer', 'loads', 'payments']));
		}

		$order->update(['delivered_at' => now()]);

		return response()->json($order->load(['customer', 'loads', 'payments']));
	}

	private function generateOrderNumber(): string
	{
		$date   = now()->format('Ymd');
		$prefix = "ORD-{$date}-";

		// withTrashed() ensures soft-deleted order numbers are still counted —
		// their rows stay in the table and hold the unique constraint.
		$last = Order::withTrashed()
			->where('order_number', 'like', "{$prefix}%")
			->orderBy('order_number', 'desc')
			->first();

		// Use strlen($prefix) offset, not -3, so sequences past 999 extract correctly.
		$sequence = $last
			? (int) substr($last->order_number, strlen($prefix)) + 1
			: 1;

		return $prefix . str_pad($sequence, 3, '0', STR_PAD_LEFT);
	}
}
