<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function backdateSetup(string $role): array
{
    $branch = Branch::create(['name' => 'Main', 'is_active' => true]);
    $user   = User::factory()->create(['role' => $role]);
    $user->branches()->attach($branch->id, ['is_primary' => true]);
    Sanctum::actingAs($user);

    $category = ServiceCategory::create(['name' => 'Wash Cat', 'load_rule' => 'quantity']);
    $service  = Service::create([
        'category_id'  => $category->id,
        'name'         => 'Wash',
        'pricing_type' => 'flat_rate',
        'price'        => 100,
        'is_active'    => true,
    ]);
    $customer = Customer::create(['branch_id' => $branch->id, 'name' => 'Juan', 'phone' => '09170000000']);

    return [$branch, $service, $customer];
}

it('lets an admin backdate an order via order_date', function () {
    [$branch, $service, $customer] = backdateSetup('admin');
    $date = now()->subDays(3)->toDateString();

    $res = $this->postJson('/api/orders', [
        'customer_id' => $customer->id,
        'loads'       => [['service_id' => $service->id, 'quantity' => 1]],
        'order_date'  => $date,
    ], ['X-Branch-Id' => $branch->id])->assertCreated();

    $order = Order::find($res->json('id'));
    expect($order->created_at->toDateString())->toBe($date);
});

it('ignores order_date for cashiers', function () {
    [$branch, $service, $customer] = backdateSetup('cashier');

    $res = $this->postJson('/api/orders', [
        'customer_id' => $customer->id,
        'loads'       => [['service_id' => $service->id, 'quantity' => 1]],
        'order_date'  => now()->subDays(3)->toDateString(),
    ], ['X-Branch-Id' => $branch->id])->assertCreated();

    $order = Order::find($res->json('id'));
    expect($order->created_at->toDateString())->toBe(now()->toDateString());
});

it('rejects a future order_date', function () {
    [$branch, $service, $customer] = backdateSetup('admin');

    $this->postJson('/api/orders', [
        'customer_id' => $customer->id,
        'loads'       => [['service_id' => $service->id, 'quantity' => 1]],
        'order_date'  => now()->addDay()->toDateString(),
    ], ['X-Branch-Id' => $branch->id])->assertUnprocessable();
});

it('lets an admin backdate a payment via payment_date', function () {
    [$branch, $service, $customer] = backdateSetup('admin');
    $date = now()->subDays(2)->toDateString();

    $orderId = $this->postJson('/api/orders', [
        'customer_id' => $customer->id,
        'loads'       => [['service_id' => $service->id, 'quantity' => 1]],
        'order_date'  => $date,
    ], ['X-Branch-Id' => $branch->id])->json('id');

    $payId = $this->postJson("/api/orders/{$orderId}/payments", [
        'method'       => 'cash',
        'amount'       => 100,
        'tendered'     => 100,
        'payment_date' => $date,
    ], ['X-Branch-Id' => $branch->id])->assertCreated()->json('id') ?? null;

    $payment = \App\Models\Payment::where('order_id', $orderId)->first();
    expect($payment->created_at->toDateString())->toBe($date);
});
