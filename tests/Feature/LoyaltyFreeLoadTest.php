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

function loyaltyService(string $name, float $price, bool $eligible): Service
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

function loyaltyOrderSetup(): array
{
    $branch = Branch::create(['name' => 'Main', 'is_active' => true]);
    $user   = User::factory()->create(['role' => 'admin']);
    $user->branches()->attach($branch->id, ['is_primary' => true]);
    Sanctum::actingAs($user);

    $customer = Customer::create(['branch_id' => $branch->id, 'name' => 'Juan', 'phone' => '09170000000']);

    // A free load every 2 eligible stamps.
    LoyaltyRule::create([
        'branch_id'          => $branch->id,
        'every_n_stamps'     => 2,
        'reward_type'        => 'free_load',
        'reward_description' => 'Free wash',
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

    return [$branch, $order];
}

it('applies a free load to the cheapest eligible load across the order', function () {
    [$branch, $order] = loyaltyOrderSetup();

    $pricey  = loyaltyService('Pricey wash', 200, true);
    $cheap   = loyaltyService('Cheap wash', 50, true);
    $freebie = loyaltyService('Cheapest but not eligible', 10, false);

    // 2 eligible loads => 2 stamps => 1 free load, plus a cheaper non-eligible load.
    $this->postJson("/api/orders/{$order->id}/loads", [
        'loads' => [
            ['service_id' => $pricey->id,  'quantity' => 1],
            ['service_id' => $cheap->id,   'quantity' => 1],
            ['service_id' => $freebie->id, 'quantity' => 1],
        ],
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    $order->refresh();

    // Free load covers the cheapest ELIGIBLE load (50), never the cheaper
    // non-eligible one (10).
    expect((float) $order->discount_amount)->toBe(50.0);
    expect((float) $order->subtotal)->toBe(260.0);      // 200 + 50 + 10
    expect((float) $order->total_amount)->toBe(210.0);  // 260 - 50

    expect(LoyaltyReward::where('redeemed_on_order_id', $order->id)->count())->toBe(1);
});

it('does not redeem a reward when no eligible load can cover it', function () {
    [$branch, $order] = loyaltyOrderSetup();

    $eligible    = loyaltyService('Wash', 100, true);
    $nonEligible = loyaltyService('Fold', 40, false);

    // Earn one free load from 2 eligible stamps...
    $this->postJson("/api/orders/{$order->id}/loads", [
        'loads' => [['service_id' => $eligible->id, 'quantity' => 2]],
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    $order->refresh();
    // 2 eligible units, 1 free load => cheapest eligible unit (100) discounted.
    expect((float) $order->discount_amount)->toBe(100.0);
    expect(LoyaltyReward::whereNull('redeemed_at')->count())->toBe(0);
});
