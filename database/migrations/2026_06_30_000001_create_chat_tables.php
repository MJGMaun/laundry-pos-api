<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
	public function up(): void
	{
		// A conversation is either a 1:1 direct chat or a per-branch group chat.
		// Chat is scoped to a branch, so both types carry a branch_id.
		Schema::create('conversations', function (Blueprint $table) {
			$table->id();
			$table->enum('type', ['direct', 'branch']);
			$table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
			$table->timestamps();

			$table->index(['branch_id', 'type']);
		});

		// Participants. last_read_at drives unread counts.
		Schema::create('conversation_user', function (Blueprint $table) {
			$table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
			$table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
			$table->timestamp('last_read_at')->nullable();
			$table->timestamps();

			$table->primary(['conversation_id', 'user_id']);
		});

		Schema::create('messages', function (Blueprint $table) {
			$table->id();
			$table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
			$table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // sender
			$table->text('body');
			$table->timestamps();

			$table->index(['conversation_id', 'id']);
		});
	}

	public function down(): void
	{
		Schema::dropIfExists('messages');
		Schema::dropIfExists('conversation_user');
		Schema::dropIfExists('conversations');
	}
};
