<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyStamp extends Model
{
    protected $fillable = [
        'customer_id',
        'branch_id',
        'order_id',
        'stamps_earned',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
