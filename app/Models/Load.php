<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Load extends Model
{
	use SoftDeletes;
	protected $fillable = [
		'order_id',
		'service_id',
		'service_name_snapshot',
		'unit_price_snapshot',
		'quantity',
		'line_total',
		'status',
		'notes',
	];

	protected $casts = [
		'unit_price_snapshot' => 'decimal:2',
		'quantity' => 'decimal:2',
		'line_total' => 'decimal:2',
	];

	public function order()
	{
		return $this->belongsTo(Order::class);
	}

	public function service()
	{
		return $this->belongsTo(Service::class);
	}
}
