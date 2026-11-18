<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountCheck extends Model
{
	protected $fillable = [
		'branch_id',
		'method',
		'counted_amount',
		'expected_amount',
		'difference',
		'note',
		'user_id',
	];

	protected $casts = [
		'counted_amount'  => 'decimal:2',
		'expected_amount' => 'decimal:2',
		'difference'      => 'decimal:2',
	];

	public function user()
	{
		return $this->belongsTo(User::class);
	}
}
