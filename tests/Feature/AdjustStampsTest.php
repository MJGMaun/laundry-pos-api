<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyStamp;
use App\Models\Order;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function adjustStampsSetup(): array
{
    $branch = Branch::create(['name' => 'Main', 'is_active' => true]);
    $user   = User::factory()->create(['role' => 'admin']);
    $user->branches()->attach($branch->id, ['is_primary' => true]);
    Sanctum::actingAs($user);

    $rule = LoyaltyRule::create([
        'branch_id'          => $branch->id,
        'every_n_stamps'     => 11,
        'reward_type'        => 'free_load',
        'reward_description' => 'Free wash',
        'is_active'          => true,
    ]);

    $customer = Customer::create(['branch_id' => $branch->id, 'name' => 'Adrian', 'phone' => '09170000000']);

    return [$branch, $rule, $customer];
}

it('revokes an unearned pending reward when stamps are manually removed below its threshold', function () {
    [$branch, $rule, $customer] = adjustStampsSetup();

    // Manually add 11 stamps -> crosses the threshold once -> 1 reward.
    $this->postJson("/api/customers/{$customer->id}/loyalty-stamps/adjust", [
        'mode' => 'add', 'stamps' => 11,
    ], ['X-Branch-Id' => $branch->id])->assertOk();
    expect(LoyaltyReward::where('customer_id', $customer->id)->whereNull('redeemed_at')->count())->toBe(1);

    // Manually remove those same 11 stamps -> no longer justifies the reward.
    $this->postJson("/api/customers/{$customer->id}/loyalty-stamps/adjust", [
        'mode' => 'add', 'stamps' => -11,
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    expect(LoyaltyReward::where('customer_id', $customer->id)->whereNull('redeemed_at')->count())->toBe(0);
});

it('does not revoke a reward already redeemed on an order when stamps are later removed', function () {
    [$branch, $rule, $customer] = adjustStampsSetup();

    $this->postJson("/api/customers/{$customer->id}/loyalty-stamps/adjust", [
        'mode' => 'add', 'stamps' => 11,
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    $order = Order::create([
        'branch_id'       => $branch->id,
        'customer_id'     => $customer->id,
        'user_id'         => auth()->id(),
        'order_number'    => 'T-001',
        'subtotal'        => 0,
        'extra_fees'      => 0,
        'discount_amount' => 0,
        'total_amount'    => 0,
    ]);

    $reward = LoyaltyReward::where('customer_id', $customer->id)->first();
    $reward->update(['redeemed_at' => now(), 'redeemed_on_order_id' => $order->id]);

    $this->postJson("/api/customers/{$customer->id}/loyalty-stamps/adjust", [
        'mode' => 'add', 'stamps' => -11,
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    expect(LoyaltyReward::find($reward->id)->redeemed_at)->not->toBeNull();
});

it('leaves a still-justified reward alone when a partial removal does not un-cross its threshold', function () {
    [$branch, $rule, $customer] = adjustStampsSetup();

    $this->postJson("/api/customers/{$customer->id}/loyalty-stamps/adjust", [
        'mode' => 'add', 'stamps' => 15,
    ], ['X-Branch-Id' => $branch->id])->assertOk();
    expect(LoyaltyReward::where('customer_id', $customer->id)->count())->toBe(1);

    // 15 -> 12 still crosses the 11 threshold once.
    $this->postJson("/api/customers/{$customer->id}/loyalty-stamps/adjust", [
        'mode' => 'add', 'stamps' => -3,
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    expect(LoyaltyReward::where('customer_id', $customer->id)->count())->toBe(1);
});
