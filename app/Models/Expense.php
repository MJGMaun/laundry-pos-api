<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TracksDeletedBy;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
	use SoftDeletes, TracksDeletedBy;
	protected $fillable = [
		'branch_id',
		'expense_category_id',
		'user_id',
		'amount',
		'payment_method',
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
