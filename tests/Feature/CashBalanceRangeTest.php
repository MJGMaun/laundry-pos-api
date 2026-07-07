<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function cashRangeSetup(): array
{
    $branch = Branch::create(['name' => 'Main', 'is_active' => true]);
    $user   = User::factory()->create(['role' => 'admin']);
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

function cashRangeOrderWithPayment(Branch $branch, Service $service, Customer $customer, string $date): void
{
    $orderId = test()->postJson('/api/orders', [
        'customer_id' => $customer->id,
        'loads'       => [['service_id' => $service->id, 'quantity' => 1]],
        'order_date'  => $date,
    ], ['X-Branch-Id' => $branch->id])->json('id');

    test()->postJson("/api/orders/{$orderId}/payments", [
        'method'       => 'cash',
        'amount'       => 100,
        'tendered'     => 100,
        'payment_date' => $date,
    ], ['X-Branch-Id' => $branch->id])->assertCreated();
}

it('sums cash over a date range and hides per-day drawer fields', function () {
    [$branch, $service, $customer] = cashRangeSetup();

    $dayA = now()->subDays(2)->toDateString();
    $dayB = now()->subDay()->toDateString();
    cashRangeOrderWithPayment($branch, $service, $customer, $dayA);
    cashRangeOrderWithPayment($branch, $service, $customer, $dayB);

    $res = $this->getJson("/api/cash-balance?date_from={$dayA}&date_to={$dayB}", ['X-Branch-Id' => $branch->id])
        ->assertOk()
        ->json();

    expect($res['is_range'])->toBeTrue();
    expect((float) $res['cash_in'])->toBe(200.0);
    expect($res['starting_balance'])->toBeNull();
    expect($res['total_in_drawer'])->toBeNull();
    expect((float) $res['to_remit_cash'])->toBe(200.0);
    expect($res['payments'])->toHaveCount(2);
});

it('keeps single-day behaviour when only date is given', function () {
    [$branch, $service, $customer] = cashRangeSetup();

    $dayA = now()->subDays(2)->toDateString();
    $dayB = now()->subDay()->toDateString();
    cashRangeOrderWithPayment($branch, $service, $customer, $dayA);
    cashRangeOrderWithPayment($branch, $service, $customer, $dayB);

    $res = $this->getJson("/api/cash-balance?date={$dayA}", ['X-Branch-Id' => $branch->id])
        ->assertOk()
        ->json();

    expect($res['is_range'])->toBeFalse();
    expect((float) $res['cash_in'])->toBe(100.0);
    expect($res['starting_balance'])->not->toBeNull();
    expect($res['total_in_drawer'])->not->toBeNull();
    expect($res['payments'])->toHaveCount(1);
});

it('lists unpaid orders with outstanding totals', function () {
    [$branch, $service, $customer] = cashRangeSetup();
    $today = now()->toDateString();

    // Fully paid order — must NOT appear as unpaid.
    cashRangeOrderWithPayment($branch, $service, $customer, $today);

    // Partially paid order: 100 total, 40 paid → 60 due.
    $orderId = test()->postJson('/api/orders', [
        'customer_id' => $customer->id,
        'loads'       => [['service_id' => $service->id, 'quantity' => 1]],
    ], ['X-Branch-Id' => $branch->id])->json('id');
    test()->postJson("/api/orders/{$orderId}/payments", [
        'method' => 'cash', 'amount' => 40, 'tendered' => 40,
    ], ['X-Branch-Id' => $branch->id])->assertCreated();

    // Unpaid order: nothing paid → 100 due.
    test()->postJson('/api/orders', [
        'customer_id' => $customer->id,
        'loads'       => [['service_id' => $service->id, 'quantity' => 1]],
    ], ['X-Branch-Id' => $branch->id])->assertCreated();

    $res = $this->getJson("/api/cash-balance?date={$today}", ['X-Branch-Id' => $branch->id])
        ->assertOk()
        ->json();

    expect($res['unpaid'])->toHaveCount(2);
    expect((float) $res['unpaid_total'])->toBe(160.0);
    expect((float) $res['unpaid'][0]['balance_due'])->toBe(60.0);
    expect((float) $res['unpaid'][1]['balance_due'])->toBe(100.0);
});

it('normalises a reversed range', function () {
    [$branch, $service, $customer] = cashRangeSetup();

    $dayA = now()->subDays(2)->toDateString();
    cashRangeOrderWithPayment($branch, $service, $customer, $dayA);

    $res = $this->getJson("/api/cash-balance?date_from=" . now()->toDateString() . "&date_to={$dayA}", ['X-Branch-Id' => $branch->id])
        ->assertOk()
        ->json();

    expect($res['date_from'])->toBe($dayA);
    expect((float) $res['cash_in'])->toBe(100.0);
});
