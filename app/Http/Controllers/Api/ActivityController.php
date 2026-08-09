<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

// Super-admin cross-branch activity feed: recent orders with their loads,
// customer, branch, and the cashier who rang them up. Read-only; deleted
// orders can be included alongside live ones (their loads are soft-deleted
// with them, so loads are always fetched withTrashed).
class ActivityController extends Controller implements HasMiddleware
{
	public static function middleware(): array
	{
		return [
			new Middleware('role:super_admin'),
		];
	}

	public function orders(Request $request)
	{
		$perPage = min((int) ($request->per_page ?? 20), 500);

		$query = Order::with([
				'customer:id,name,phone',
				'branch:id,name',
				'user:id,name',
				'deletedBy:id,name',
				'loads' => fn ($q) => $q->withTrashed()->select(
					'id', 'order_id', 'service_name_snapshot', 'quantity', 'unit_price_snapshot', 'line_total'
				),
			])
			->latest();

		if ($request->boolean('include_deleted')) {
			$query->withTrashed();
		}

		if ($request->filled('branch_id')) {
			$query->where('branch_id', $request->branch_id);
		}

		if ($request->filled('search')) {
			$search = $request->search;
			$query->where(function ($q) use ($search) {
				$q->where('order_number', 'like', "%{$search}%")
				  ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$search}%"))
				  ->orWhereHas('loads', fn ($lq) => $lq->withTrashed()->where('service_name_snapshot', 'like', "%{$search}%"));
			});
		}

		if ($request->filled('date_from')) {
			$query->whereDate('created_at', '>=', $request->date_from);
		}
		if ($request->filled('date_to')) {
			$query->whereDate('created_at', '<=', $request->date_to);
		}

		return response()->json($query->paginate($perPage));
	}
}
