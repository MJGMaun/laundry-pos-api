<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Load;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function activityFeedSetup(): array
{
    $branch   = Branch::create(['name' => 'Main', 'is_active' => true]);
    $cashier  = User::factory()->create(['role' => 'cashier']);
    $customer = Customer::create(['branch_id' => $branch->id, 'name' => 'Juan', 'phone' => '09170000000']);

    $category = ServiceCategory::create(['name' => 'Wash Cat', 'load_rule' => 'quantity']);
    $service  = Service::create([
        'category_id'  => $category->id,
        'name'         => 'Wash',
        'pricing_type' => 'flat_rate',
        'price'        => 100,
        'is_active'    => true,
    ]);

    $orders = [];
    foreach (['T-001', 'T-002'] as $number) {
        $order = Order::create([
            'branch_id'       => $branch->id,
            'customer_id'     => $customer->id,
            'user_id'         => $cashier->id,
            'order_number'    => $number,
            'subtotal'        => 100,
            'extra_fees'      => 0,
            'discount_amount' => 0,
            'total_amount'    => 100,
        ]);
        Load::create([
            'order_id'              => $order->id,
            'service_id'            => $service->id,
            'service_name_snapshot' => 'Wash',
            'unit_price_snapshot'   => 100,
            'quantity'              => 1,
            'line_total'            => 100,
        ]);
        $orders[] = $order;
    }

    return [$branch, $cashier, $orders];
}

it('shows recent orders with loads and cashier to a super admin, hiding deleted by default', function () {
    [, , $orders] = activityFeedSetup();

    // Soft-delete the second order (and its load, mirroring OrderController::destroy).
    $orders[1]->loads()->each(fn ($l) => $l->delete());
    $orders[1]->delete();

    Sanctum::actingAs(User::factory()->create(['role' => 'super_admin']));

    $res = $this->getJson('/api/activity/orders')->assertOk();
    expect($res->json('total'))->toBe(1);
    expect($res->json('data.0.order_number'))->toBe('T-001');
    expect($res->json('data.0.user.name'))->not->toBeNull();
    expect($res->json('data.0.loads.0.service_name_snapshot'))->toBe('Wash');

    $res = $this->getJson('/api/activity/orders?include_deleted=1')->assertOk();
    expect($res->json('total'))->toBe(2);
    // Deleted order still lists its soft-deleted loads.
    $deleted = collect($res->json('data'))->firstWhere('order_number', 'T-002');
    expect($deleted['deleted_at'])->not->toBeNull();
    expect($deleted['loads'])->toHaveCount(1);
});

it('matches orders by the service name availed when searching', function () {
    activityFeedSetup();

    Sanctum::actingAs(User::factory()->create(['role' => 'super_admin']));

    expect($this->getJson('/api/activity/orders?search=Wash')->assertOk()->json('total'))->toBe(2);
    expect($this->getJson('/api/activity/orders?search=Dry+Clean')->assertOk()->json('total'))->toBe(0);
});

it('rejects non-super-admin users', function () {
    activityFeedSetup();

    Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

    $this->getJson('/api/activity/orders')->assertForbidden();
});
