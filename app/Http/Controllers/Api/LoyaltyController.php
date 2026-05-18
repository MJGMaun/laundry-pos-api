<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyStamp;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    public function __construct(private LoyaltyService $loyaltyService) {}

    public function pendingRewards(Request $request, Customer $customer)
    {
        $branchId = $this->branchId($request);

        $totalStamps = LoyaltyStamp::where('customer_id', $customer->id)
            ->where('branch_id', $branchId)
            ->sum('stamps_earned');

        $rules = LoyaltyRule::where('branch_id', $branchId)
            ->active()
            ->orderBy('every_n_stamps')
            ->get(['id', 'every_n_stamps', 'reward_type', 'reward_description']);

        $rewards = $this->loyaltyService->getPendingRewards($customer->id, $branchId);

        return response()->json([
            'total_stamps'    => (int) $totalStamps,
            'rules'           => $rules,
            'pending_rewards' => $rewards,
        ]);
    }

    public function redeemReward(Request $request, LoyaltyReward $reward)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
        ]);

        try {
            $this->loyaltyService->redeemReward($reward, $validated['order_id']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($reward->load('rule'));
    }
}
