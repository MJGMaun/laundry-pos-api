<?php

use App\Models\Branch;
use App\Models\Setting;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function daySummaryStaffBranch(): Branch
{
    return Branch::create(['name' => 'Main', 'is_active' => true]);
}

it('lets a super admin opt a branch staff into day summary', function () {
    $branch = daySummaryStaffBranch();
    Sanctum::actingAs(User::factory()->create(['role' => 'super_admin']));

    $this->putJson('/api/settings/day_summary_staff_enabled', ['value' => 'true'], [
        'X-Branch-Id' => $branch->id,
    ])->assertOk();

    expect(Setting::where('key', 'day_summary_staff_enabled')->where('branch_id', $branch->id)->value('value'))
        ->toBe('true');

    // The branch list surfaces the flag so the toggle renders in the right state.
    $flags = collect($this->getJson('/api/branches')->assertOk()->json())->firstWhere('id', $branch->id);
    expect($flags['day_summary_staff_enabled'])->toBeTrue();
});

it('defaults staff access to off so day summary stays admin-only', function () {
    $branch = daySummaryStaffBranch();
    Sanctum::actingAs(User::factory()->create(['role' => 'super_admin']));

    $flags = collect($this->getJson('/api/branches')->assertOk()->json())->firstWhere('id', $branch->id);

    expect($flags['day_summary_staff_enabled'])->toBeFalse();
    expect($flags['day_summary_enabled'])->toBeTrue();
});

it('blocks a regular admin from changing staff access', function () {
    $branch = daySummaryStaffBranch();
    $admin  = User::factory()->create(['role' => 'admin']);
    $admin->branches()->attach($branch->id, ['is_primary' => true]);
    Sanctum::actingAs($admin);

    $this->putJson('/api/settings/day_summary_staff_enabled', ['value' => 'true'], [
        'X-Branch-Id' => $branch->id,
    ])->assertForbidden();

    expect(Setting::where('key', 'day_summary_staff_enabled')->exists())->toBeFalse();
});
