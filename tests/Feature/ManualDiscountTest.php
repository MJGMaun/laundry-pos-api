<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function manualDiscSetup(): array
{
    $branch = Branch::create(['name' => 'Main', 'is_active' => true]);
    $user   = User::factory()->create(['role' => 'admin']);
    $user->branches()->attach($branch->id, ['is_primary' => true]);
    Sanctum::actingAs($user);

    LoyaltyRule::create([
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

    $order = Order::create([
        'branch_id'       => $branch->id,
        'customer_id'     => $customer->id,
        'user_id'         => $user->id,
        'order_number'    => 'MD-001',
        'subtotal'        => 100,
        'extra_fees'      => 0,
        'discount_amount' => 0,
        'total_amount'    => 100,
    ]);

    return [$branch, $service, $order];
}

it('lets an admin set an additional discount and recalculates the total', function () {
    [$branch, $service, $order] = manualDiscSetup();

    $updated = $this->putJson("/api/orders/{$order->id}", [
        'manual_discount_amount' => 30,
    ], ['X-Branch-Id' => $branch->id])->assertOk()->json();

    expect((float) $updated['manual_discount_amount'])->toBe(30.0);
    expect((float) $updated['total_amount'])->toBe(70.0); // 100 - 30
});

it('keeps the additional discount when the loyalty recompute runs on add loads', function () {
    [$branch, $service, $order] = manualDiscSetup();

    $this->putJson("/api/orders/{$order->id}", [
        'manual_discount_amount' => 30,
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    // Adding 2 eligible loads earns a free-load reward that is redeemed on
    // this order — the recompute must not wipe the additional discount.
    $this->postJson("/api/orders/{$order->id}/loads", [
        'loads' => [['service_id' => $service->id, 'quantity' => 2]],
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    $order->refresh();
    expect((float) $order->discount_amount)->toBe(100.0);        // loyalty free load
    expect((float) $order->manual_discount_amount)->toBe(30.0);  // survives
    expect((float) $order->total_amount)->toBe(170.0);           // 300 - 100 - 30
});

it('rejects an additional discount from a cashier', function () {
    [$branch, $service, $order] = manualDiscSetup();

    $cashier = User::factory()->create(['role' => 'cashier']);
    $cashier->branches()->attach($branch->id, ['is_primary' => true]);
    Sanctum::actingAs($cashier);

    $this->putJson("/api/orders/{$order->id}", [
        'manual_discount_amount' => 30,
    ], ['X-Branch-Id' => $branch->id])->assertForbidden();
});
