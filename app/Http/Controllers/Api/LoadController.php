<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Load;
use App\Models\Order;
use App\Models\Service;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LoadController extends Controller implements HasMiddleware
{
	public function __construct(private LoyaltyService $loyaltyService) {}

	public static function middleware(): array
	{
		return [
			new Middleware('role:admin,cashier,staff'),
		];
	}

	public function store(Request $request, Order $order)
	{
		if ($order->status === 'completed') {
			return response()->json(['message' => 'Cannot add loads to a completed order.'], 422);
		}

		$validated = $request->validate([
			'loads'                => 'required|array|min:1',
			'loads.*.service_id'   => 'required|exists:services,id',
			'loads.*.quantity'     => 'required|numeric|min:0.01',
			'loads.*.notes'        => 'nullable|string',
			'loads.*._key'         => 'nullable|string|max:64',
			'loads.*.parent_key'   => 'nullable|string|max:64',
			// An existing parent load this add-on attaches to (must be in this order).
			'loads.*.parent_load_id' => [
				'nullable',
				Rule::exists('loads', 'id')->where('order_id', $order->id),
			],
		]);

		DB::transaction(function () use ($validated, $order) {
			$resolved          = [];
			$addedTotal        = 0;
			$eligibleNewStamps = 0.0;

			foreach ($validated['loads'] as $loadInput) {
				$service   = Service::findOrFail($loadInput['service_id']);
				$lineTotal = round($service->price * $loadInput['quantity'], 2);
				$addedTotal += $lineTotal;

				if ($service->is_loyalty_eligible) {
					$eligibleNewStamps += $loadInput['quantity'];
				}

				$resolved[] = [
					'service'        => $service,
					'line_total'     => $lineTotal,
					'quantity'       => $loadInput['quantity'],
					'notes'          => $loadInput['notes'] ?? null,
					'_key'           => $loadInput['_key'] ?? null,
					'parent_key'     => $loadInput['parent_key'] ?? null,
					'parent_load_id' => $loadInput['parent_load_id'] ?? null,
				];
			}

			Load::createWithAddons($order, $resolved);

			$order->subtotal     = round($order->subtotal + $addedTotal, 2);
			$order->total_amount = round($order->subtotal + $order->extra_fees - $order->discount_amount, 2);
			$order->save();

			if ($order->customer_id) {
				$this->loyaltyService->awardStamps(
					$order->customer_id, $order->branch_id, $order->id,
					(int) floor($eligibleNewStamps)
				);

				// Newly awarded stamps may unlock free loads; redeem what this
				// order can absorb and recompute its discount from the cheapest
				// eligible loads (existing + newly added).
				$this->loyaltyService->reconcileFreeLoadDiscount($order);
			}
		});

		return response()->json($order->fresh()->load(['customer', 'loads.addons', 'payments']));
	}

}

