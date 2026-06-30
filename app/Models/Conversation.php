<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
	protected $fillable = ['type', 'branch_id'];

	public function participants()
	{
		return $this->belongsToMany(User::class, 'conversation_user')
			->withPivot('last_read_at')
			->withTimestamps();
	}

	public function messages()
	{
		return $this->hasMany(Message::class);
	}

	public function latestMessage()
	{
		return $this->hasOne(Message::class)->latestOfMany();
	}

	public function branch()
	{
		return $this->belongsTo(Branch::class);
	}
}
