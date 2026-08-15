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

        // Removing stamps can un-cross a reward threshold just like an order
        // delete does — revoke any pending reward the new total no longer
        // justifies. Adding stamps can only unlock new rewards, never revoke one.
        if ($delta > 0) {
            $this->generateRewards($customerId, $branchId, $current, $current + $delta);
        } else {
            $this->revokeUncrossedRewards($customerId, $branchId, $current, $current + $delta);
        }

        return $current + $delta;
    }

    /**
     * Undo everything an order did to the customer's loyalty state (used when
     * the order is deleted): rewards it redeemed go back to pending, its stamps
     * are reversed, and unspent rewards its stamps unlocked are revoked. This
     * keeps delete-and-retry idempotent — re-ringing the same order neither
     * loses the customer's reward nor double-grants one.
     */
    public function reverseOrderLoyalty(Order $order, ?int $userId): void
    {
        if ($order->customer_id === null) {
            return;
        }

        // Un-spend rewards redeemed on this order first, so a reward both
        // earned and spent here becomes pending and is then revoked below.
        LoyaltyReward::where('redeemed_on_order_id', $order->id)
            ->whereNotNull('redeemed_at')
            ->update(['redeemed_at' => null, 'redeemed_on_order_id' => null]);

        $this->reverseOrderStamps($order, $userId);
    }

    /**
     * Reverse the stamps an order earned (e.g. when the order is deleted).
     * Clamped so it never drives the balance below zero. Pending rewards whose
     * threshold is un-crossed by the removal are revoked; rewards already
     * spent on another order stay spent.
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

        $this->revokeUncrossedRewards($order->customer_id, $order->branch_id, $current, $current - $remove);
    }

    /**
     * Delete pending rewards whose stamp threshold is no longer crossed after
     * stamps were removed. Only pending rewards are touched — a reward already
     * spent on another order stays spent (mirrors the stamp clamping).
     */
    private function revokeUncrossedRewards(int $customerId, int $branchId, int $previousTotal, int $newTotal): void
    {
        foreach (LoyaltyRule::where('branch_id', $branchId)->active()->get() as $rule) {
            $lost = (int) floor($previousTotal / $rule->every_n_stamps)
                  - (int) floor($newTotal / $rule->every_n_stamps);

            if ($lost <= 0) {
                continue;
            }

            LoyaltyReward::where('customer_id', $customerId)
                ->where('branch_id', $branchId)
                ->where('rule_id', $rule->id)
                ->whereNull('redeemed_at')
                ->latest('earned_at')
                ->limit($lost)
                ->get()
                ->each->delete();
        }
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
     * Per-unit price of every loyalty-eligible load in the order, cheapest
     * first. Each unit is one redeemable free load (matching the POS/stamp
     * logic, which counts one stamp per eligible load unit).
     */
    private function eligibleUnits(Order $order): array
    {
        $order->load('loads.service');

        $units = [];
        foreach ($order->loads as $load) {
            if (! $load->service || ! $load->service->is_loyalty_eligible) {
                continue;
            }
            $count = max(1, (int) floor((float) $load->quantity));
            for ($i = 0; $i < $count; $i++) {
                $units[] = (float) $load->unit_price_snapshot;
            }
        }
        sort($units);

        return $units;
    }

    /**
     * Recompute an order's loyalty discount from scratch and redeem any pending
     * rewards the order can still absorb.
     *
     * Two reward types put money back: a free load covers one loyalty-eligible
     * load unit (always the cheapest remaining, so the reward takes the smallest
     * bite), and a fixed discount takes its rule's flat peso amount. Either way
     * the total is capped at the eligible-load value of the order — the reward
     * discounts the services that earn stamps, never fees or ineligible items,
     * and it never turns into change.
     *
     * Recomputing from the current loads (rather than adding to the stored
     * discount) keeps the total correct and never double-discounts when loads
     * are added over time.
     *
     * discount_amount is the loyalty discount only — admin additional discounts
     * live in manual_discount_amount so this recompute never wipes them.
     */
    public function reconcileLoyaltyDiscount(Order $order): void
    {
        if ($order->customer_id === null) {
            return;
        }

        $units    = $this->eligibleUnits($order);
        $cap      = array_sum($units);
        $nextUnit = 0;   // cheapest eligible unit a free load hasn't claimed yet
        $discount = 0.0;

        // What this reward is worth against what's left, or null if it doesn't
        // fit. Free loads consume a unit; fixed discounts take their amount.
        $valueOf = function (LoyaltyReward $reward) use (&$nextUnit, &$discount, $units, $cap): ?float {
            $rule = $reward->rule;
            if (! $rule) {
                return null;
            }

            if ($rule->reward_type === 'free_load') {
                if ($nextUnit >= count($units)) {
                    return null;
                }
                $value = $units[$nextUnit];
            } else {
                $value = (float) $rule->reward_amount;
                if ($value <= 0) {
                    return null;
                }
            }

            // A reward is spent whole or not at all — no partial redemption, so
            // an order too small to absorb it leaves it pending for next time.
            if (round($discount + $value, 2) > round($cap, 2)) {
                return null;
            }

            if ($rule->reward_type === 'free_load') {
                $nextUnit++;
            }

            return $value;
        };

        // Rewards already tied to this order (e.g. redeemed at checkout) are
        // already spent, so they price first and keep their claim on the
        // cheapest units.
        $redeemedHere = LoyaltyReward::with('rule')
            ->where('redeemed_on_order_id', $order->id)
            ->whereHas('rule', fn($q) => $q->whereIn('reward_type', LoyaltyRule::DISCOUNT_TYPES))
            ->get();

        foreach ($redeemedHere as $reward) {
            $discount += $valueOf($reward) ?? 0.0;
        }

        // Then anything still pending that the order can still absorb.
        $pending = LoyaltyReward::with('rule')
            ->where('customer_id', $order->customer_id)
            ->where('branch_id', $order->branch_id)
            ->whereNull('redeemed_at')
            ->whereHas('rule', fn($q) => $q->whereIn('reward_type', LoyaltyRule::DISCOUNT_TYPES))
            ->latest('earned_at')
            ->get();

        foreach ($pending as $reward) {
            $value = $valueOf($reward);
            if ($value === null) {
                continue;
            }

            $discount += $value;
            $reward->redeemed_at          = now();
            $reward->redeemed_on_order_id = $order->id;
            $reward->save();
        }

        $order->discount_amount = round($discount, 2);
        $order->total_amount    = round(
            $order->subtotal + $order->extra_fees - $order->discount_amount - $order->manual_discount_amount,
            2
        );
        $order->save();
    }
}
