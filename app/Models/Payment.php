<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
	protected $fillable = [
		'order_id',
		'amount',
		'method',
		'type',
		'tendered',
		'change_amount',
		'reference_number',
	];

	protected $casts = [
		'amount' => 'decimal:2',
		'tendered' => 'decimal:2',
		'change_amount' => 'decimal:2',
	];

	public function order()
	{
		return $this->belongsTo(Order::class);
	}
}
