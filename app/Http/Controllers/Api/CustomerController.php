<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class CustomerController extends Controller implements HasMiddleware
{
	public static function middleware(): array
	{
		return [
			new Middleware('role:admin,cashier,staff', only: ['store', 'update']),
			new Middleware('role:admin', only: ['destroy']),
		];
	}

	public function index(Request $request)
	{
		$query = Customer::query();

		$this->scopeToBranch($query, $request);

		if ($request->has('search')) {
			$search = $request->search;
			$query->where(function ($q) use ($search) {
				$q->where('name', 'like', "%{$search}%")
					->orWhere('phone', 'like', "%{$search}%")
					->orWhere('email', 'like', "%{$search}%");
			});
		}

		if ($request->has('updated_after')) {
			$query->where('updated_at', '>', $request->updated_after);
		}

		$perPage = min((int) ($request->per_page ?? 15), 500);

		return response()->json($query->latest()->paginate($perPage));
	}

	public function store(Request $request)
	{
		$branchId = $this->branchId($request);

		$validated = $request->validate([
			'name'                => 'required|string|max:255',
			'username'            => 'nullable|string|max:255',
			'phone'               => [
				'required', 'string', 'max:20',
				Rule::unique('customers', 'phone')->where('branch_id', $branchId),
			],
			'email'               => 'nullable|email|unique:customers,email',
			'address'             => 'nullable|string',
			'notes'               => 'nullable|string',
			'loyalty_card_number' => 'nullable|string|unique:customers,loyalty_card_number',
			'loyalty_tier_id'     => 'nullable|exists:loyalty_tiers,id',
		]);

		$validated['branch_id'] = $branchId;

		return response()->json(Customer::create($validated), 201);
	}

	public function show(Customer $customer)
	{
		return response()->json($customer->load('loyaltyTier'));
	}

	public function update(Request $request, Customer $customer)
	{
		$branchId = $this->branchId($request);

		$validated = $request->validate([
			'name'                => 'sometimes|string|max:255',
			'username'            => 'nullable|string|max:255',
			'phone'               => [
				'sometimes', 'string', 'max:20',
				Rule::unique('customers', 'phone')->where('branch_id', $branchId)->ignore($customer->id),
			],
			'email'               => 'nullable|email|unique:customers,email,' . $customer->id,
			'address'             => 'nullable|string',
			'notes'               => 'nullable|string',
			'loyalty_card_number' => 'nullable|string|unique:customers,loyalty_card_number,' . $customer->id,
			'loyalty_tier_id'     => 'nullable|exists:loyalty_tiers,id',
			'loyalty_points'      => 'sometimes|integer|min:0',
			'total_visits'        => 'sometimes|integer|min:0',
			'total_spent'         => 'sometimes|numeric|min:0',
		]);

		$customer->update($validated);

		return response()->json($customer);
	}

	public function destroy(Customer $customer)
	{
		$customer->delete();

		return response()->json(['message' => 'Customer deleted successfully']);
	}
}
