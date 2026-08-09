<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
	/**
	 * Chat is scoped to the active branch. Abort if no branch context
	 * (e.g. a super admin who hasn't selected a branch).
	 */
	private function requireBranchId(Request $request): int
	{
		$id = $this->branchId($request);
		abort_if($id === null, 422, 'Select a branch to use chat.');

		return $id;
	}

	/**
	 * Find-or-create the per-branch group conversation and make sure every
	 * current branch member is a participant.
	 */
	private function branchConversation(int $branchId): Conversation
	{
		$conversation = Conversation::firstOrCreate(['type' => 'branch', 'branch_id' => $branchId]);

		$memberIds = DB::table('branch_user')->where('branch_id', $branchId)->pluck('user_id');
		$conversation->participants()->syncWithoutDetaching($memberIds);

		return $conversation;
	}

	/** Confirm the user belongs to the conversation, else 403. */
	private function authorizeParticipant(Conversation $conversation, User $user): void
	{
		abort_unless($conversation->participants()->whereKey($user->id)->exists(), 403, 'Not part of this conversation.');
	}

	/** Shape a conversation for the list / direct endpoints. */
	private function presentConversation(Conversation $conversation, User $me): array
	{
		$last = $conversation->latestMessage;
		$lastReadAt = optional($conversation->participants->firstWhere('id', $me->id))->pivot->last_read_at;

		$unread = $conversation->messages()
			->where('user_id', '!=', $me->id)
			->when($lastReadAt, fn ($q) => $q->where('created_at', '>', $lastReadAt))
			->count();

		$title = '# Branch chat';
		$other = null;
		if ($conversation->type === 'direct') {
			$other = $conversation->participants->firstWhere('id', '!=', $me->id);
			$title = $other?->name ?? 'Unknown';
		}

		return [
			'id' => $conversation->id,
			'type' => $conversation->type,
			'title' => $title,
			'other' => $other ? ['id' => $other->id, 'name' => $other->name, 'username' => $other->username] : null,
			// Super admins see DMs from every branch at once, so name the branch.
			'branch_name' => $me->isSuperAdmin() ? optional($conversation->branch)->name : null,
			'last_message' => $last ? [
				'body' => $last->body,
				'sender_id' => $last->user_id,
				'created_at' => $last->created_at,
			] : null,
			'unread_count' => $unread,
			'updated_at' => $conversation->updated_at,
		];
	}

	// GET /messages/conversations
	public function conversations(Request $request)
	{
		$branchId = $this->requireBranchId($request);
		$me = $request->user();

		// Ensure the branch group exists and the user is in it.
		$this->branchConversation($branchId);

		$conversations = Conversation::query()
			->whereHas('participants', fn ($q) => $q->whereKey($me->id))
			// A DM to a super admin is filed under the sender's branch, so
			// scoping them to the selected branch would hide it until the super
			// admin happened to switch there. Show all of their DMs instead.
			->where(fn ($q) => $q->where('branch_id', $branchId)
				->when($me->isSuperAdmin(), fn ($w) => $w->orWhere('type', 'direct')))
			->with(['participants', 'latestMessage', 'branch:id,name'])
			->get()
			->sortByDesc(fn ($c) => $c->type === 'branch' ? PHP_INT_MAX : optional($c->latestMessage)->created_at?->timestamp ?? $c->updated_at->timestamp)
			->values();

		return response()->json([
			'data' => $conversations->map(fn ($c) => $this->presentConversation($c, $me)),
		]);
	}

	// GET /messages/conversations/{conversation}
	public function show(Request $request, Conversation $conversation)
	{
		$me = $request->user();
		$this->authorizeParticipant($conversation, $me);

		$messages = $conversation->messages()
			->with('sender:id,name,username')
			->orderByDesc('id')
			->paginate(50);

		// Mark read for this user.
		$conversation->participants()->updateExistingPivot($me->id, ['last_read_at' => now()]);

		return response()->json([
			'data' => $messages->getCollection()->map(fn (Message $m) => [
				'id' => $m->id,
				'body' => $m->body,
				'sender_id' => $m->user_id,
				'sender' => ['id' => $m->sender->id, 'name' => $m->sender->name, 'username' => $m->sender->username],
				'created_at' => $m->created_at,
			])->values(),
			'has_more' => $messages->hasMorePages(),
		]);
	}

	// POST /messages/conversations/{conversation}/messages
	public function send(Request $request, Conversation $conversation)
	{
		$me = $request->user();
		$this->authorizeParticipant($conversation, $me);

		$data = $request->validate(['body' => 'required|string|max:2000']);

		$message = $conversation->messages()->create([
			'user_id' => $me->id,
			'body' => trim($data['body']),
		]);

		$conversation->touch();
		$conversation->participants()->updateExistingPivot($me->id, ['last_read_at' => now()]);

		return response()->json([
			'data' => [
				'id' => $message->id,
				'body' => $message->body,
				'sender_id' => $me->id,
				'sender' => ['id' => $me->id, 'name' => $me->name, 'username' => $me->username],
				'created_at' => $message->created_at,
			],
		], 201);
	}

	// POST /messages/direct  { username }
	public function direct(Request $request)
	{
		$branchId = $this->requireBranchId($request);
		$me = $request->user();

		$data = $request->validate(['username' => 'required|string']);

		$target = User::where('username', $data['username'])->first();
		abort_if(! $target || $target->id === $me->id, 422, 'No such user to message.');

		$shares = DB::table('branch_user')
			->where('branch_id', $branchId)
			->where('user_id', $target->id)
			->exists();

		// Branch members can always reach each other. Beyond that: a super admin
		// may message anyone, and anyone may message a super admin — the latter
		// only works if you already know the exact username, since super admins
		// are left out of user search.
		abort_unless(
			$shares || $me->isSuperAdmin() || $target->isSuperAdmin(),
			403,
			'You can only message people in your branch.'
		);

		// Find an existing direct conversation in this branch with exactly these two.
		$conversation = Conversation::where('type', 'direct')
			->where('branch_id', $branchId)
			->whereHas('participants', fn ($q) => $q->whereKey($me->id))
			->whereHas('participants', fn ($q) => $q->whereKey($target->id))
			->first();

		if (! $conversation) {
			$conversation = Conversation::create(['type' => 'direct', 'branch_id' => $branchId]);
			$conversation->participants()->attach([$me->id, $target->id]);
		}

		$conversation->load(['participants', 'latestMessage', 'branch:id,name']);

		return response()->json(['data' => $this->presentConversation($conversation, $me)]);
	}

	// GET /messages/users/search?q=
	public function searchUsers(Request $request)
	{
		$branchId = $this->requireBranchId($request);
		$me = $request->user();
		$q = trim((string) $request->query('q', ''));

		$users = User::where('id', '!=', $me->id)
			// A super admin can message anyone, so they search across all branches.
			// Everyone else browses their own branch, with super admins withheld.
			->when(! $me->isSuperAdmin(), fn ($query) => $query
				->whereIn('id', DB::table('branch_user')->where('branch_id', $branchId)->pluck('user_id'))
				->where('role', '!=', 'super_admin'))
			->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
				->where('username', 'like', "%{$q}%")
				->orWhere('name', 'like', "%{$q}%")))
			->orderBy('username')
			->limit(10)
			->get(['id', 'name', 'username', 'role']);

		// Typing a super admin's exact username surfaces them even though they
		// are unlisted — support stays reachable by whoever knows the handle.
		if ($q !== '' && ! $me->isSuperAdmin()) {
			$hidden = User::where('username', $q)
				->where('role', 'super_admin')
				->where('id', '!=', $me->id)
				->first(['id', 'name', 'username', 'role']);

			if ($hidden && ! $users->contains('id', $hidden->id)) {
				$users->prepend($hidden);
			}
		}

		return response()->json(['data' => $users->values()]);
	}

	// GET /messages/unread-count
	public function unreadCount(Request $request)
	{
		$branchId = $this->requireBranchId($request);
		$me = $request->user();

		$this->branchConversation($branchId);

		$count = DB::table('messages')
			->join('conversation_user as cu', 'cu.conversation_id', '=', 'messages.conversation_id')
			->join('conversations as c', 'c.id', '=', 'messages.conversation_id')
			->where('cu.user_id', $me->id)
			// Mirrors conversations(): a super admin's DMs count no matter which
			// branch they were opened from.
			->where(fn ($w) => $w->where('c.branch_id', $branchId)
				->when($me->isSuperAdmin(), fn ($s) => $s->orWhere('c.type', 'direct')))
			->where('messages.user_id', '!=', $me->id)
			->where(fn ($w) => $w->whereNull('cu.last_read_at')->orWhereColumn('messages.created_at', '>', 'cu.last_read_at'))
			->count();

		return response()->json(['count' => $count]);
	}
}
