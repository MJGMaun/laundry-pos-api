<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function delLogSetup(string $role): array
{
    $branch = Branch::create(['name' => 'Main', 'is_active' => true]);
    $user   = User::factory()->create(['role' => $role]);
    $user->branches()->attach($branch->id, ['is_primary' => true]);
    Sanctum::actingAs($user);

    return [$branch, $user];
}

it('shows deleted payments in the audit log', function () {
    [$branch, $user] = delLogSetup('super_admin');

    $category = ServiceCategory::create(['name' => 'Wash Cat', 'load_rule' => 'quantity']);
    $service  = Service::create([
        'category_id' => $category->id, 'name' => 'Wash',
        'pricing_type' => 'flat_rate', 'price' => 100, 'is_active' => true,
    ]);
    $customer = Customer::create(['branch_id' => $branch->id, 'name' => 'Juan', 'phone' => '09170000000']);

    $orderId = $this->postJson('/api/orders', [
        'customer_id' => $customer->id,
        'loads'       => [['service_id' => $service->id, 'quantity' => 1]],
    ], ['X-Branch-Id' => $branch->id])->json('id');

    $this->postJson("/api/orders/{$orderId}/payments", [
        'method' => 'cash', 'amount' => 100, 'tendered' => 100,
    ], ['X-Branch-Id' => $branch->id]);

    $payment = Payment::where('order_id', $orderId)->first();
    $this->deleteJson("/api/payments/{$payment->id}", [], ['X-Branch-Id' => $branch->id])->assertOk();

    $res = $this->getJson('/api/deleted-records?type=payments', ['X-Branch-Id' => $branch->id])
        ->assertOk()
        ->json();

    expect($res['data'])->toHaveCount(1);
    expect($res['data'][0]['customer_name'])->toBe('Juan');
    expect($res['data'][0]['branch_name'])->toBe('Main');
    expect($res['data'][0]['deleted_at'])->not->toBeNull();
    expect($res['data'][0]['deleted_by_name'])->toBe($user->name);
});

it('shows deleted customers and rejects unknown types', function () {
    [$branch] = delLogSetup('super_admin');

    $customer = Customer::create(['branch_id' => $branch->id, 'name' => 'Maria', 'phone' => '09170000001']);
    $customer->delete();

    $res = $this->getJson('/api/deleted-records?type=customers', ['X-Branch-Id' => $branch->id])
        ->assertOk()->json();
    expect($res['data'])->toHaveCount(1);
    expect($res['data'][0]['name'])->toBe('Maria');

    $this->getJson('/api/deleted-records?type=nope', ['X-Branch-Id' => $branch->id])
        ->assertUnprocessable();
});

it('forbids non-super-admins', function () {
    [$branch] = delLogSetup('admin');

    $this->getJson('/api/deleted-records?type=payments', ['X-Branch-Id' => $branch->id])
        ->assertForbidden();
});
