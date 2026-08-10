<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function phoneSetup(): array
{
    $branch = Branch::create(['name' => 'Main', 'is_active' => true]);
    $user   = User::factory()->create(['role' => 'admin']);
    $user->branches()->attach($branch->id, ['is_primary' => true]);
    Sanctum::actingAs($user);

    return [$branch, $user];
}

function allowBlankPhone(Branch $branch): void
{
    Setting::create([
        'key'       => 'customer_phone_required',
        'value'     => 'false',
        'group'     => 'general',
        'branch_id' => $branch->id,
    ]);
}

it('still demands a phone number by default', function () {
    [$branch] = phoneSetup();

    $this->postJson('/api/customers', ['name' => 'Ana'], ['X-Branch-Id' => $branch->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone');
});

it('accepts a customer with no phone once the branch turns it off', function () {
    [$branch] = phoneSetup();
    allowBlankPhone($branch);

    $this->postJson('/api/customers', ['name' => 'Ana'], ['X-Branch-Id' => $branch->id])
        ->assertCreated();

    expect(Customer::where('name', 'Ana')->value('phone'))->toBeNull();
});

it('stores an empty phone as null so a second one is not a duplicate', function () {
    [$branch] = phoneSetup();
    allowBlankPhone($branch);

    // The forms post '' rather than omitting the field. Stored as '' this would
    // trip the (branch_id, phone) unique index on the second customer.
    $this->postJson('/api/customers', ['name' => 'Ana', 'phone' => ''], ['X-Branch-Id' => $branch->id])
        ->assertCreated();
    $this->postJson('/api/customers', ['name' => 'Ben', 'phone' => ''], ['X-Branch-Id' => $branch->id])
        ->assertCreated();

    expect(Customer::whereNull('phone')->count())->toBe(2);
});

it('still blocks two customers sharing a real number', function () {
    [$branch] = phoneSetup();
    allowBlankPhone($branch);

    $this->postJson('/api/customers', ['name' => 'Ana', 'phone' => '09170000001'], ['X-Branch-Id' => $branch->id])
        ->assertCreated();

    $this->postJson('/api/customers', ['name' => 'Ben', 'phone' => '09170000001'], ['X-Branch-Id' => $branch->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone');
});

it('still blocks duplicate names, which is the dedupe key without a phone', function () {
    [$branch] = phoneSetup();
    allowBlankPhone($branch);

    $this->postJson('/api/customers', ['name' => 'Ana'], ['X-Branch-Id' => $branch->id])->assertCreated();
    $this->postJson('/api/customers', ['name' => 'Ana'], ['X-Branch-Id' => $branch->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('refuses to blank an existing phone while the branch requires one', function () {
    [$branch] = phoneSetup();

    $id = $this->postJson('/api/customers', ['name' => 'Ana', 'phone' => '09170000001'],
        ['X-Branch-Id' => $branch->id])->assertCreated()->json('id');

    $this->putJson("/api/customers/{$id}", ['phone' => ''], ['X-Branch-Id' => $branch->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('phone');
});

it('lets a phone be cleared once the branch stops requiring it', function () {
    [$branch] = phoneSetup();

    $id = $this->postJson('/api/customers', ['name' => 'Ana', 'phone' => '09170000001'],
        ['X-Branch-Id' => $branch->id])->assertCreated()->json('id');

    allowBlankPhone($branch);

    $this->putJson("/api/customers/{$id}", ['phone' => ''], ['X-Branch-Id' => $branch->id])->assertOk();

    expect(Customer::find($id)->phone)->toBeNull();
});

it('keeps searching by name when customers have no phone', function () {
    [$branch] = phoneSetup();
    allowBlankPhone($branch);

    $this->postJson('/api/customers', ['name' => 'Ana Cruz'], ['X-Branch-Id' => $branch->id])->assertCreated();

    $found = $this->getJson('/api/customers?search=Ana', ['X-Branch-Id' => $branch->id])
        ->assertOk()->json('data');

    expect($found)->toHaveCount(1);
    expect($found[0]['name'])->toBe('Ana Cruz');
});
