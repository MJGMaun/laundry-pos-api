<?php

use App\Models\Branch;
use App\Models\Conversation;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function chatBranch(string $name = 'Main Branch'): Branch
{
	return Branch::create(['name' => $name, 'is_active' => true]);
}

function chatUser(Branch $branch, string $username, string $role = 'cashier'): User
{
	$user = User::factory()->create(['username' => $username, 'role' => $role]);
	$user->branches()->attach($branch->id, ['is_primary' => true]);

	return $user;
}

it('starts a direct conversation with a branch mate by username', function () {
	$branch = chatBranch();
	$me = chatUser($branch, 'maria');
	$other = chatUser($branch, 'juan');
	Sanctum::actingAs($me);

	$res = $this->postJson('/api/messages/direct', ['username' => 'juan'], ['X-Branch-Id' => $branch->id]);

	$res->assertOk()
		->assertJsonPath('data.type', 'direct')
		->assertJsonPath('data.other.username', 'juan');

	// Calling again returns the same conversation, not a duplicate.
	$first = $res->json('data.id');
	$again = $this->postJson('/api/messages/direct', ['username' => 'juan'], ['X-Branch-Id' => $branch->id]);
	expect($again->json('data.id'))->toBe($first);
	expect(Conversation::where('type', 'direct')->count())->toBe(1);
});

it('blocks messaging someone outside the active branch', function () {
	$branchA = chatBranch('A');
	$branchB = chatBranch('B');
	$me = chatUser($branchA, 'maria');
	$outsider = chatUser($branchB, 'pedro');
	Sanctum::actingAs($me);

	$this->postJson('/api/messages/direct', ['username' => 'pedro'], ['X-Branch-Id' => $branchA->id])
		->assertForbidden();
});

it('sends a message and the recipient sees it as unread', function () {
	$branch = chatBranch();
	$me = chatUser($branch, 'maria');
	$other = chatUser($branch, 'juan');

	Sanctum::actingAs($me);
	$convo = $this->postJson('/api/messages/direct', ['username' => 'juan'], ['X-Branch-Id' => $branch->id])->json('data.id');
	$this->postJson("/api/messages/conversations/{$convo}/messages", ['body' => 'hello'], ['X-Branch-Id' => $branch->id])
		->assertCreated()
		->assertJsonPath('data.body', 'hello');

	// Recipient has 1 unread.
	Sanctum::actingAs($other);
	$this->getJson('/api/messages/unread-count', ['X-Branch-Id' => $branch->id])
		->assertOk()
		->assertJsonPath('count', 1);

	// Opening the thread marks it read.
	$this->getJson("/api/messages/conversations/{$convo}", ['X-Branch-Id' => $branch->id])->assertOk();
	$this->getJson('/api/messages/unread-count', ['X-Branch-Id' => $branch->id])
		->assertJsonPath('count', 0);
});

it('does not count my own messages as unread', function () {
	$branch = chatBranch();
	$me = chatUser($branch, 'maria');
	chatUser($branch, 'juan');

	Sanctum::actingAs($me);
	$convo = $this->postJson('/api/messages/direct', ['username' => 'juan'], ['X-Branch-Id' => $branch->id])->json('data.id');
	$this->postJson("/api/messages/conversations/{$convo}/messages", ['body' => 'hi'], ['X-Branch-Id' => $branch->id]);

	$this->getJson('/api/messages/unread-count', ['X-Branch-Id' => $branch->id])
		->assertJsonPath('count', 0);
});

it('forbids reading a conversation you are not part of', function () {
	$branch = chatBranch();
	$a = chatUser($branch, 'a');
	$b = chatUser($branch, 'b');
	$intruder = chatUser($branch, 'c');

	Sanctum::actingAs($a);
	$convo = $this->postJson('/api/messages/direct', ['username' => 'b'], ['X-Branch-Id' => $branch->id])->json('data.id');

	Sanctum::actingAs($intruder);
	$this->getJson("/api/messages/conversations/{$convo}", ['X-Branch-Id' => $branch->id])
		->assertForbidden();
});

it('lists conversations with the branch group pinned first', function () {
	$branch = chatBranch();
	$me = chatUser($branch, 'maria');
	chatUser($branch, 'juan');
	Sanctum::actingAs($me);

	$this->postJson('/api/messages/direct', ['username' => 'juan'], ['X-Branch-Id' => $branch->id]);

	$res = $this->getJson('/api/messages/conversations', ['X-Branch-Id' => $branch->id])->assertOk();
	expect($res->json('data.0.type'))->toBe('branch');
	expect(collect($res->json('data'))->pluck('type'))->toContain('direct');
});

it('autocompletes branch users by username and excludes self', function () {
	$branch = chatBranch();
	$me = chatUser($branch, 'maria');
	chatUser($branch, 'juancho');
	Sanctum::actingAs($me);

	$res = $this->getJson('/api/messages/users/search?q=jua', ['X-Branch-Id' => $branch->id])->assertOk();
	$usernames = collect($res->json('data'))->pluck('username');
	expect($usernames)->toContain('juancho');
	expect($usernames)->not->toContain('maria');
});
