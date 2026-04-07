<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use App\Models\Customer;

class CustomerController extends Controller implements HasMiddleware
{
	public static function middleware(): array
	{
		return [
			new Middleware('role:admin,cashier,staff', only: ['store', 'update']),
			new Middleware('role:admin', only: ['destroy']),
		];
	}

	/**
	 * Display a listing of the resource.
	 */
	public function index(Request $request)
	{
		$query = Customer::query();

		if ($request->has('search')) {
			$search = $request->search;
			$query->where('name', 'like', "%{$search}%")
				->orWhere('phone', 'like', "%{$search}%")
				->orWhere('email', 'like', "%{$search}%");
		}

		$customers = $query->latest()->paginate(15);

		return response()->json($customers);
	}

	/**
	 * Store a newly created resource in storage.
	 */
	public function store(Request $request)
	{
		$validated = $request->validate([
			'name' => 'required|string|max:255',
			'phone' => 'required|string|max:20|unique:customers,phone',
			'email' => 'nullable|email|unique:customers,email',
			'address' => 'nullable|string',
			'loyalty_card_number' => 'nullable|string|unique:customers,loyalty_card_number',
			'loyalty_tier_id' => 'nullable|exists:loyalty_tiers,id',
		]);

		$customer = Customer::create($validated);

		return response()->json($customer, 201);
	}

	/**
	 * Display the specified resource.
	 */
	public function show(Customer $customer)
	{
		return response()->json($customer->load('loyaltyTier'));
	}

	/**
	 * Update the specified resource in storage.
	 */
	public function update(Request $request, Customer $customer)
	{
		$validated = $request->validate([
			'name' => 'sometimes|string|max:255',
			'phone' => 'sometimes|string|max:20|unique:customers,phone,' . $customer->id,
			'email' => 'nullable|email|unique:customers,email,' . $customer->id,
			'address' => 'nullable|string',
			'loyalty_card_number' => 'nullable|string|unique:customers,loyalty_card_number,' . $customer->id,
			'loyalty_tier_id' => 'nullable|exists:loyalty_tiers,id',
			'loyalty_points' => 'sometimes|integer|min:0',
			'total_visits' => 'sometimes|integer|min:0',
			'total_spent' => 'sometimes|numeric|min:0',
		]);

		$customer->update($validated);

		return response()->json($customer);
	}

	/**
	 * Remove the specified resource from storage.
	 */
	public function destroy(Customer $customer)
	{
		$customer->delete();

		return response()->json(['message' => 'Customer deleted successfully']);
	}
}
