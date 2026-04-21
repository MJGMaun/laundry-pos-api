<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller implements HasMiddleware
{
	public static function middleware(): array
	{
		return [
			new Middleware('role:admin,cashier,staff', only: ['store']),
			new Middleware('role:admin', only: ['destroy'])
		];
	}

	public function index(Request $request)
	{
		$query = Order::with(['customer', 'loads']);

		if ($request->has('status')) {
			$query->where('status', $request->status);
		}

		if ($request->has('date')) {
			$query->whereDate('created_at', $request->date);
		}

		if ($request->has('customer_id')) {
			$query->where('customer_id', $request->customer_id);
		}

		$orders = $query->latest()->paginate(15);

		return response()->json($orders);
	}

	public function store(Request $request)
	{
		$validated = $request->validate([
			'customer_id'          => 'required|exists:customers,id',
			'loads'                => 'required|array|min:1',
			'loads.*.service_id'   => 'required|exists:services,id',
			'loads.*.quantity'     => 'required|numeric|min:0.01',
			'loads.*.notes'        => 'nullable|string',
			'extra_fees'           => 'nullable|numeric|min:0',
			'discount_amount'      => 'nullable|numeric|min:0',
			'notes'                => 'nullable|string',
			'estimated_ready_at'   => 'nullable|date',
		]);

		$order = DB::transaction(function () use ($validated, $request) {
			$loadsData = [];
			$subtotal = 0;

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

			return $order;
		});

		return response()->json($order->load(['customer', 'loads']), 201);
	}

	public function show(Order $order)
	{
		return response()->json(
			$order->load(['customer', 'loads', 'payments', 'user'])
		);
	}

	public function destroy(Order $order)
	{
		DB::transaction(function () use ($order) {
			$order->loads()->each(fn($load) => $load->delete());
			$order->delete();
		});

		return response()->json(['message' => 'Deleted successfully']);
	}

	private function generateOrderNumber(): string
	{
		$date   = now()->format('Ymd');
		$prefix = "ORD-{$date}-";

		$last = Order::where('order_number', 'like', "{$prefix}%")
			->orderBy('order_number', 'desc')
			->lockForUpdate()
			->first();

		$sequence = $last ? ((int) substr($last->order_number, -3)) + 1 : 1;

		return $prefix . str_pad($sequence, 3, '0', STR_PAD_LEFT);
	}
}
