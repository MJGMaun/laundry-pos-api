<?php

namespace App\Services;

use App\Models\LoyaltyReward;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyStamp;
use App\Models\Order;
use Illuminate\Support\Collection;

class LoyaltyService
{
    public function recordStamps(Order $order): void
    {
        if ($order->customer_id === null) {
            return;
        }

        $loadsCount = $order->loads()->count();

        LoyaltyStamp::create([
            'customer_id'   => $order->customer_id,
            'branch_id'     => $order->branch_id,
            'order_id'      => $order->id,
            'stamps_earned' => $loadsCount,
        ]);

        $total    = LoyaltyStamp::where('customer_id', $order->customer_id)
                        ->where('branch_id', $order->branch_id)
                        ->sum('stamps_earned');
        $previous = $total - $loadsCount;

        $rules = LoyaltyRule::where('branch_id', $order->branch_id)->active()->get();

        foreach ($rules as $rule) {
            $prevMilestone = (int) floor($previous / $rule->every_n_stamps);
            $newMilestone  = (int) floor($total    / $rule->every_n_stamps);

            for ($i = 0; $i < $newMilestone - $prevMilestone; $i++) {
                LoyaltyReward::create([
                    'customer_id' => $order->customer_id,
                    'branch_id'   => $order->branch_id,
                    'rule_id'     => $rule->id,
                    'earned_at'   => now(),
                ]);
            }
        }
    }

    public function getPendingRewards(int $customerId, int $branchId): Collection
    {
        return LoyaltyReward::with('rule')
            ->where('customer_id', $customerId)
            ->where('branch_id', $branchId)
            ->pending()
            ->latest('earned_at')
            ->get();
    }

    public function redeemReward(LoyaltyReward $reward, int $orderId): void
    {
        if ($reward->redeemed_at !== null) {
            throw new \InvalidArgumentException('Reward has already been redeemed.');
        }

        $reward->redeemed_at           = now();
        $reward->redeemed_on_order_id  = $orderId;
        $reward->save();
    }
}
