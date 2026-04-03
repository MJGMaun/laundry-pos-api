<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
	protected $fillable = [
		'service_category_id',
		'name',
		'pricing_type',
		'price',
		'is_active',
	];

	protected $casts = [
		'price' => 'decimal:2',
		'is_active' => 'boolean',
	];

	// Relationships
	public function category()
	{
		return $this->belongsTo(ServiceCategory::class, 'service_category_id');
	}

	// Scopes
	public function scopeActive($query)
	{
		return $query->where('is_active', true);
	}
}