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

        $stamps = (int) floor($order->loads()->sum('quantity'));

        $this->awardStamps($order->customer_id, $order->branch_id, $order->id, $stamps);
    }

    public function awardStamps(int $customerId, int $branchId, int $orderId, int $stamps): void
    {
        if ($stamps <= 0) {
            return;
        }

        LoyaltyStamp::create([
            'customer_id'   => $customerId,
            'branch_id'     => $branchId,
            'order_id'      => $orderId,
            'stamps_earned' => $stamps,
        ]);

        $total    = LoyaltyStamp::where('customer_id', $customerId)
                        ->where('branch_id', $branchId)
                        ->sum('stamps_earned');
        $previous = $total - $stamps;

        foreach (LoyaltyRule::where('branch_id', $branchId)->active()->get() as $rule) {
            $newRewards = (int) floor($total / $rule->every_n_stamps)
                        - (int) floor($previous / $rule->every_n_stamps);

            for ($i = 0; $i < $newRewards; $i++) {
                LoyaltyReward::create([
                    'customer_id' => $customerId,
                    'branch_id'   => $branchId,
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
