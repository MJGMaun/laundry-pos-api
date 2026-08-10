<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * A super admin with no branch picked sees every real branch. That case has to
 * be handled explicitly — `where('branch_id', null)` compiles to
 * `branch_id = NULL`, which matches nothing, so totals came back as zero.
 */
function allBranchesSetup(): array
{
    $alpha = Branch::create(['name' => 'Alpha', 'is_active' => true]);
    $beta  = Branch::create(['name' => 'Beta', 'is_active' => true]);
    $test  = Branch::create(['name' => 'Sandbox', 'is_active' => true, 'is_test' => true]);

    $user = User::factory()->create(['role' => 'super_admin']);
    $user->branches()->attach([$alpha->id, $beta->id, $test->id]);
    Sanctum::actingAs($user);

    $category = ServiceCategory::create(['name' => 'Wash Cat', 'load_rule' => 'quantity']);
    $service  = Service::create([
        'category_id'  => $category->id,
        'name'         => 'Wash',
        'pricing_type' => 'flat_rate',
        'price'        => 100,
        'is_active'    => true,
    ]);

    foreach ([$alpha, $beta, $test] as $branch) {
        $customer = Customer::create([
            'branch_id' => $branch->id,
            'name'      => "Cust {$branch->id}",
            'phone'     => '0917000000' . $branch->id,
        ]);

        $orderId = test()->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'loads'       => [['service_id' => $service->id, 'quantity' => 1]],
        ], ['X-Branch-Id' => $branch->id])->json('id');

        test()->postJson("/api/orders/{$orderId}/payments", [
            'method' => 'cash', 'amount' => 100, 'tendered' => 100,
        ], ['X-Branch-Id' => $branch->id])->assertCreated();
    }

    return [$alpha, $beta, $test, $user];
}

it('totals cash across every real branch when none is selected', function () {
    allBranchesSetup();

    // No X-Branch-Id header = the "All branches" option.
    $res = $this->getJson('/api/cash-balance')->assertOk()->json();

    // Alpha + Beta only — the test branch is excluded, as in the reports.
    expect((float) $res['cash_in'])->toBe(200.0);
    expect($res['payments'])->toHaveCount(2);
});

it('sums the starting float of every real branch when none is selected', function () {
    [$alpha, $beta] = allBranchesSetup();
    $today = now()->toDateString();

    $this->postJson('/api/cash-balance', ['date' => $today, 'starting_balance' => 500],
        ['X-Branch-Id' => $alpha->id])->assertOk();
    $this->postJson('/api/cash-balance', ['date' => $today, 'starting_balance' => 300],
        ['X-Branch-Id' => $beta->id])->assertOk();

    $res = $this->getJson('/api/cash-balance')->assertOk()->json();

    expect((float) $res['starting_balance'])->toBe(800.0);
    // 800 float + 200 collected, nothing spent.
    expect((float) $res['total_in_drawer'])->toBe(1000.0);
});

it('refuses to set a starting float with no branch selected', function () {
    allBranchesSetup();

    $this->postJson('/api/cash-balance', [
        'date'             => now()->toDateString(),
        'starting_balance' => 500,
    ])->assertStatus(422);
});

it('keeps test-branch expenses out of the all-branches expense list', function () {
    [$alpha, , $test, $user] = allBranchesSetup();
    $category = ExpenseCategory::create(['name' => 'Supplies']);

    foreach ([[$alpha, 40], [$test, 999]] as [$branch, $amount]) {
        Expense::create([
            'branch_id'           => $branch->id,
            'expense_category_id' => $category->id,
            'user_id'             => $user->id,
            'amount'              => $amount,
            'payment_method'      => 'cash',
            'expense_date'        => now()->toDateString(),
        ]);
    }

    // Only Alpha's expense is listed; the sandbox branch's ₱999 stays out.
    $listed = $this->getJson('/api/expenses?date_from=' . now()->toDateString())
        ->assertOk()->json('expenses.data');

    expect($listed)->toHaveCount(1);
    expect((float) $listed[0]['amount'])->toBe(40.0);

    // And the cash balance agrees, so the dashboard's two figures reconcile.
    $cash = $this->getJson('/api/cash-balance')->assertOk()->json();
    expect((float) $cash['cash_expenses'])->toBe(40.0);
});
