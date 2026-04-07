<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyTier extends Model
{
	protected $fillable = [
		'name',
		'multiplier',
		'min_spend_threshold',
		'is_default',
	];

	protected $casts = [
		'multiplier' => 'decimal:1',
		'min_spend_threshold' => 'decimal:2',
		'is_default' => 'boolean',
	];

	// Relationships
	public function customers()
	{
		return $this->hasMany(Customer::class);
	}

	// Scopes
	public function scopeDefault($query)
	{
		return $query->where('is_default', true);
	}
}