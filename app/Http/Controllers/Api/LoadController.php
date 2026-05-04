<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Load;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class LoadController extends Controller implements HasMiddleware
{
	public static function middleware(): array
	{
		return [
			new Middleware('role:admin,cashier,staff'),
		];
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
