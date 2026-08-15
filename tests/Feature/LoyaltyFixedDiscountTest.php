<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function fixedDiscountService(string $name, float $price, bool $eligible = true): Service
{
    $category = ServiceCategory::create(['name' => $name . ' Cat', 'load_rule' => 'quantity']);

    return Service::create([
        'category_id'         => $category->id,
        'name'                => $name,
        'pricing_type'        => 'flat_rate',
        'price'               => $price,
        'is_active'           => true,
        'is_loyalty_eligible' => $eligible,
    ]);
}

/**
 * Branch with a "₱X off every 2 stamps" rule, plus an empty order to add loads to.
 *
 * @return array{0: Branch, 1: Order, 2: User}
 */
function fixedDiscountSetup(float $amount = 100): array
{
    $branch = Branch::create(['name' => 'Main', 'is_active' => true]);
    $user   = User::factory()->create(['role' => 'admin']);
    $user->branches()->attach($branch->id, ['is_primary' => true]);
    Sanctum::actingAs($user);

    $customer = Customer::create(['branch_id' => $branch->id, 'name' => 'Juan', 'phone' => '09170000000']);

    LoyaltyRule::create([
        'branch_id'          => $branch->id,
        'every_n_stamps'     => 2,
        'reward_type'        => 'fixed_discount',
        'reward_amount'      => $amount,
        'reward_description' => "₱{$amount} off",
        'is_active'          => true,
    ]);

    $order = Order::create([
        'branch_id'       => $branch->id,
        'customer_id'     => $customer->id,
        'user_id'         => $user->id,
        'order_number'    => 'T-001',
        'subtotal'        => 0,
        'extra_fees'      => 0,
        'discount_amount' => 0,
        'total_amount'    => 0,
    ]);

    return [$branch, $order, $user];
}

it('discounts the flat rule amount rather than the cheapest load', function () {
    [$branch, $order] = fixedDiscountSetup(100);

    $pricey = fixedDiscountService('Pricey wash', 200);
    $cheap  = fixedDiscountService('Cheap wash', 50);

    // 2 eligible loads => 2 stamps => 1 reward.
    $this->postJson("/api/orders/{$order->id}/loads", [
        'loads' => [
            ['service_id' => $pricey->id, 'quantity' => 1],
            ['service_id' => $cheap->id,  'quantity' => 1],
        ],
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    $order->refresh();

    // A free load would have taken 50 (the cheapest); this takes the flat 100.
    expect((float) $order->discount_amount)->toBe(100.0);
    expect((float) $order->subtotal)->toBe(250.0);
    expect((float) $order->total_amount)->toBe(150.0);
    expect(LoyaltyReward::where('redeemed_on_order_id', $order->id)->count())->toBe(1);
});

it('leaves the reward pending when the order is too small to absorb it', function () {
    [$branch, $order] = fixedDiscountSetup(100);

    $small = fixedDiscountService('Small wash', 30);

    // 2 stamps earn the reward, but 60 of eligible value cannot cover 100.
    $this->postJson("/api/orders/{$order->id}/loads", [
        'loads' => [['service_id' => $small->id, 'quantity' => 2]],
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    $order->refresh();

    expect((float) $order->discount_amount)->toBe(0.0);
    expect((float) $order->total_amount)->toBe(60.0);
    expect(LoyaltyReward::whereNull('redeemed_at')->count())->toBe(1);
});

it('never discounts more than the loads that earn stamps', function () {
    [$branch, $order] = fixedDiscountSetup(100);

    $eligible = fixedDiscountService('Wash', 60, true);
    $addon    = fixedDiscountService('Fold', 500, false);

    $this->postJson("/api/orders/{$order->id}/loads", [
        'loads' => [
            ['service_id' => $eligible->id, 'quantity' => 2],
            ['service_id' => $addon->id,    'quantity' => 1],
        ],
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    $order->refresh();

    // 120 of eligible value covers the 100 reward; the 500 non-eligible load
    // inflates the bill but is not something loyalty may discount.
    expect((float) $order->discount_amount)->toBe(100.0);
    expect((float) $order->subtotal)->toBe(620.0);
    expect((float) $order->total_amount)->toBe(520.0);
});

it('does not double-discount when more loads are added later', function () {
    [$branch, $order] = fixedDiscountSetup(100);

    $wash = fixedDiscountService('Wash', 150);

    $this->postJson("/api/orders/{$order->id}/loads", [
        'loads' => [['service_id' => $wash->id, 'quantity' => 2]],
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    $order->refresh();
    expect((float) $order->discount_amount)->toBe(100.0);

    // Two more loads => 2 more stamps => a second reward, which now fits.
    $this->postJson("/api/orders/{$order->id}/loads", [
        'loads' => [['service_id' => $wash->id, 'quantity' => 2]],
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    $order->refresh();

    expect((float) $order->subtotal)->toBe(600.0);
    expect((float) $order->discount_amount)->toBe(200.0);
    expect((float) $order->total_amount)->toBe(400.0);
    expect(LoyaltyReward::where('redeemed_on_order_id', $order->id)->count())->toBe(2);
});

it('prices a free load and a fixed discount together when both rules exist', function () {
    [$branch, $order] = fixedDiscountSetup(100);

    // A second rule on the same cycle: every 2 stamps also gives a free load.
    LoyaltyRule::create([
        'branch_id'          => $branch->id,
        'every_n_stamps'     => 2,
        'reward_type'        => 'free_load',
        'reward_description' => 'Free load',
        'is_active'          => true,
    ]);

    $pricey = fixedDiscountService('Pricey wash', 300);
    $cheap  = fixedDiscountService('Cheap wash', 80);

    $this->postJson("/api/orders/{$order->id}/loads", [
        'loads' => [
            ['service_id' => $pricey->id, 'quantity' => 1],
            ['service_id' => $cheap->id,  'quantity' => 1],
        ],
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    $order->refresh();

    // 100 off, plus a free load taking the cheapest remaining unit (80).
    expect((float) $order->discount_amount)->toBe(180.0);
    expect((float) $order->total_amount)->toBe(200.0);
    expect(LoyaltyReward::where('redeemed_on_order_id', $order->id)->count())->toBe(2);
});

it('rejects a fixed discount rule with no amount', function () {
    [$branch] = fixedDiscountSetup(100);

    $this->postJson('/api/loyalty-rules', [
        'every_n_stamps'     => 5,
        'reward_type'        => 'fixed_discount',
        'reward_description' => 'Some off',
    ], ['X-Branch-Id' => $branch->id])->assertStatus(422)->assertJsonValidationErrors('reward_amount');
});

it('rejects switching an existing rule to fixed discount without an amount', function () {
    [$branch] = fixedDiscountSetup(100);

    $rule = LoyaltyRule::create([
        'branch_id'          => $branch->id,
        'every_n_stamps'     => 5,
        'reward_type'        => 'free_load',
        'reward_description' => 'Free load',
        'is_active'          => true,
    ]);

    $this->putJson("/api/loyalty-rules/{$rule->id}", [
        'reward_type' => 'fixed_discount',
    ], ['X-Branch-Id' => $branch->id])->assertStatus(422);

    expect($rule->fresh()->reward_type)->toBe('free_load');
});
