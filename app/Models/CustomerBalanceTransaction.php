<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line in a customer's prepaid-balance ledger. `amount` is signed:
 * positive adds to the balance, negative takes from it.
 */
class CustomerBalanceTransaction extends Model
{
	protected $fillable = [
		'customer_id',
		'branch_id',
		'type',
		'method',
		'amount',
		'balance_after',
		'payment_id',
		'reverses_id',
		'voided_at',
		'note',
		'user_id',
	];

	protected $casts = [
		'amount'        => 'decimal:2',
		'balance_after' => 'decimal:2',
		'voided_at'     => 'datetime',
	];

	public function customer()
	{
		return $this->belongsTo(Customer::class);
	}

	public function payment()
	{
		return $this->belongsTo(Payment::class)->withTrashed();
	}

	public function user()
	{
		return $this->belongsTo(User::class);
	}
}
