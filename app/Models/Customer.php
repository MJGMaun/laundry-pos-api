<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
	use SoftDeletes;

	protected $fillable = [
		'name',
		'phone',
		'email',
		'address',
		'loyalty_card_number',
		'loyalty_tier_id',
		'loyalty_points',
		'total_visits',
		'total_spent',
	];

	/**
	 * The attributes that should have a default value.
	 *
	 * @var list<string>
	 */
	protected $attributes = [
		'loyalty_tier_id' => 1,
	];

	protected $casts = [
		'loyalty_points' => 'integer',
		'total_visits' => 'integer',
		'total_spent' => 'decimal:2',
	];

	// Relationships
	public function loyaltyTier()
	{
		return $this->belongsTo(LoyaltyTier::class);
	}

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
