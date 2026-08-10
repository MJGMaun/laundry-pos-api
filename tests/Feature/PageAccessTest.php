<?php

use App\Models\Branch;
use App\Models\BranchPageAccess;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function pageAccessSetup(string $role = 'cashier'): array
{
    $branch = Branch::create(['name' => 'Main', 'is_active' => true]);
    $other  = Branch::create(['name' => 'Second', 'is_active' => true]);

    $user = User::factory()->create(['role' => $role]);
    $user->branches()->attach([$branch->id, $other->id]);
    Sanctum::actingAs($user);

    return [$branch, $other, $user];
}

function grant(Branch $branch, string $page, string $role, bool $view, bool $edit): void
{
    BranchPageAccess::updateOrCreate(
        ['branch_id' => $branch->id, 'page' => $page, 'role' => $role],
        ['can_view' => $view, 'can_edit' => $edit]
    );
}

it('keeps the shipped rules when a branch has no overrides', function () {
    [$branch] = pageAccessSetup('cashier');

    // Expenses has always been admin-only.
    $this->getJson('/api/expenses', ['X-Branch-Id' => $branch->id])->assertStatus(403);
});

it('lets a branch open expenses to its cashiers', function () {
    [$branch] = pageAccessSetup('cashier');
    grant($branch, 'expenses', 'cashier', view: true, edit: false);

    $this->getJson('/api/expenses', ['X-Branch-Id' => $branch->id])->assertOk();
});

it('separates viewing from editing', function () {
    [$branch] = pageAccessSetup('cashier');
    grant($branch, 'expenses', 'cashier', view: true, edit: false);

    $this->postJson('/api/expenses', [
        'expense_category_id' => 1,
        'amount'              => 50,
        'expense_date'        => now()->toDateString(),
    ], ['X-Branch-Id' => $branch->id])->assertStatus(403);

    grant($branch, 'expenses', 'cashier', view: true, edit: true);

    // Now past the guard — it fails validation on the category instead of 403.
    $this->postJson('/api/expenses', [
        'expense_category_id' => 1,
        'amount'              => 50,
        'expense_date'        => now()->toDateString(),
    ], ['X-Branch-Id' => $branch->id])->assertStatus(422);
});

it('confines a grant to the branch that made it', function () {
    [$branch, $other] = pageAccessSetup('cashier');
    grant($branch, 'expenses', 'cashier', view: true, edit: false);

    $this->getJson('/api/expenses', ['X-Branch-Id' => $branch->id])->assertOk();
    $this->getJson('/api/expenses', ['X-Branch-Id' => $other->id])->assertStatus(403);
});

it('confines a grant to the role that received it', function () {
    [$branch] = pageAccessSetup('staff');
    grant($branch, 'expenses', 'cashier', view: true, edit: true);

    $this->getJson('/api/expenses', ['X-Branch-Id' => $branch->id])->assertStatus(403);
});

it('refuses to grant a super-admin-only page', function () {
    $branch = Branch::create(['name' => 'Main', 'is_active' => true]);
    $admin  = User::factory()->create(['role' => 'super_admin']);
    Sanctum::actingAs($admin);

    $this->putJson("/api/branches/{$branch->id}/page-access", [
        'page' => 'data-management', 'role' => 'cashier', 'can_view' => true, 'can_edit' => true,
    ])->assertStatus(422);
});

it('ignores a stored override on a locked page', function () {
    [$branch] = pageAccessSetup('cashier');
    // Written directly, bypassing the endpoint's guard.
    grant($branch, 'deleted-records', 'cashier', view: true, edit: true);

    $pages = $this->getJson('/api/my-page-access', ['X-Branch-Id' => $branch->id])
        ->assertOk()->json('pages');

    expect($pages['deleted-records']['view'])->toBeFalse();
});

it('never locks a super admin out', function () {
    $branch = Branch::create(['name' => 'Main', 'is_active' => true]);
    $user   = User::factory()->create(['role' => 'super_admin']);
    Sanctum::actingAs($user);

    // Even with everything revoked for every role.
    foreach (['admin', 'cashier', 'staff'] as $role) {
        grant($branch, 'settings', $role, view: false, edit: false);
    }

    $this->getJson('/api/expenses', ['X-Branch-Id' => $branch->id])->assertOk();
    $this->getJson('/api/my-page-access', ['X-Branch-Id' => $branch->id])
        ->assertOk()
        ->assertJsonPath('pages.settings.edit', true);
});

it('treats edit as implying view', function () {
    [$branch] = pageAccessSetup('cashier');
    // Edit without view is incoherent, so it is stored/resolved as neither.
    grant($branch, 'expenses', 'cashier', view: false, edit: true);

    $this->getJson('/api/expenses', ['X-Branch-Id' => $branch->id])->assertStatus(403);
});

it('drops the override when a cell is set back to its default', function () {
    $branch = Branch::create(['name' => 'Main', 'is_active' => true]);
    Sanctum::actingAs(User::factory()->create(['role' => 'super_admin']));

    $this->putJson("/api/branches/{$branch->id}/page-access", [
        'page' => 'expenses', 'role' => 'cashier', 'can_view' => true, 'can_edit' => false,
    ])->assertOk();
    expect(BranchPageAccess::count())->toBe(1);

    // Back to the shipped default — the row is noise, so it goes.
    $this->putJson("/api/branches/{$branch->id}/page-access", [
        'page' => 'expenses', 'role' => 'cashier', 'can_view' => false, 'can_edit' => false,
    ])->assertOk();
    expect(BranchPageAccess::count())->toBe(0);
});

it('keeps day summary working for cashiers without granting cash balance', function () {
    [$branch] = pageAccessSetup('cashier');
    grant($branch, 'day-summary', 'cashier', view: true, edit: false);

    // Day Summary is built on the cash-balance endpoint.
    $this->getJson('/api/cash-balance', ['X-Branch-Id' => $branch->id])->assertOk();

    // But setting the float still belongs to Cash Balance.
    $this->postJson('/api/cash-balance', [
        'date' => now()->toDateString(), 'starting_balance' => 500,
    ], ['X-Branch-Id' => $branch->id])->assertStatus(403);
});

it('only lets super admins read or edit a branch matrix', function () {
    [$branch] = pageAccessSetup('admin');

    $this->getJson("/api/branches/{$branch->id}/page-access")->assertStatus(403);
    $this->putJson("/api/branches/{$branch->id}/page-access", [
        'page' => 'expenses', 'role' => 'cashier', 'can_view' => true, 'can_edit' => true,
    ])->assertStatus(403);

    // But anyone may read their own.
    $this->getJson('/api/my-page-access', ['X-Branch-Id' => $branch->id])->assertOk();
});
