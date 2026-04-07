<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
	protected $fillable = [
		'customer_id',
		'user_id',
		'order_number',
		'subtotal',
		'discount_amount',
		'total_amount',
		'status',
		'loyalty_points_earned',
		'loyalty_points_redeemed',
		'estimated_ready_at',
		'notes',
	];

	protected $casts = [
		'subtotal' => 'decimal:2',
		'discount_amount' => 'decimal:2',
		'total_amount' => 'decimal:2',
		'loyalty_points_earned' => 'integer',
		'loyalty_points_redeemed' => 'integer',
		'estimated_ready_at' => 'datetime',
	];

	// Relationships
	public function customer()
	{
		return $this->belongsTo(Customer::class);
	}

	public function user()
	{
		return $this->belongsTo(User::class);
	}

	public function loads()
	{
		return $this->hasMany(Load::class);
	}

	public function loyaltyTransactions()
	{
		return $this->hasMany(LoyaltyTransaction::class);
	}

	// Scopes
	public function scopePending($query)
	{
		return $query->where('status', 'pending');
	}
}
