<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
	protected $fillable = [
		'branch_id',
		'expense_category_id',
		'user_id',
		'amount',
		'expense_date',
		'description',
		'receipt_reference',
	];

	protected $casts = [
		'amount'       => 'decimal:2',
		'expense_date' => 'date:Y-m-d',
	];

	public function category()
	{
		return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
	}

	public function user()
	{
		return $this->belongsTo(User::class);
	}
}
