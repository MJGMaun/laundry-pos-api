<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use App\Models\Service;
use App\Services\ServiceService;

class ServiceController extends Controller implements HasMiddleware
{
	protected $serviceService;

	public function __construct(ServiceService $serviceService)
	{
		$this->serviceService = $serviceService;
	}

	public function index(Request $request)
	{
		$filters = $request->all();

		$branchId = $request->header('X-Branch-Id');
		if ($branchId) {
			$filters['branch_id'] = $branchId;
		}

		$services = $this->serviceService->list($filters);

		return response()->json($services);
	}

	public function store(Request $request)
	{
		$validated = $request->validate([
			'name' => 'required|string|max:255',
			'category_id' => 'nullable|exists:service_categories,id',
			'pricing_type' => 'required|in:per_kilo,per_piece,flat_rate',
			'price' => 'required|numeric|min:0',
			'is_active' => 'boolean',
			'is_loyalty_eligible' => 'boolean',
		]);

		$branchId = $request->header('X-Branch-Id');
		if ($branchId) {
			$validated['branch_id'] = $branchId;
		}

		$service = $this->serviceService->create($validated);

		return response()->json($service, 201);
	}

	public function show(Service $service)
	{
		return response()->json($service);
	}

	public function update(Request $request, Service $service)
	{
		$validated = $request->validate([
			'name' => 'sometimes|string|max:255',
			'category_id' => 'nullable|exists:service_categories,id',
			'pricing_type' => 'sometimes|in:per_kilo,per_piece,flat_rate',
			'price' => 'sometimes|numeric|min:0',
			'is_active' => 'boolean',
			'is_loyalty_eligible' => 'boolean',
		]);

		$updated = $this->serviceService->update($service, $validated);

		return response()->json($updated);
	}

	public function destroy(Service $service)
	{
		$this->serviceService->delete($service);

		return response()->json(['message' => 'Deleted successfully']);
	}

	// Optional: toggle active
	public function toggle(Service $service)
	{
		$updated = $this->serviceService->toggle($service);

		return response()->json($updated);
	}

	public static function middleware(): array
	{
		return [
			new Middleware('role:admin', only: ['store', 'destroy', 'update', 'toggle']),
			// new Middleware('role:admin,cashier', only: []),
		];
	}
}
