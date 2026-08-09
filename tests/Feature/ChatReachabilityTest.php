<?php

use App\Models\Branch;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function chatBranchUser(Branch $branch, string $role, string $username): User
{
    $user = User::factory()->create(['role' => $role, 'username' => $username]);
    $user->branches()->attach($branch->id, ['is_primary' => true]);

    return $user;
}

it('hides super admins from user search but reveals them on an exact username', function () {
    $branch  = Branch::create(['name' => 'Main', 'is_active' => true]);
    $cashier = chatBranchUser($branch, 'cashier', 'juan');
    chatBranchUser($branch, 'admin', 'boss');
    $super   = User::factory()->create(['role' => 'super_admin', 'username' => 'sysadmin']);

    Sanctum::actingAs($cashier);
    $headers = ['X-Branch-Id' => $branch->id];

    // A partial match that would catch the super admin's name must not list them.
    $names = collect($this->getJson('/api/messages/users/search?q=sys', $headers)->assertOk()->json('data'))
        ->pluck('username');
    expect($names)->not->toContain('sysadmin');

    // The exact handle reveals them.
    $names = collect($this->getJson('/api/messages/users/search?q=sysadmin', $headers)->assertOk()->json('data'))
        ->pluck('username');
    expect($names)->toContain('sysadmin');

    // Branch colleagues are still searchable normally.
    $names = collect($this->getJson('/api/messages/users/search?q=bos', $headers)->assertOk()->json('data'))
        ->pluck('username');
    expect($names)->toContain('boss');

    expect($super->isSuperAdmin())->toBeTrue();
});

it('lets anyone open a direct chat with a super admin outside their branch', function () {
    $branch  = Branch::create(['name' => 'Main', 'is_active' => true]);
    $cashier = chatBranchUser($branch, 'cashier', 'juan');
    User::factory()->create(['role' => 'super_admin', 'username' => 'sysadmin']);

    Sanctum::actingAs($cashier);

    $this->postJson('/api/messages/direct', ['username' => 'sysadmin'], ['X-Branch-Id' => $branch->id])
        ->assertOk()
        ->assertJsonPath('data.other.username', 'sysadmin');
});

it('still blocks messaging a non-super-admin from another branch', function () {
    $mine    = Branch::create(['name' => 'Main', 'is_active' => true]);
    $theirs  = Branch::create(['name' => 'Annex', 'is_active' => true]);
    $cashier = chatBranchUser($mine, 'cashier', 'juan');
    chatBranchUser($theirs, 'cashier', 'pedro');

    Sanctum::actingAs($cashier);

    $this->postJson('/api/messages/direct', ['username' => 'pedro'], ['X-Branch-Id' => $mine->id])
        ->assertForbidden();
});

it('lets a super admin message anyone in any branch', function () {
    $mine   = Branch::create(['name' => 'Main', 'is_active' => true]);
    $theirs = Branch::create(['name' => 'Annex', 'is_active' => true]);
    chatBranchUser($theirs, 'staff', 'pedro');
    $super  = User::factory()->create(['role' => 'super_admin', 'username' => 'sysadmin']);

    Sanctum::actingAs($super);

    // Acting with one branch selected, but reaching a member of another.
    $this->postJson('/api/messages/direct', ['username' => 'pedro'], ['X-Branch-Id' => $mine->id])
        ->assertOk()
        ->assertJsonPath('data.other.username', 'pedro');
});

it('shows a super admin their direct chats regardless of the selected branch', function () {
    $mine    = Branch::create(['name' => 'Main', 'is_active' => true]);
    $other   = Branch::create(['name' => 'Annex', 'is_active' => true]);
    $cashier = chatBranchUser($other, 'cashier', 'juan');
    $super   = User::factory()->create(['role' => 'super_admin', 'username' => 'sysadmin']);

    // The cashier opens a DM from their own branch and writes in.
    Sanctum::actingAs($cashier);
    $convo = $this->postJson('/api/messages/direct', ['username' => 'sysadmin'], ['X-Branch-Id' => $other->id])
        ->assertOk()->json('data.id');
    $this->postJson("/api/messages/conversations/{$convo}/messages", ['body' => 'Need help'], ['X-Branch-Id' => $other->id])
        ->assertCreated();

    // The super admin has a different branch selected and must still see it.
    Sanctum::actingAs($super);
    $listed = collect($this->getJson('/api/messages/conversations', ['X-Branch-Id' => $mine->id])->assertOk()->json('data'));

    $dm = $listed->firstWhere('id', $convo);
    expect($dm)->not->toBeNull();
    expect($dm['unread_count'])->toBe(1);
    expect($dm['branch_name'])->toBe('Annex');

    expect($this->getJson('/api/messages/unread-count', ['X-Branch-Id' => $mine->id])->assertOk()->json('count'))
        ->toBe(1);
});
