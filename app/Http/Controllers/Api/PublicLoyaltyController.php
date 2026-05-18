<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyStamp;

class PublicLoyaltyController extends Controller
{
    public function show(string $username)
    {
        $customer = Customer::where('username', $username)->firstOrFail();
        $branchId = $customer->branch_id;

        $totalStamps = (int) LoyaltyStamp::where('customer_id', $customer->id)
            ->where('branch_id', $branchId)
            ->sum('stamps_earned');

        $rules = LoyaltyRule::where('branch_id', $branchId)
            ->active()
            ->orderBy('every_n_stamps')
            ->get(['id', 'every_n_stamps', 'reward_type', 'reward_description']);

        $pendingRewards = LoyaltyReward::with('rule:id,reward_description,reward_type')
            ->where('customer_id', $customer->id)
            ->where('branch_id', $branchId)
            ->pending()
            ->latest('earned_at')
            ->get(['id', 'rule_id', 'earned_at']);

        $branch = Branch::find($branchId, ['name', 'address']);

        return response()->json([
            'customer' => [
                'name'                => $customer->name,
                'loyalty_card_number' => $customer->loyalty_card_number,
            ],
            'branch'          => $branch,
            'total_stamps'    => $totalStamps,
            'rules'           => $rules,
            'pending_rewards' => $pendingRewards,
        ]);
    }
}
