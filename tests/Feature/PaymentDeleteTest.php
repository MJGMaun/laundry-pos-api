<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function payDelSetup(string $role = 'admin'): array
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

    $orderId = test()->postJson('/api/orders', [
        'customer_id' => $customer->id,
        'loads'       => [['service_id' => $service->id, 'quantity' => 1]],
    ], ['X-Branch-Id' => $branch->id])->json('id');

    test()->postJson("/api/orders/{$orderId}/payments", [
        'method'   => 'cash',
        'amount'   => 100,
        'tendered' => 100,
    ], ['X-Branch-Id' => $branch->id])->assertCreated();

    return [$branch, $customer, $orderId, Payment::where('order_id', $orderId)->first()];
}

it('lets an admin soft-delete a payment and reverses its side effects', function () {
    [$branch, $customer, $orderId, $payment] = payDelSetup();

    // Payment recorded: spend counted, order auto-completed via full payment.
    expect((float) $customer->fresh()->total_spent)->toBe(100.0);

    $this->deleteJson("/api/payments/{$payment->id}", [], ['X-Branch-Id' => $branch->id])
        ->assertOk();

    // Soft-deleted: row kept, excluded from queries.
    expect(Payment::find($payment->id))->toBeNull();
    expect(Payment::withTrashed()->find($payment->id))->not->toBeNull();

    // total_spent reversed.
    expect((float) $customer->fresh()->total_spent)->toBe(0.0);

    // Excluded from the order's payment list and from cash balance.
    $list = $this->getJson("/api/orders/{$orderId}/payments", ['X-Branch-Id' => $branch->id])->json();
    expect($list['data'])->toHaveCount(0);

    $cash = $this->getJson('/api/cash-balance', ['X-Branch-Id' => $branch->id])->json();
    expect((float) $cash['cash_in'])->toBe(0.0);
    expect($cash['payments'])->toHaveCount(0);
});

it('reverts a completed order back to claimed when its payment is deleted', function () {
    [$branch, $customer, $orderId, $payment] = payDelSetup();

    // Move the order to claimed, then re-pay to trigger the auto-complete.
    // (payDelSetup already paid in full while pending; set status manually.)
    \App\Models\Order::where('id', $orderId)->update(['status' => 'completed']);

    $this->deleteJson("/api/payments/{$payment->id}", [], ['X-Branch-Id' => $branch->id])
        ->assertOk();

    expect(\App\Models\Order::find($orderId)->status)->toBe('claimed');
});

it('forbids cashiers from deleting payments', function () {
    [$branch, $customer, $orderId, $payment] = payDelSetup('cashier');

    $this->deleteJson("/api/payments/{$payment->id}", [], ['X-Branch-Id' => $branch->id])
        ->assertForbidden();
});

it('lists branch payments with totals and filters', function () {
    [$branch, $customer, $orderId, $payment] = payDelSetup();

    $res = $this->getJson('/api/payments?date_from=' . now()->toDateString() . '&date_to=' . now()->toDateString(), ['X-Branch-Id' => $branch->id])
        ->assertOk()
        ->json();

    expect((float) $res['summary']['total_paid'])->toBe(100.0);
    expect((float) $res['summary']['net'])->toBe(100.0);
    expect($res['data'])->toHaveCount(1);
    expect($res['data'][0]['order_number'])->not->toBeNull();

    // Method filter excludes non-matching payments.
    $gcash = $this->getJson('/api/payments?method=gcash', ['X-Branch-Id' => $branch->id])->json();
    expect($gcash['data'])->toHaveCount(0);
});
