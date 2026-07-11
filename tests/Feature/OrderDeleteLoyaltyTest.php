<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyStamp;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function delLoyaltySetup(): array
{
    $branch = Branch::create(['name' => 'Main', 'is_active' => true]);
    $user   = User::factory()->create(['role' => 'admin']);
    $user->branches()->attach($branch->id, ['is_primary' => true]);
    Sanctum::actingAs($user);

    $rule = LoyaltyRule::create([
        'branch_id'          => $branch->id,
        'every_n_stamps'     => 2,
        'reward_type'        => 'free_load',
        'reward_description' => 'Free wash',
        'is_active'          => true,
    ]);

    $category = ServiceCategory::create(['name' => 'Wash Cat', 'load_rule' => 'quantity']);
    $service  = Service::create([
        'category_id'         => $category->id,
        'name'                => 'Wash',
        'pricing_type'        => 'flat_rate',
        'price'               => 100,
        'is_active'           => true,
        'is_loyalty_eligible' => true,
    ]);

    $customer = Customer::create(['branch_id' => $branch->id, 'name' => 'Juan', 'phone' => '09170000000']);

    return [$branch, $rule, $service, $customer];
}

function delLoyaltyOrder(Branch $branch, Service $service, Customer $customer, float $quantity, array $extra = []): int
{
    return test()->postJson('/api/orders', array_merge([
        'customer_id' => $customer->id,
        'loads'       => [['service_id' => $service->id, 'quantity' => $quantity]],
    ], $extra), ['X-Branch-Id' => $branch->id])->assertCreated()->json('id');
}

function stampTotal(Customer $customer): int
{
    return (int) LoyaltyStamp::where('customer_id', $customer->id)->sum('stamps_earned');
}

it('returns exactly the stamps the order earned, revoking unlocked rewards, even with many loads', function () {
    [$branch, $rule, $service, $customer] = delLoyaltySetup();

    // 5 loads => 5 stamps => crosses the 2-stamp threshold twice => 2 rewards.
    $orderId = delLoyaltyOrder($branch, $service, $customer, 5);
    expect(stampTotal($customer))->toBe(5);
    expect(LoyaltyReward::whereNull('redeemed_at')->count())->toBe(2);

    $this->deleteJson("/api/orders/{$orderId}", [], ['X-Branch-Id' => $branch->id])->assertOk();

    // All 5 stamps come back off, and both unspent rewards are revoked.
    expect(stampTotal($customer))->toBe(0);
    expect(LoyaltyReward::count())->toBe(0);
});

it('restores a reward the deleted order redeemed', function () {
    [$branch, $rule, $service, $customer] = delLoyaltySetup();

    // Pending reward from earlier (e.g. a manual stamp adjustment).
    LoyaltyReward::create([
        'customer_id' => $customer->id,
        'branch_id'   => $branch->id,
        'rule_id'     => $rule->id,
        'earned_at'   => now(),
    ]);

    // POS-style checkout that spends the reward on the order.
    $orderId = delLoyaltyOrder($branch, $service, $customer, 1, [
        'discount_amount'    => 100,
        'loyalty_free_loads' => 1,
    ]);
    expect(LoyaltyReward::whereNull('redeemed_at')->count())->toBe(0);

    $this->deleteJson("/api/orders/{$orderId}", [], ['X-Branch-Id' => $branch->id])->assertOk();

    // The reward is usable again and the order's 1 stamp is reversed.
    expect(LoyaltyReward::whereNull('redeemed_at')->count())->toBe(1);
    expect(stampTotal($customer))->toBe(0);
});

it('does not claw back a reward already spent on another order', function () {
    [$branch, $rule, $service, $customer] = delLoyaltySetup();

    // Order A earns the reward (2 stamps), left pending at checkout.
    $orderA = delLoyaltyOrder($branch, $service, $customer, 2);
    // Order B spends it.
    $orderB = delLoyaltyOrder($branch, $service, $customer, 1, [
        'discount_amount'    => 100,
        'loyalty_free_loads' => 1,
    ]);
    expect(LoyaltyReward::where('redeemed_on_order_id', $orderB)->count())->toBe(1);

    // Deleting order A reverses its stamps but the reward stays spent on B.
    $this->deleteJson("/api/orders/{$orderA}", [], ['X-Branch-Id' => $branch->id])->assertOk();

    expect(stampTotal($customer))->toBe(1); // only order B's stamp remains
    expect(LoyaltyReward::where('redeemed_on_order_id', $orderB)->whereNotNull('redeemed_at')->count())->toBe(1);
});

it('delete-and-re-ring lands in the same state (no double reward)', function () {
    [$branch, $rule, $service, $customer] = delLoyaltySetup();

    $orderId = delLoyaltyOrder($branch, $service, $customer, 2);
    expect(LoyaltyReward::count())->toBe(1);

    $this->deleteJson("/api/orders/{$orderId}", [], ['X-Branch-Id' => $branch->id])->assertOk();
    expect(LoyaltyReward::count())->toBe(0);
    expect(stampTotal($customer))->toBe(0);

    // Identical re-ring: exactly one reward again, not two.
    delLoyaltyOrder($branch, $service, $customer, 2);
    expect(stampTotal($customer))->toBe(2);
    expect(LoyaltyReward::count())->toBe(1);
});
