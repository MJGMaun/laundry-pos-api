<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyRule;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class LoyaltyRuleController extends Controller implements HasMiddleware
{
    public function __construct(private LoyaltyService $loyaltyService) {}

    public static function middleware(): array
    {
        return [
            new Middleware('page:loyalty,edit', only: ['store', 'update', 'destroy']),
        ];
    }

    public function index(Request $request)
    {
        $rules = LoyaltyRule::where('branch_id', $this->branchId($request))
            ->orderBy('every_n_stamps')
            ->get();

        return response()->json($rules);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'every_n_stamps'     => 'required|integer|min:1',
            'reward_type'        => 'required|in:free_load,free_item,fixed_discount',
            'reward_amount'      => 'required_if:reward_type,fixed_discount|nullable|numeric|min:0.01',
            'reward_description' => 'required|string|max:200',
            'service_id'         => 'nullable|exists:services,id',
            'is_active'          => 'boolean',
            'reset_stamps'       => 'boolean',
        ]);

        $branchId = $this->branchId($request);

        if (!empty($validated['reset_stamps'])) {
            $this->loyaltyService->resetAllStamps($branchId, $request->user()->id);
        }

        unset($validated['reset_stamps']);

        $rule = LoyaltyRule::create(array_merge($validated, ['branch_id' => $branchId]));

        return response()->json($rule, 201);
    }

    public function update(Request $request, LoyaltyRule $loyaltyRule)
    {
        $validated = $request->validate([
            'every_n_stamps'     => 'sometimes|integer|min:1',
            'reward_type'        => 'sometimes|in:free_load,free_item,fixed_discount',
            'reward_amount'      => 'nullable|numeric|min:0.01',
            'reward_description' => 'sometimes|string|max:200',
            'service_id'         => 'nullable|exists:services,id',
            'is_active'          => 'boolean',
        ]);

        // A partial update can switch the type without resending the amount, so
        // check the resulting rule rather than the payload — a fixed discount
        // with no amount is worth nothing and would silently redeem for ₱0.
        $type   = $validated['reward_type'] ?? $loyaltyRule->reward_type;
        $amount = array_key_exists('reward_amount', $validated)
            ? $validated['reward_amount']
            : $loyaltyRule->reward_amount;

        if ($type === 'fixed_discount' && (float) $amount <= 0) {
            return response()->json([
                'message' => 'A fixed discount reward needs an amount greater than zero.',
                'errors'  => ['reward_amount' => ['The reward amount field is required.']],
            ], 422);
        }

        $loyaltyRule->update($validated);

        return response()->json($loyaltyRule);
    }

    public function destroy(LoyaltyRule $loyaltyRule)
    {
        $loyaltyRule->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }
}
