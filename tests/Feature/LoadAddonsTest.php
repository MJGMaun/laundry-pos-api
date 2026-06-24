<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Load;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeService(string $name, float $price, string $loadRule): Service
{
    $category = ServiceCategory::create(['name' => $name . ' Cat', 'load_rule' => $loadRule]);

    return Service::create([
        'category_id'  => $category->id,
        'name'         => $name,
        'pricing_type' => 'flat_rate',
        'price'        => $price,
        'is_active'    => true,
    ]);
}

function makeOrder(): Order
{
    $branch   = Branch::create(['name' => 'Test', 'is_active' => true]);
    $user     = User::factory()->create();
    $customer = Customer::create(['branch_id' => $branch->id, 'name' => 'Juan', 'phone' => '09170000000']);

    return Order::create([
        'branch_id'       => $branch->id,
        'customer_id'     => $customer->id,
        'user_id'         => $user->id,
        'order_number'    => 'TEST-001',
        'subtotal'        => 0,
        'extra_fees'      => 0,
        'discount_amount' => 0,
        'total_amount'    => 0,
    ]);
}

it('links an add-on load to its parent via client keys', function () {
    $wash  = makeService('Wash', 70, 'quantity');
    $fabcon = makeService('Add fabcon', 12, 'none');
    $order = makeOrder();

    Load::createWithAddons($order, [
        ['service' => $wash,   'line_total' => 70, 'quantity' => 1, 'notes' => null, '_key' => 'L1', 'parent_key' => null],
        ['service' => $fabcon, 'line_total' => 12, 'quantity' => 1, 'notes' => null, '_key' => null, 'parent_key' => 'L1'],
    ]);

    $loads = $order->loads()->get();
    expect($loads)->toHaveCount(2);

    $parent = $loads->firstWhere('service_id', $wash->id);
    $addon  = $loads->firstWhere('service_id', $fabcon->id);

    expect($parent->parent_load_id)->toBeNull();
    expect($addon->parent_load_id)->toBe($parent->id);
    expect($parent->addons()->count())->toBe(1);
});

it('links an add-on to an already-existing parent via parent_load_id', function () {
    $wash   = makeService('Wash', 70, 'quantity');
    $dry    = makeService('Add dry', 20, 'none');
    $order  = makeOrder();

    Load::createWithAddons($order, [
        ['service' => $wash, 'line_total' => 70, 'quantity' => 1, 'notes' => null, '_key' => 'L1', 'parent_key' => null],
    ]);
    $parent = $order->loads()->first();

    Load::createWithAddons($order, [
        ['service' => $dry, 'line_total' => 20, 'quantity' => 1, 'notes' => null, 'parent_load_id' => $parent->id],
    ]);

    $addon = $order->loads()->where('service_id', $dry->id)->first();
    expect($addon->parent_load_id)->toBe($parent->id);
});

it('excludes add-ons from the order load_count but keeps primary loads', function () {
    $wash   = makeService('Wash', 70, 'quantity');
    $fabcon = makeService('Add fabcon', 12, 'none');
    $order  = makeOrder();

    Load::createWithAddons($order, [
        ['service' => $wash,   'line_total' => 70, 'quantity' => 2, 'notes' => null, '_key' => 'L1', 'parent_key' => null],
        ['service' => $fabcon, 'line_total' => 12, 'quantity' => 1, 'notes' => null, '_key' => null, 'parent_key' => 'L1'],
    ]);

    // 2 wash units counted; the add-on (load_rule none) is not.
    expect($order->fresh()->load_count)->toBe(2.0);
});
