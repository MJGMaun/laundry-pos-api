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
			$loadsData  = [];
			$addedTotal = 0;

			foreach ($validated['loads'] as $loadInput) {
				$service   = Service::findOrFail($loadInput['service_id']);
				$lineTotal = round($service->price * $loadInput['quantity'], 2);
				$addedTotal += $lineTotal;

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
				$newStamps = (int) floor(collect($validated['loads'])->sum('quantity'));
				$this->loyaltyService->awardStamps($order->customer_id, $order->branch_id, $order->id, $newStamps);
			}
		});

		return response()->json($order->fresh()->load(['customer', 'loads', 'payments']));
	}

	private const TRANSITIONS = [
		'in_process' => 'ready',
		'ready'      => 'picked_up',
	];

	public function updateStatus(Request $request, Load $load)
	{
		$validated = $request->validate([
			'status' => 'required|in:in_process,ready,picked_up',
		]);

		$next = self::TRANSITIONS[$load->status] ?? null;

		if ($validated['status'] !== $next) {
			$message = $next
				? "Load is '{$load->status}', next allowed status is '{$next}'."
				: "Load is already at its final status '{$load->status}'.";

			return response()->json(['message' => $message], 422);
		}

		DB::transaction(function () use ($validated, $load) {
			$load->status = $validated['status'];
			$load->save();

			// Auto-update order to 'ready' when all its loads are ready
			if ($validated['status'] === 'ready') {
				$order = $load->order;

				$allReady = !$order->loads()
					->where('status', '!=', 'ready')
					->exists();

				if ($allReady && !in_array($order->status, ['ready', 'completed'])) {
					$order->status = 'ready';
					$order->save();
				}
			}
		});

		return response()->json($load->fresh()->load('order'));
	}
}
