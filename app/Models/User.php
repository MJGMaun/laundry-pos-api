<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;


class User extends Authenticatable
{
	/** @use HasFactory<\Database\Factories\UserFactory> */
	use HasApiTokens, HasFactory, Notifiable;

	/**
	 * The attributes that are mass assignable.
	 *
	 * @var list<string>
	 */
	protected $fillable = [
		'name',
		'username',
		'email',
		'password',
		'role'
	];

	/**
	 * The attributes that should have a default value.
	 *
	 * @var list<string>
	 */
	protected $attributes = [
		'role' => 'staff',
	];

	/**
	 * The attributes that should be hidden for serialization.
	 *
	 * @var list<string>
	 */
	protected $hidden = [
		'password',
		'remember_token',
	];

	/**
	 * Get the attributes that should be cast.
	 *
	 * @return array<string, string>
	 */
	protected function casts(): array
	{
		return [
			'email_verified_at' => 'datetime',
			'password' => 'hashed',
		];
	}

	public function branches()
	{
		return $this->belongsToMany(Branch::class, 'branch_user')
			->withPivot('is_primary')
			->withTimestamps();
	}

	public function isSuperAdmin(): bool
	{
		return $this->role === 'super_admin';
	}

	public function isAdmin(): bool
	{
		return $this->role === 'admin';
	}

	public function isCashier(): bool
	{
		return $this->role === 'cashier';
	}

	public function isStaff(): bool
	{
		return $this->role === 'staff';
	}
}
