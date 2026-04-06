<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
	use SoftDeletes;
	protected $fillable = [
		'name',
		'pricing_type',
		'price',
		'is_active',
	];

	protected $casts = [
		'price' => 'decimal:2',
		'is_active' => 'boolean',
	];

	// Scopes
	public function scopeActive($query)
	{
		return $query->where('is_active', true);
	}
}
