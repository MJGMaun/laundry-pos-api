<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyReward extends Model
{
    protected $fillable = [
        'customer_id',
        'branch_id',
        'rule_id',
        'earned_at',
        'redeemed_at',
        'redeemed_on_order_id',
    ];

    protected $casts = [
        'earned_at'    => 'datetime',
        'redeemed_at'  => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function rule()
    {
        return $this->belongsTo(LoyaltyRule::class, 'rule_id');
    }

    public function scopePending($query)
    {
        return $query->whereNull('redeemed_at');
    }
}
