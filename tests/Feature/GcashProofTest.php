<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function gcSetup(string $role = 'admin'): array
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

    return [$branch, $user, $orderId];
}

it('requires the last 4 of the reference or a receipt photo for gcash', function () {
    Storage::fake('local');
    [$branch, , $orderId] = gcSetup('cashier');
    $h = ['X-Branch-Id' => $branch->id];

    $this->postJson("/api/orders/{$orderId}/payments", ['method' => 'gcash', 'amount' => 50], $h)
        ->assertStatus(422);
    $this->postJson("/api/orders/{$orderId}/payments", ['method' => 'gcash', 'amount' => 50, 'reference_number' => '12'], $h)
        ->assertStatus(422);

    $this->postJson("/api/orders/{$orderId}/payments", ['method' => 'gcash', 'amount' => 25, 'reference_number' => '4821'], $h)
        ->assertCreated();

    $res = $this->post("/api/orders/{$orderId}/payments", [
        'method'  => 'gcash',
        'amount'  => 25,
        'receipt' => UploadedFile::fake()->image('receipt.jpg'),
    ], $h + ['Accept' => 'application/json'])->assertCreated();

    $payment = Payment::find($res->json('data.id'));
    expect($payment->receipt_path)->not->toBeNull();
    Storage::disk('local')->assertExists($payment->receipt_path);
    expect($res->json('data.has_receipt'))->toBeTrue();
    expect($res->json('data'))->not->toHaveKey('receipt_path');

    $this->get("/api/payments/{$payment->id}/receipt", $h)->assertOk();
});

it('lets an admin switch a mistaken gcash payment to cash, moving the totals', function () {
    [$branch, , $orderId] = gcSetup();
    $h = ['X-Branch-Id' => $branch->id];

    $id = $this->postJson("/api/orders/{$orderId}/payments", ['method' => 'gcash', 'amount' => 100, 'reference_number' => '9999'], $h)
        ->assertCreated()->json('data.id');

    $this->patchJson("/api/payments/{$id}/method", ['method' => 'cash'], $h)
        ->assertOk()
        ->assertJsonPath('data.method', 'cash')
        ->assertJsonPath('data.method_changed_from', 'gcash');

    $cash = $this->getJson('/api/cash-balance', $h)->assertOk();
    expect((float) $cash->json('cash_in'))->toBe(100.0);
    expect((float) $cash->json('gcash_in'))->toBe(0.0);

    // Back to gcash needs a reference again.
    $this->patchJson("/api/payments/{$id}/method", ['method' => 'gcash'], $h)->assertStatus(422);
    $this->patchJson("/api/payments/{$id}/method", ['method' => 'gcash', 'reference_number' => '9999'], $h)
        ->assertOk()
        ->assertJsonPath('data.method_changed_from', 'gcash');
});

it('blocks cashiers from changing a payment method', function () {
    [$branch, , $orderId] = gcSetup('cashier');
    $h = ['X-Branch-Id' => $branch->id];

    $id = $this->postJson("/api/orders/{$orderId}/payments", ['method' => 'gcash', 'amount' => 100, 'reference_number' => '9999'], $h)
        ->json('data.id');

    $this->patchJson("/api/payments/{$id}/method", ['method' => 'cash'], $h)->assertForbidden();
});

it('lets a super admin tick gcash payments off and totals the gap', function () {
    [$branch, , $orderId] = gcSetup('super_admin');
    $h = ['X-Branch-Id' => $branch->id];

    $a = $this->postJson("/api/orders/{$orderId}/payments", ['method' => 'gcash', 'amount' => 60, 'reference_number' => '1111'], $h)->json('data.id');
    $this->postJson("/api/orders/{$orderId}/payments", ['method' => 'gcash', 'amount' => 40, 'reference_number' => '2222'], $h);

    $this->patchJson("/api/payments/{$a}/matched", ['matched' => true], $h)->assertOk();

    $res = $this->getJson('/api/gcash-matching', $h)->assertOk();
    expect((float) $res->json('summary.total'))->toBe(100.0);
    expect((float) $res->json('summary.matched'))->toBe(60.0);
    expect((float) $res->json('summary.unmatched'))->toBe(40.0);
    expect($res->json('payments'))->toHaveCount(2);

    $unmatched = $this->getJson('/api/gcash-matching?status=unmatched', $h)->json('payments');
    expect($unmatched)->toHaveCount(1);
});

it('hides gcash matching from admins', function () {
    [$branch] = gcSetup('admin');
    $this->getJson('/api/gcash-matching', ['X-Branch-Id' => $branch->id])->assertForbidden();
});
