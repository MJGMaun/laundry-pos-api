<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TracksDeletedBy;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
	use SoftDeletes, TracksDeletedBy;
	protected $fillable = [
		'branch_id',
		'client_id',
		'customer_id',
		'user_id',
		'order_number',
		'subtotal',
		'extra_fees',
		'discount_amount',
		'total_amount',
		'status',
		'loyalty_points_earned',
		'loyalty_points_redeemed',
		'estimated_ready_at',
		'delivery_scheduled_at',
		'delivered_at',
		'notes',
	];

	protected $casts = [
		'subtotal' => 'decimal:2',
		'extra_fees' => 'decimal:2',
		'discount_amount' => 'decimal:2',
		'total_amount' => 'decimal:2',
		'loyalty_points_earned' => 'integer',
		'loyalty_points_redeemed' => 'integer',
		'estimated_ready_at' => 'datetime',
		'delivery_scheduled_at' => 'datetime',
		'delivered_at' => 'datetime',
	];

	protected $appends = ['load_count'];

	// Load count, computed exactly like the dashboard (ReportsController@summary):
	//   quantity  → each unit is a load (sum of quantity)
	//   per_order → all of a category's items in this order = 1 load; multiple
	//               batches use the max quantity (e.g. Wash x2 + Dry x2 = 2)
	//   none      → not a load (e.g. add-ons like "Add Dry")
	public function getLoadCountAttribute(): float
	{
		$loads = $this->relationLoaded('loads') ? $this->loads : $this->loads()->get();
		$loads->loadMissing('service.category');

		$quantityLoads = 0.0;
		$perOrderMax   = []; // category_id => max quantity

		foreach ($loads as $load) {
			$rule = optional(optional($load->service)->category)->load_rule;

			if ($rule === 'per_order') {
				$catId = $load->service->category_id;
				$perOrderMax[$catId] = max($perOrderMax[$catId] ?? 0, (float) $load->quantity);
			} elseif ($rule === 'none') {
				continue;
			} else {
				// default to the 'quantity' rule when unset
				$quantityLoads += (float) $load->quantity;
			}
		}

		return round($quantityLoads + array_sum($perOrderMax), 2);
	}

	// Relationships
	public function customer()
	{
		return $this->belongsTo(Customer::class)->withTrashed();
	}

	public function user()
	{
		return $this->belongsTo(User::class);
	}

	public function loads()
	{
		return $this->hasMany(Load::class);
	}

	public function payments()
	{
		return $this->hasMany(Payment::class);
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

	public function scopeUnpaid($query)
	{
		return $query->whereRaw(
			'COALESCE((SELECT SUM(amount) FROM payments WHERE payments.order_id = orders.id AND payments.deleted_at IS NULL), 0) < total_amount'
		);
	}
}
