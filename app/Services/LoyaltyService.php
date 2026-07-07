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

    /**
     * Reverse the stamps an order earned (e.g. when the order is deleted).
     * Clamped so it never drives the balance below zero. Does not revoke
     * rewards already granted, mirroring manual negative adjustments.
     */
    public function reverseOrderStamps(Order $order, ?int $userId): void
    {
        if ($order->customer_id === null) {
            return;
        }

        $earned = (int) LoyaltyStamp::where('order_id', $order->id)
            ->where('branch_id', $order->branch_id)
            ->sum('stamps_earned');

        if ($earned <= 0) {
            return;
        }

        $current = $this->currentStampCount($order->customer_id, $order->branch_id);
        $remove  = min($earned, $current);

        if ($remove <= 0) {
            return;
        }

        LoyaltyStamp::create([
            'customer_id'   => $order->customer_id,
            'branch_id'     => $order->branch_id,
            'order_id'      => null,
            'stamps_earned' => -$remove,
            'note'          => "Reversed stamps from deleted order #{$order->order_number}",
            'created_by'    => $userId,
        ]);
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

    /**
     * Zero out every customer's stamp balance at a branch (used when starting a fresh program).
     * Inserts correcting negative entries — no data is deleted.
     */
    public function resetAllStamps(int $branchId, ?int $userId = null): void
    {
        $rows = LoyaltyStamp::where('branch_id', $branchId)
            ->selectRaw('customer_id, SUM(stamps_earned) as total')
            ->groupBy('customer_id')
            ->having('total', '>', 0)
            ->get();

        foreach ($rows as $row) {
            LoyaltyStamp::create([
                'customer_id'   => $row->customer_id,
                'branch_id'     => $branchId,
                'order_id'      => null,
                'stamps_earned' => -(int) $row->total,
                'note'          => 'Stamps reset — new loyalty program started',
                'created_by'    => $userId,
            ]);
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

    /**
     * Recompute an order's free-load loyalty discount from scratch and redeem any
     * pending free-load rewards the order can still absorb.
     *
     * A free load discounts one loyalty-eligible load unit; the reward always
     * covers the cheapest eligible loads across the whole order. Recomputing from
     * the current loads (rather than adding to the stored discount) keeps the
     * total correct and never double-discounts when loads are added over time.
     *
     * Assumes discount_amount is the loyalty discount only (the app has no other
     * discount source).
     */
    public function reconcileFreeLoadDiscount(Order $order): void
    {
        if ($order->customer_id === null) {
            return;
        }

        // Per-unit price of every loyalty-eligible load in the order, cheapest
        // first. Each unit is one redeemable free load (matching the POS/stamp
        // logic, which counts one stamp per eligible load unit).
        $order->load('loads.service');
        $eligibleUnits = [];
        foreach ($order->loads as $load) {
            if (! $load->service || ! $load->service->is_loyalty_eligible) {
                continue;
            }
            $units = max(1, (int) floor((float) $load->quantity));
            for ($i = 0; $i < $units; $i++) {
                $eligibleUnits[] = (float) $load->unit_price_snapshot;
            }
        }
        sort($eligibleUnits);
        $poolSize = count($eligibleUnits);

        // Free-load rewards already tied to this order (e.g. redeemed at checkout).
        $redeemedHere = LoyaltyReward::where('redeemed_on_order_id', $order->id)
            ->whereHas('rule', fn($q) => $q->where('reward_type', 'free_load'))
            ->count();

        // Pending free-load rewards we could still redeem for this customer.
        $pending = LoyaltyReward::where('customer_id', $order->customer_id)
            ->where('branch_id', $order->branch_id)
            ->whereNull('redeemed_at')
            ->whereHas('rule', fn($q) => $q->where('reward_type', 'free_load'))
            ->latest('earned_at')
            ->get();

        // Only redeem as many as the order still has eligible loads to cover.
        $toRedeem = max(0, min($pending->count(), $poolSize - $redeemedHere));
        foreach ($pending->take($toRedeem) as $reward) {
            $reward->redeemed_at          = now();
            $reward->redeemed_on_order_id = $order->id;
            $reward->save();
        }

        $freeLoads = min($poolSize, $redeemedHere + $toRedeem);
        $discount  = array_sum(array_slice($eligibleUnits, 0, $freeLoads));

        $order->discount_amount = round($discount, 2);
        $order->total_amount    = round($order->subtotal + $order->extra_fees - $order->discount_amount, 2);
        $order->save();
    }
}
