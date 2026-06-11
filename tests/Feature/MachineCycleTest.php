<?php

use App\Models\Branch;
use App\Models\Machine;
use App\Models\MachineCycleCount;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function makeBranch(string $name = 'Main Branch'): Branch
{
    return Branch::create(['name' => $name, 'is_active' => true]);
}

function actingAsRole(string $role, Branch $branch): User
{
    $user = User::factory()->create(['role' => $role]);
    $user->branches()->attach($branch->id, ['is_primary' => true]);
    Sanctum::actingAs($user);

    return $user;
}

it('lets an admin create a machine', function () {
    $branch = makeBranch();
    actingAsRole('admin', $branch);

    $response = $this->postJson('/api/machines', [
        'name' => 'Washer 1',
        'type' => 'washer',
    ], ['X-Branch-Id' => $branch->id]);

    $response->assertCreated()
        ->assertJsonFragment(['name' => 'Washer 1', 'type' => 'washer']);

    expect(Machine::where('branch_id', $branch->id)->count())->toBe(1);
});

it('forbids a cashier from managing machines or cycle counts', function () {
    $branch = makeBranch();
    actingAsRole('cashier', $branch);

    $this->postJson('/api/machines', ['name' => 'Washer 1', 'type' => 'washer'], ['X-Branch-Id' => $branch->id])
        ->assertForbidden();

    $this->getJson('/api/machine-cycles', ['X-Branch-Id' => $branch->id])
        ->assertForbidden();
});

it('saves daily cycle counts and updates on re-save', function () {
    $branch = makeBranch();
    actingAsRole('admin', $branch);

    $washer = Machine::create(['branch_id' => $branch->id, 'name' => 'Washer 1', 'type' => 'washer']);
    $dryer = Machine::create(['branch_id' => $branch->id, 'name' => 'Dryer 1', 'type' => 'dryer']);

    $this->postJson('/api/machine-cycles', [
        'date' => '2026-06-11',
        'counts' => [
            ['machine_id' => $washer->id, 'cycle_count' => 12],
            ['machine_id' => $dryer->id, 'cycle_count' => 9],
        ],
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    // Re-save the same day — must update, not duplicate
    $this->postJson('/api/machine-cycles', [
        'date' => '2026-06-11',
        'counts' => [
            ['machine_id' => $washer->id, 'cycle_count' => 14],
        ],
    ], ['X-Branch-Id' => $branch->id])->assertOk();

    expect(MachineCycleCount::count())->toBe(2)
        ->and(MachineCycleCount::where('machine_id', $washer->id)->first()->cycle_count)->toBe(14);
});

it('returns machines with counts and the daily total', function () {
    $branch = makeBranch();
    $admin = actingAsRole('admin', $branch);

    $washer = Machine::create(['branch_id' => $branch->id, 'name' => 'Washer 1', 'type' => 'washer']);
    Machine::create(['branch_id' => $branch->id, 'name' => 'Dryer 1', 'type' => 'dryer']);

    MachineCycleCount::create([
        'machine_id' => $washer->id,
        'date' => '2026-06-11',
        'cycle_count' => 7,
        'recorded_by' => $admin->id,
    ]);

    $response = $this->getJson('/api/machine-cycles?date=2026-06-11', ['X-Branch-Id' => $branch->id]);

    $response->assertOk()
        ->assertJsonPath('total_cycles', 7)
        ->assertJsonCount(2, 'machines');

    $machines = collect($response->json('machines'));
    expect($machines->firstWhere('id', $washer->id)['cycle_count'])->toBe(7)
        ->and($machines->firstWhere('id', $washer->id)['recorded_by'])->toBe($admin->name);
});

it('rejects cycle counts for machines of another branch', function () {
    $branch = makeBranch();
    $other = makeBranch('Other Branch');
    actingAsRole('admin', $branch);

    $foreignMachine = Machine::create(['branch_id' => $other->id, 'name' => 'Washer X', 'type' => 'washer']);

    $this->postJson('/api/machine-cycles', [
        'date' => '2026-06-11',
        'counts' => [
            ['machine_id' => $foreignMachine->id, 'cycle_count' => 5],
        ],
    ], ['X-Branch-Id' => $branch->id])->assertForbidden();

    expect(MachineCycleCount::count())->toBe(0);
});

it('hides machines of other branches from the list', function () {
    $branch = makeBranch();
    $other = makeBranch('Other Branch');
    actingAsRole('admin', $branch);

    Machine::create(['branch_id' => $branch->id, 'name' => 'Washer 1', 'type' => 'washer']);
    Machine::create(['branch_id' => $other->id, 'name' => 'Washer X', 'type' => 'washer']);

    $this->getJson('/api/machines', ['X-Branch-Id' => $branch->id])
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['name' => 'Washer 1']);
});

it('returns month and all-time running totals', function () {
    $branch = makeBranch();
    actingAsRole('admin', $branch);

    $washer = Machine::create(['branch_id' => $branch->id, 'name' => 'Washer 1', 'type' => 'washer', 'initial_cycle_count' => 100]);
    $inactive = Machine::create(['branch_id' => $branch->id, 'name' => 'Old Dryer', 'type' => 'dryer', 'is_active' => false]);

    MachineCycleCount::create(['machine_id' => $washer->id, 'date' => '2026-06-01', 'cycle_count' => 10]);
    MachineCycleCount::create(['machine_id' => $washer->id, 'date' => '2026-06-11', 'cycle_count' => 5]);
    // Inactive machines still count toward history
    MachineCycleCount::create(['machine_id' => $inactive->id, 'date' => '2026-06-02', 'cycle_count' => 3]);
    // Previous month counts toward all-time only
    MachineCycleCount::create(['machine_id' => $washer->id, 'date' => '2026-05-31', 'cycle_count' => 7]);

    $response = $this->getJson('/api/machine-cycles?date=2026-06-11', ['X-Branch-Id' => $branch->id]);

    $response->assertOk()
        ->assertJsonPath('month_total', 18)
        // 25 recorded + 100 starting meter reading
        ->assertJsonPath('all_time_total', 125);

    // Per-machine totals on the daily sheet: month is recorded-only, lifetime includes starting count
    $machines = collect($response->json('machines'));
    expect($machines->firstWhere('id', $washer->id)['month_cycles'])->toBe(15)
        ->and($machines->firstWhere('id', $washer->id)['total_cycles'])->toBe(122);
});

it('includes per-machine all-time totals in the machines list', function () {
    $branch = makeBranch();
    actingAsRole('admin', $branch);

    $washer = Machine::create(['branch_id' => $branch->id, 'name' => 'Washer 1', 'type' => 'washer', 'initial_cycle_count' => 50]);
    MachineCycleCount::create(['machine_id' => $washer->id, 'date' => '2026-06-10', 'cycle_count' => 4]);
    MachineCycleCount::create(['machine_id' => $washer->id, 'date' => '2026-06-11', 'cycle_count' => 6]);

    $response = $this->getJson('/api/machines', ['X-Branch-Id' => $branch->id])->assertOk();

    expect((int) $response->json('0.total_cycles'))->toBe(60);
});

it('accepts a starting cycle count when creating and updating a machine', function () {
    $branch = makeBranch();
    actingAsRole('admin', $branch);

    $response = $this->postJson('/api/machines', [
        'name' => 'Washer 1',
        'type' => 'washer',
        'initial_cycle_count' => 4500,
    ], ['X-Branch-Id' => $branch->id]);

    $response->assertCreated()->assertJsonFragment(['initial_cycle_count' => 4500]);

    $machineId = $response->json('id');
    $this->putJson("/api/machines/{$machineId}", ['initial_cycle_count' => 4600], ['X-Branch-Id' => $branch->id])
        ->assertOk()
        ->assertJsonFragment(['initial_cycle_count' => 4600]);
});

it('excludes inactive machines from the daily cycle sheet', function () {
    $branch = makeBranch();
    actingAsRole('admin', $branch);

    Machine::create(['branch_id' => $branch->id, 'name' => 'Washer 1', 'type' => 'washer']);
    Machine::create(['branch_id' => $branch->id, 'name' => 'Broken Dryer', 'type' => 'dryer', 'is_active' => false]);

    $this->getJson('/api/machine-cycles', ['X-Branch-Id' => $branch->id])
        ->assertOk()
        ->assertJsonCount(1, 'machines');
});
