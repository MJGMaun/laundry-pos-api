<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TracksDeletedBy;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
	use SoftDeletes, TracksDeletedBy;

	protected $fillable = [
		'order_id',
		'amount',
		'tip_amount',
		'method',
		'type',
		'tendered',
		'change_amount',
		'reference_number',
		'receipt_path',
		'matched_at',
		'matched_by',
		'method_changed_from',
		'method_changed_by',
		'method_changed_at',
	];

	// The file path is internal; clients get a has_receipt flag and fetch the
	// image through the authenticated receipt endpoint.
	protected $hidden = ['receipt_path'];

	protected $appends = ['has_receipt'];

	protected $casts = [
		'amount' => 'decimal:2',
		'tip_amount' => 'decimal:2',
		'tendered' => 'decimal:2',
		'change_amount' => 'decimal:2',
		'matched_at' => 'datetime',
		'method_changed_at' => 'datetime',
	];

	public function getHasReceiptAttribute(): bool
	{
		return !empty($this->attributes['receipt_path']);
	}

	public function matchedBy()
	{
		return $this->belongsTo(User::class, 'matched_by');
	}

	public function methodChangedBy()
	{
		return $this->belongsTo(User::class, 'method_changed_by');
	}

	public function order()
	{
		return $this->belongsTo(Order::class);
	}
}
