<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function accountsSetup(string $role = 'super_admin'): array
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

    return [$branch, $service, $customer, $user];
}

function accountsPaidOrder(Branch $branch, Service $service, Customer $customer, string $date, string $method = 'cash'): void
{
    $orderId = test()->postJson('/api/orders', [
        'customer_id' => $customer->id,
        'loads'       => [['service_id' => $service->id, 'quantity' => 1]],
        'order_date'  => $date,
    ], ['X-Branch-Id' => $branch->id])->json('id');

    test()->postJson("/api/orders/{$orderId}/payments", [
        'method'       => $method,
        'amount'       => 100,
        'tendered'     => 100,
        'payment_date' => $date,
    ], ['X-Branch-Id' => $branch->id])->assertCreated();
}

function accountFor(array $payload, string $method): array
{
    return collect($payload['accounts'])->firstWhere('method', $method);
}

it('carries a running balance of payments minus expenses minus withdrawals', function () {
    [$branch, $service, $customer, $user] = accountsSetup();
    $today = now()->toDateString();

    accountsPaidOrder($branch, $service, $customer, $today);          // +100 cash
    accountsPaidOrder($branch, $service, $customer, $today, 'gcash'); // +100 gcash

    $category = ExpenseCategory::create(['name' => 'Supplies']);
    Expense::create([
        'branch_id'           => $branch->id,
        'expense_category_id' => $category->id,
        'user_id'             => $user->id,
        'amount'              => 30,
        'payment_method'      => 'cash',
        'expense_date'        => $today,
    ]);

    $res = $this->postJson('/api/account-movements', [
        'type'        => 'withdrawal',
        'method'      => 'cash',
        'amount'      => 50,
        'occurred_on' => $today,
        'recipient'   => 'Partner',
    ], ['X-Branch-Id' => $branch->id])->assertOk()->json();

    $cash = accountFor($res, 'cash');
    expect((float) $cash['payments_in'])->toBe(100.0);
    expect((float) $cash['expenses'])->toBe(30.0);
    expect((float) $cash['withdrawals'])->toBe(50.0);
    expect((float) $cash['balance'])->toBe(20.0);

    expect((float) accountFor($res, 'gcash')['balance'])->toBe(100.0);
    expect((float) $res['total_balance'])->toBe(120.0);
});

it('leaves profit and margin untouched when money is withdrawn', function () {
    [$branch, $service, $customer] = accountsSetup();
    $today = now()->toDateString();

    accountsPaidOrder($branch, $service, $customer, $today);

    $before = $this->getJson("/api/reports/profit-loss?date_from={$today}&date_to={$today}", ['X-Branch-Id' => $branch->id])
        ->assertOk()->json();

    $this->postJson('/api/account-movements', [
        'type'        => 'withdrawal',
        'method'      => 'cash',
        'amount'      => 80,
        'occurred_on' => $today,
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    $after = $this->getJson("/api/reports/profit-loss?date_from={$today}&date_to={$today}", ['X-Branch-Id' => $branch->id])
        ->assertOk()->json();

    expect($after['expenses']['total'])->toBe($before['expenses']['total']);
    expect($after['net_profit'])->toBe($before['net_profit']);
    expect($after['profit_margin_pct'])->toBe($before['profit_margin_pct']);
});

it('zeroes every counter when an opening balance is set', function () {
    [$branch, $service, $customer, $user] = accountsSetup();
    $today = now()->toDateString();

    // Everything below is recorded BEFORE the opening, so it is sealed into
    // the counted figure rather than stacked on top of it.
    accountsPaidOrder($branch, $service, $customer, $today);

    $category = ExpenseCategory::create(['name' => 'Supplies']);
    Expense::create([
        'branch_id'           => $branch->id,
        'expense_category_id' => $category->id,
        'user_id'             => $user->id,
        'amount'              => 30,
        'payment_method'      => 'cash',
        'expense_date'        => $today,
    ]);

    $this->postJson('/api/account-movements', [
        'type'        => 'withdrawal',
        'method'      => 'cash',
        'amount'      => 20,
        'occurred_on' => $today,
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    Carbon::setTestNow(now()->addMinute());

    $res = $this->postJson('/api/account-movements', [
        'type'        => 'opening',
        'method'      => 'cash',
        'amount'      => 500,
    ], ['X-Branch-Id' => $branch->id])->assertOk()->json();

    $cash = accountFor($res, 'cash');
    expect($cash['has_opening'])->toBeTrue();
    expect($cash['cutover_at'])->not->toBeNull();
    expect((float) $cash['opening'])->toBe(500.0);
    expect((float) $cash['payments_in'])->toBe(0.0);
    expect((float) $cash['expenses'])->toBe(0.0);
    expect((float) $cash['withdrawals'])->toBe(0.0);
    expect((float) $cash['balance'])->toBe(500.0);
});

it('counts only what is recorded after the opening balance', function () {
    [$branch, $service, $customer] = accountsSetup();

    accountsPaidOrder($branch, $service, $customer, now()->toDateString()); // sealed

    Carbon::setTestNow(now()->addMinute());

    $this->postJson('/api/account-movements', [
        'type'   => 'opening',
        'method' => 'cash',
        'amount' => 500,
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    Carbon::setTestNow(now()->addMinute());

    accountsPaidOrder($branch, $service, $customer, now()->toDateString()); // counts

    $res = $this->getJson('/api/accounts', ['X-Branch-Id' => $branch->id])->assertOk()->json();

    $cash = accountFor($res, 'cash');
    expect((float) $cash['payments_in'])->toBe(100.0);
    expect((float) $cash['balance'])->toBe(600.0);
});

it('moves money between accounts on a transfer without changing the total', function () {
    [$branch, $service, $customer] = accountsSetup();
    $today = now()->toDateString();

    accountsPaidOrder($branch, $service, $customer, $today, 'gcash');

    $res = $this->postJson('/api/account-movements', [
        'type'        => 'transfer',
        'method'      => 'gcash',
        'to_method'   => 'cash',
        'amount'      => 60,
        'occurred_on' => $today,
    ], ['X-Branch-Id' => $branch->id])->assertOk()->json();

    expect((float) accountFor($res, 'gcash')['balance'])->toBe(40.0);
    expect((float) accountFor($res, 'cash')['balance'])->toBe(60.0);
    expect((float) $res['total_balance'])->toBe(100.0);
});

it('rejects a transfer to the same account', function () {
    [$branch] = accountsSetup();

    $this->postJson('/api/account-movements', [
        'type'        => 'transfer',
        'method'      => 'cash',
        'to_method'   => 'cash',
        'amount'      => 10,
        'occurred_on' => now()->toDateString(),
    ], ['X-Branch-Id' => $branch->id])->assertStatus(422);
});

it('restores the balance when a movement is deleted', function () {
    [$branch, $service, $customer] = accountsSetup();
    $today = now()->toDateString();

    accountsPaidOrder($branch, $service, $customer, $today);

    $res = $this->postJson('/api/account-movements', [
        'type'        => 'withdrawal',
        'method'      => 'cash',
        'amount'      => 40,
        'occurred_on' => $today,
    ], ['X-Branch-Id' => $branch->id])->assertOk()->json();

    expect((float) accountFor($res, 'cash')['balance'])->toBe(60.0);

    $id    = $res['movements'][0]['id'];
    $after = $this->deleteJson("/api/account-movements/{$id}", [], ['X-Branch-Id' => $branch->id])
        ->assertOk()->json();

    expect((float) accountFor($after, 'cash')['balance'])->toBe(100.0);
});

it('breaks money in and out down by month', function () {
    [$branch, $service, $customer, $user] = accountsSetup();

    $thisMonth = now()->startOfMonth()->addDays(2);
    $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->addDays(3);

    accountsPaidOrder($branch, $service, $customer, $thisMonth->toDateString());
    accountsPaidOrder($branch, $service, $customer, $thisMonth->toDateString(), 'gcash');
    accountsPaidOrder($branch, $service, $customer, $lastMonth->toDateString());

    $category = ExpenseCategory::create(['name' => 'Supplies']);
    Expense::create([
        'branch_id'           => $branch->id,
        'expense_category_id' => $category->id,
        'user_id'             => $user->id,
        'amount'              => 40,
        'payment_method'      => 'cash',
        'expense_date'        => $thisMonth->toDateString(),
    ]);

    $this->postJson('/api/account-movements', [
        'type'        => 'withdrawal',
        'method'      => 'cash',
        'amount'      => 25,
        'occurred_on' => $thisMonth->toDateString(),
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    $months = $this->getJson('/api/accounts', ['X-Branch-Id' => $branch->id])
        ->assertOk()->json('months');

    // Newest first.
    expect($months[0]['month'])->toBe(now()->format('Y-m'));
    expect($months[1]['month'])->toBe(now()->subMonthNoOverflow()->format('Y-m'));

    expect((float) $months[0]['cash_in'])->toBe(100.0);
    expect((float) $months[0]['gcash_in'])->toBe(100.0);
    expect((float) $months[0]['expenses'])->toBe(40.0);
    expect((float) $months[0]['withdrawals'])->toBe(25.0);
    // Withdrawals stay out of net — they are not a cost of the month.
    expect((float) $months[0]['net'])->toBe(160.0);

    expect((float) $months[1]['cash_in'])->toBe(100.0);
    expect((float) $months[1]['net'])->toBe(100.0);
});

it('keeps monthly history visible after an opening balance seals the account', function () {
    [$branch, $service, $customer] = accountsSetup();

    accountsPaidOrder($branch, $service, $customer, now()->startOfMonth()->addDay()->toDateString());

    Carbon::setTestNow(now()->addMinute());

    $res = $this->postJson('/api/account-movements', [
        'type'   => 'opening',
        'method' => 'cash',
        'amount' => 500,
    ], ['X-Branch-Id' => $branch->id])->assertOk()->json();

    // Sealed for the balance...
    expect((float) accountFor($res, 'cash')['payments_in'])->toBe(0.0);
    // ...but the month still reports what actually came in.
    expect((float) $res['months'][0]['cash_in'])->toBe(100.0);
});

it('lets admins view and record movements', function () {
    [$branch] = accountsSetup('admin');

    $this->getJson('/api/accounts', ['X-Branch-Id' => $branch->id])->assertOk();

    $res = $this->postJson('/api/account-movements', [
        'type'        => 'withdrawal',
        'method'      => 'cash',
        'amount'      => 10,
        'occurred_on' => now()->toDateString(),
    ], ['X-Branch-Id' => $branch->id])->assertOk()->json();

    expect((float) accountFor($res, 'cash')['withdrawals'])->toBe(10.0);
});

it('is closed to cashiers and staff', function () {
    foreach (['cashier', 'staff'] as $role) {
        [$branch] = accountsSetup($role);

        $this->getJson('/api/accounts', ['X-Branch-Id' => $branch->id])->assertStatus(403);
        $this->postJson('/api/account-movements', [
            'type'        => 'withdrawal',
            'method'      => 'cash',
            'amount'      => 10,
            'occurred_on' => now()->toDateString(),
        ], ['X-Branch-Id' => $branch->id])->assertStatus(403);
    }
});
