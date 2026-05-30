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

        $loads = $order->loads()->with('service')->get();

        // Count stamps only from eligible services; ineligible ones contribute 0 but don't block
        $stamps = (int) floor(
            $loads->filter(fn($l) => $l->service && $l->service->is_loyalty_eligible)
                  ->sum('quantity')
        );

        $this->awardStamps($order->customer_id, $order->branch_id, $order->id, $stamps);
    }

    public function awardStamps(int $customerId, int $branchId, int $orderId, int $stamps): void
    {
        if ($stamps <= 0) {
            return;
        }

        $previous = $this->currentStampCount($customerId, $branchId);

        LoyaltyStamp::create([
            'customer_id'   => $customerId,
            'branch_id'     => $branchId,
            'order_id'      => $orderId,
            'stamps_earned' => $stamps,
        ]);

        $this->generateRewards($customerId, $branchId, $previous, $previous + $stamps);
    }

    /**
     * Current net stamp balance for a customer at a branch (includes manual adjustments).
     */
    public function currentStampCount(int $customerId, int $branchId): int
    {
        return (int) LoyaltyStamp::where('customer_id', $customerId)
            ->where('branch_id', $branchId)
            ->sum('stamps_earned');
    }

    /**
     * Manually adjust a customer's stamp balance (admin/super-admin only).
     * $delta may be negative to remove stamps. Returns the new total.
     */
    public function adjustStamps(int $customerId, int $branchId, int $delta, ?string $note, ?int $userId): int
    {
        $current = $this->currentStampCount($customerId, $branchId);

        if ($delta === 0) {
            return $current;
        }

        if ($current + $delta < 0) {
            throw new \InvalidArgumentException('Adjustment would make the stamp total negative.');
        }

        LoyaltyStamp::create([
            'customer_id'   => $customerId,
            'branch_id'     => $branchId,
            'order_id'      => null,
            'stamps_earned' => $delta,
            'note'          => $note,
            'created_by'    => $userId,
        ]);

        // Only positive adjustments can cross reward thresholds.
        if ($delta > 0) {
            $this->generateRewards($customerId, $branchId, $current, $current + $delta);
        }

        return $current + $delta;
    }

    private function generateRewards(int $customerId, int $branchId, int $previousTotal, int $newTotal): void
    {
        foreach (LoyaltyRule::where('branch_id', $branchId)->active()->get() as $rule) {
            $newRewards = (int) floor($newTotal / $rule->every_n_stamps)
                        - (int) floor($previousTotal / $rule->every_n_stamps);

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
