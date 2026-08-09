<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function reportsScopeSetup(): array
{
    $alpha = Branch::create(['name' => 'Alpha', 'is_active' => true]);
    $beta  = Branch::create(['name' => 'Beta', 'is_active' => true]);

    $user = User::factory()->create(['role' => 'super_admin']);
    $user->branches()->attach([$alpha->id, $beta->id]);
    Sanctum::actingAs($user);

    $category = ServiceCategory::create(['name' => 'Wash Cat', 'load_rule' => 'quantity']);

    $wash = Service::create([
        'category_id'  => $category->id,
        'name'         => 'Wash',
        'pricing_type' => 'flat_rate',
        'price'        => 100,
        'is_active'    => true,
    ]);
    $dry = Service::create([
        'category_id'  => $category->id,
        'name'         => 'Dry',
        'pricing_type' => 'flat_rate',
        'price'        => 200,
        'is_active'    => true,
    ]);

    $alphaCustomer = Customer::create(['branch_id' => $alpha->id, 'name' => 'Ana', 'phone' => '09170000001']);
    $betaCustomer  = Customer::create(['branch_id' => $beta->id, 'name' => 'Ben', 'phone' => '09170000002']);

    // Alpha sells Wash only; Beta sells Dry only.
    test()->postJson('/api/orders', [
        'customer_id' => $alphaCustomer->id,
        'loads'       => [['service_id' => $wash->id, 'quantity' => 1]],
    ], ['X-Branch-Id' => $alpha->id])->assertCreated();

    test()->postJson('/api/orders', [
        'customer_id' => $betaCustomer->id,
        'loads'       => [['service_id' => $dry->id, 'quantity' => 1]],
    ], ['X-Branch-Id' => $beta->id])->assertCreated();

    return [$alpha, $beta];
}

it('scopes the service performance report to the requested branch', function () {
    [$alpha, $beta] = reportsScopeSetup();

    $alphaRows = $this->getJson('/api/reports/services', ['X-Branch-Id' => $alpha->id])
        ->assertOk()->json('data');

    expect(collect($alphaRows)->pluck('service_name')->all())->toBe(['Wash']);

    $betaRows = $this->getJson('/api/reports/services', ['X-Branch-Id' => $beta->id])
        ->assertOk()->json('data');

    expect(collect($betaRows)->pluck('service_name')->all())->toBe(['Dry']);
});

it('returns every branch on the service report when none is selected', function () {
    reportsScopeSetup();

    $rows = $this->getJson('/api/reports/services')->assertOk()->json('data');

    expect(collect($rows)->pluck('service_name')->sort()->values()->all())->toBe(['Dry', 'Wash']);
});
