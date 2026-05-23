<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Service;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

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
		]);

		DB::transaction(function () use ($validated, $order) {
			$loadsData          = [];
			$addedTotal         = 0;
			$eligibleNewStamps  = 0.0;

			foreach ($validated['loads'] as $loadInput) {
				$service   = Service::findOrFail($loadInput['service_id']);
				$lineTotal = round($service->price * $loadInput['quantity'], 2);
				$addedTotal += $lineTotal;

				if ($service->is_loyalty_eligible) {
					$eligibleNewStamps += $loadInput['quantity'];
				}

				$loadsData[] = [
					'service_id'            => $service->id,
					'service_name_snapshot' => $service->name,
					'unit_price_snapshot'   => $service->price,
					'quantity'              => $loadInput['quantity'],
					'line_total'            => $lineTotal,
					'notes'                 => $loadInput['notes'] ?? null,
				];
			}

			$order->loads()->createMany($loadsData);

			$order->subtotal     = round($order->subtotal + $addedTotal, 2);
			$order->total_amount = round($order->subtotal + $order->extra_fees - $order->discount_amount, 2);
			$order->save();

			if ($order->customer_id) {
				$this->loyaltyService->awardStamps(
					$order->customer_id, $order->branch_id, $order->id,
					(int) floor($eligibleNewStamps)
				);
			}
		});

		return response()->json($order->fresh()->load(['customer', 'loads', 'payments']));
	}

}

