<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
	protected $fillable = [
		'name',
		'address',
		'phone',
		'email',
		'tin',
		'is_active',
		'is_test',
	];

	protected $casts = [
		'is_active' => 'boolean',
		'is_test'   => 'boolean',
	];

	public function users()
	{
		return $this->belongsToMany(User::class, 'branch_user')
			->withPivot('is_primary')
			->withTimestamps();
	}

	public function services()
	{
		return $this->hasMany(Service::class);
	}

	public function orders()
	{
		return $this->hasMany(Order::class);
	}

	public function customers()
	{
		return $this->hasMany(Customer::class);
	}

	public function expenses()
	{
		return $this->hasMany(Expense::class);
	}

	public function settings()
	{
		return $this->hasMany(Setting::class);
	}
}
