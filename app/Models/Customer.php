<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
	use SoftDeletes;

	protected $fillable = [
		'branch_id',
		'name',
		'username',
		'phone',
		'email',
		'address',
		'notes',
		'loyalty_card_number',
		'loyalty_points',
		'total_visits',
		'total_spent',
	];

	protected $casts = [
		'loyalty_points' => 'integer',
		'total_visits' => 'integer',
		'total_spent' => 'decimal:2',
	];

	// Relationships
	public function orders()
	{
		return $this->hasMany(Order::class);
	}

	public function loyaltyTransactions()
	{
		return $this->hasMany(LoyaltyTransaction::class);
	}

	// Scopes
	public function scopeActive($query)
	{
		return $query->whereNull('deleted_at');
	}
}
