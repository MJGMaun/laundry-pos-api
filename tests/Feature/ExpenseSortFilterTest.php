<?php

use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function expSetup(): Branch
{
    $branch = Branch::create(['name' => 'Main', 'is_active' => true]);
    $user   = User::factory()->create(['role' => 'admin']);
    $user->branches()->attach($branch->id, ['is_primary' => true]);
    Sanctum::actingAs($user);

    $cat = ExpenseCategory::create(['name' => 'Supplies']);
    foreach ([[50, 'cash', '2026-11-01'], [300, 'gcash', '2026-11-03'], [120, 'cash', '2026-11-02']] as [$amt, $m, $d]) {
        Expense::create([
            'branch_id' => $branch->id, 'expense_category_id' => $cat->id, 'user_id' => $user->id,
            'amount' => $amt, 'payment_method' => $m, 'expense_date' => $d,
        ]);
    }

    return $branch;
}

it('sorts expenses by amount and date', function () {
    $h = ['X-Branch-Id' => expSetup()->id];
    $amounts = fn ($sort) => collect($this->getJson("/api/expenses?sort={$sort}", $h)->json('expenses.data'))
        ->pluck('amount')->map(fn ($a) => (float) $a)->all();

    expect($amounts('amount_desc'))->toBe([300.0, 120.0, 50.0]);
    expect($amounts('amount_asc'))->toBe([50.0, 120.0, 300.0]);
    expect($amounts('date_asc'))->toBe([50.0, 120.0, 300.0]);
    expect($amounts('date_desc'))->toBe([300.0, 120.0, 50.0]);
});

it('filters expenses by payment method, totals included', function () {
    $h = ['X-Branch-Id' => expSetup()->id];

    $res = $this->getJson('/api/expenses?payment_method=cash', $h)->assertOk();
    expect($res->json('expenses.total'))->toBe(2);
    expect((float) $res->json('monthly_totals.0.total'))->toBe(170.0);

    expect($this->getJson('/api/expenses?payment_method=gcash', $h)->json('expenses.total'))->toBe(1);
});
