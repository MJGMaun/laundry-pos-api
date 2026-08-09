<?php

namespace App\Models;

use App\Models\Concerns\TracksDeletedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccountMovement extends Model
{
	use SoftDeletes, TracksDeletedBy;

	protected $fillable = [
		'branch_id',
		'type',
		'method',
		'to_method',
		'amount',
		'occurred_on',
		'recipient',
		'note',
		'user_id',
	];

	protected $casts = [
		'amount'      => 'decimal:2',
		'occurred_on' => 'date:Y-m-d',
	];

	public function user()
	{
		return $this->belongsTo(User::class);
	}

	public function branch()
	{
		return $this->belongsTo(Branch::class);
	}
}
