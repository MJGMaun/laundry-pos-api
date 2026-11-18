<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function tipSetup(string $role = 'super_admin'): array
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

    return [$branch, $orderId];
}

it('records a gcash tip in the gcash account but not in sales', function () {
    [$branch, $orderId] = tipSetup();
    $h = ['X-Branch-Id' => $branch->id];

    $this->postJson("/api/orders/{$orderId}/payments", [
        'method' => 'gcash', 'amount' => 100, 'tip_amount' => 20, 'reference_number' => '1234',
    ], $h)->assertCreated()->assertJsonPath('summary.is_paid', true);

    $cash = $this->getJson('/api/cash-balance', $h)->assertOk();
    expect((float) $cash->json('gcash_in'))->toBe(100.0);
    expect((float) $cash->json('gcash_tips'))->toBe(20.0);
    expect((float) $cash->json('to_remit_gcash'))->toBe(120.0);

    $gcash = collect($this->getJson('/api/accounts', $h)->json('accounts'))->firstWhere('method', 'gcash');
    expect((float) $gcash['balance'])->toBe(120.0);
    expect((float) $gcash['tips'])->toBe(20.0);

    // Matching shows what the GCash app shows: amount + tip.
    $match = $this->getJson('/api/gcash-matching', $h)->assertOk();
    expect((float) $match->json('summary.total'))->toBe(120.0);

    // Sales (customer total spent) only counts the order amount.
    $this->getJson("/api/orders/{$orderId}/payments", $h)->assertJsonPath('summary.net_paid', 100);
});

it('rejects a tip on a balance payment or refund', function () {
    [$branch, $orderId] = tipSetup();
    $h = ['X-Branch-Id' => $branch->id];

    $this->postJson("/api/orders/{$orderId}/payments", [
        'method' => 'gcash', 'type' => 'refund', 'amount' => 10, 'tip_amount' => 5,
    ], $h)->assertStatus(422);
});

it('records an end-of-day gcash check against the expected balance', function () {
    [$branch, $orderId] = tipSetup();
    $h = ['X-Branch-Id' => $branch->id];

    $this->postJson("/api/orders/{$orderId}/payments", [
        'method' => 'gcash', 'amount' => 100, 'reference_number' => '1234',
    ], $h)->assertCreated();

    $this->getJson('/api/account-checks?method=gcash', $h)
        ->assertOk()
        ->assertJsonPath('checked_today', false);

    $res = $this->postJson('/api/account-checks', ['method' => 'gcash', 'counted_amount' => 90, 'note' => 'app shows 90'], $h)
        ->assertOk()
        ->assertJsonPath('checked_today', true);

    $check = $res->json('checks.0');
    expect((float) $check['expected_amount'])->toBe(100.0);
    expect((float) $check['difference'])->toBe(-10.0);

    $this->deleteJson("/api/account-checks/{$check['id']}", [], $h)->assertOk()->assertJsonCount(0, 'checks');
});

it('keeps account checks super-admin only and branch-scoped', function () {
    [$branch] = tipSetup('admin');
    $this->getJson('/api/account-checks', ['X-Branch-Id' => $branch->id])->assertForbidden();
});

it('lets an admin add a missed tip to an existing payment', function () {
    [$branch, $orderId] = tipSetup('admin');
    $h = ['X-Branch-Id' => $branch->id];

    $id = $this->postJson("/api/orders/{$orderId}/payments", [
        'method' => 'gcash', 'amount' => 100, 'reference_number' => '1234',
    ], $h)->assertCreated()->json('data.id');

    $this->patchJson("/api/payments/{$id}/tip", ['tip_amount' => 48], $h)
        ->assertOk()
        ->assertJsonPath('data.tip_amount', '48.00');

    $cash = $this->getJson('/api/cash-balance', $h)->assertOk();
    expect((float) $cash->json('gcash_tips'))->toBe(48.0);
    expect((float) $cash->json('gcash_in'))->toBe(100.0);

    $gcash = collect($this->getJson('/api/accounts', $h)->json('accounts'))->firstWhere('method', 'gcash');
    expect((float) $gcash['balance'])->toBe(148.0);
    expect((float) $gcash['tips_held'])->toBe(48.0);
});

it('blocks cashiers from editing tips', function () {
    [$branch, $orderId] = tipSetup('cashier');
    $h = ['X-Branch-Id' => $branch->id];

    $id = $this->postJson("/api/orders/{$orderId}/payments", [
        'method' => 'gcash', 'amount' => 100, 'reference_number' => '1234',
    ], $h)->json('data.id');

    $this->patchJson("/api/payments/{$id}/tip", ['tip_amount' => 10], $h)->assertForbidden();
});

it('takes a tips payout out of the account and out of tips held', function () {
    [$branch, $orderId] = tipSetup();
    $h = ['X-Branch-Id' => $branch->id];

    $this->postJson("/api/orders/{$orderId}/payments", [
        'method' => 'gcash', 'amount' => 100, 'tip_amount' => 50, 'reference_number' => '1234',
    ], $h)->assertCreated();

    $res = $this->postJson('/api/account-movements', [
        'type' => 'tip_payout', 'method' => 'gcash', 'amount' => 30,
        'occurred_on' => now()->toDateString(), 'recipient' => 'Ana',
    ], $h)->assertOk();

    $gcash = collect($res->json('accounts'))->firstWhere('method', 'gcash');
    expect((float) $gcash['balance'])->toBe(120.0);
    expect((float) $gcash['tip_payouts'])->toBe(30.0);
    expect((float) $gcash['tips_held'])->toBe(20.0);
});
