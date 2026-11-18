<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function balSetup(string $role = 'admin'): array
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

    return [$branch, $user, $customer, $service];
}

function balOrder($branch, $customer, $service): int
{
    return test()->postJson('/api/orders', [
        'customer_id' => $customer->id,
        'loads'       => [['service_id' => $service->id, 'quantity' => 1]],
    ], ['X-Branch-Id' => $branch->id])->json('id');
}

it('lets an admin add a deposit that counts in the drawer but not as a payment', function () {
    [$branch, , $customer] = balSetup();
    $h = ['X-Branch-Id' => $branch->id];

    $this->postJson("/api/customers/{$customer->id}/balance/deposits", ['amount' => 500, 'method' => 'cash'], $h)
        ->assertOk()
        ->assertJsonPath('balance', 500);

    expect((float) $customer->fresh()->balance)->toBe(500.0);

    $cash = $this->getJson('/api/cash-balance', $h)->assertOk();
    expect((float) $cash->json('cash_deposits'))->toBe(500.0);
    expect((float) $cash->json('cash_in'))->toBe(0.0);
    expect((float) $cash->json('total_in_drawer'))->toBe(500.0);

    $accounts = $this->getJson('/api/accounts', $h)->assertOk();
    expect((float) collect($accounts->json('accounts'))->firstWhere('method', 'cash')['balance'])->toBe(500.0);
});

it('blocks cashiers from adding deposits or paying with balance', function () {
    [$branch, , $customer, $service] = balSetup('cashier');
    $h = ['X-Branch-Id' => $branch->id];

    $this->postJson("/api/customers/{$customer->id}/balance/deposits", ['amount' => 500, 'method' => 'cash'], $h)
        ->assertForbidden();

    $customer->update(['balance' => 500]);
    $orderId = balOrder($branch, $customer, $service);
    $this->postJson("/api/orders/{$orderId}/payments", ['method' => 'balance', 'amount' => 100], $h)
        ->assertForbidden();
});

it('pays an order from balance without adding to the drawer, and deleting restores it', function () {
    [$branch, , $customer, $service] = balSetup();
    $h = ['X-Branch-Id' => $branch->id];

    $this->postJson("/api/customers/{$customer->id}/balance/deposits", ['amount' => 500, 'method' => 'cash'], $h)->assertOk();
    $orderId = balOrder($branch, $customer, $service);

    $this->postJson("/api/orders/{$orderId}/payments", ['method' => 'balance', 'amount' => 100], $h)
        ->assertCreated()
        ->assertJsonPath('summary.is_paid', true);

    expect((float) $customer->fresh()->balance)->toBe(400.0);
    expect((float) $customer->fresh()->total_spent)->toBe(100.0);

    // Drawer still just the 500 deposit — the balance payment is not new money.
    $cash = $this->getJson('/api/cash-balance', $h)->assertOk();
    expect((float) $cash->json('total_in_drawer'))->toBe(500.0);
    expect((float) $cash->json('paid_from_balance'))->toBe(100.0);

    $payment = Payment::where('order_id', $orderId)->first();
    $this->deleteJson("/api/payments/{$payment->id}", [], $h)->assertOk();
    expect((float) $customer->fresh()->balance)->toBe(500.0);
});

it('rejects paying more than the balance or more than is owed', function () {
    [$branch, , $customer, $service] = balSetup();
    $h = ['X-Branch-Id' => $branch->id];

    $this->postJson("/api/customers/{$customer->id}/balance/deposits", ['amount' => 50, 'method' => 'gcash'], $h)->assertOk();
    $orderId = balOrder($branch, $customer, $service);

    $this->postJson("/api/orders/{$orderId}/payments", ['method' => 'balance', 'amount' => 80], $h)
        ->assertStatus(422);

    $customer->update(['balance' => 1000]);
    $this->postJson("/api/orders/{$orderId}/payments", ['method' => 'balance', 'amount' => 150], $h)
        ->assertStatus(422);

    expect((float) $customer->fresh()->balance)->toBe(1000.0);
});

it('restores balance when an order paid from balance is deleted', function () {
    [$branch, , $customer, $service] = balSetup();
    $h = ['X-Branch-Id' => $branch->id];

    $this->postJson("/api/customers/{$customer->id}/balance/deposits", ['amount' => 300, 'method' => 'cash'], $h)->assertOk();
    $orderId = balOrder($branch, $customer, $service);
    $this->postJson("/api/orders/{$orderId}/payments", ['method' => 'balance', 'amount' => 100], $h)->assertCreated();

    $this->deleteJson("/api/orders/{$orderId}", [], $h)->assertOk();
    expect((float) $customer->fresh()->balance)->toBe(300.0);
});

it('voids an unused deposit and refuses once it has been spent', function () {
    [$branch, , $customer, $service] = balSetup();
    $h = ['X-Branch-Id' => $branch->id];

    $res = $this->postJson("/api/customers/{$customer->id}/balance/deposits", ['amount' => 200, 'method' => 'cash'], $h)->assertOk();
    $depositId = $res->json('transactions.0.id');

    $this->deleteJson("/api/customers/{$customer->id}/balance/transactions/{$depositId}", [], $h)
        ->assertOk()
        ->assertJsonPath('balance', 0);

    $cash = $this->getJson('/api/cash-balance', $h)->assertOk();
    expect((float) $cash->json('cash_deposits'))->toBe(0.0);

    // A second deposit, partly spent, can no longer be voided.
    $res = $this->postJson("/api/customers/{$customer->id}/balance/deposits", ['amount' => 200, 'method' => 'cash'], $h)->assertOk();
    $depositId = $res->json('transactions.0.id');
    $orderId = balOrder($branch, $customer, $service);
    $this->postJson("/api/orders/{$orderId}/payments", ['method' => 'balance', 'amount' => 100], $h)->assertCreated();

    $this->deleteJson("/api/customers/{$customer->id}/balance/transactions/{$depositId}", [], $h)
        ->assertStatus(422);
    expect((float) $customer->fresh()->balance)->toBe(100.0);
});
