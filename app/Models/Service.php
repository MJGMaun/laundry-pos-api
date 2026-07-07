<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TracksDeletedBy;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
	use SoftDeletes, TracksDeletedBy;
	protected $fillable = [
		'branch_id',
		'name',
		'category_id',
		'pricing_type',
		'price',
		'is_active',
		'is_loyalty_eligible',
	];

	public function branch()
	{
		return $this->belongsTo(Branch::class);
	}

	public function category()
	{
		return $this->belongsTo(ServiceCategory::class, 'category_id');
	}

	protected $casts = [
		'price' => 'decimal:2',
		'is_active' => 'boolean',
		'is_loyalty_eligible' => 'boolean',
	];

	// Scopes
	public function scopeActive($query)
	{
		return $query->where('is_active', true);
	}
}
