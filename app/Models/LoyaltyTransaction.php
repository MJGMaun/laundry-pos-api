<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyTransaction extends Model
{
	protected $fillable = [
		'customer_id',
		'order_id',
		'type',
		'points',
		'balance_after',
		'description',
	];

	protected $casts = [
		'points' => 'integer',
		'balance_after' => 'integer',
	];

	// Relationships
	public function customer()
	{
		return $this->belongsTo(Customer::class);
	}

	public function order()
	{
		return $this->belongsTo(Order::class);
	}

	// Scopes
	public function scopeEarned($query)
	{
		return $query->where('type', 'earn');
	}
}