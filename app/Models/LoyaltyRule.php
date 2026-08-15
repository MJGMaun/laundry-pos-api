<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyRule extends Model
{
    /** Reward types that come off the order total (as opposed to a physical item). */
    public const DISCOUNT_TYPES = ['free_load', 'fixed_discount'];

    protected $fillable = [
        'branch_id',
        'every_n_stamps',
        'reward_type',
        'reward_amount',
        'reward_description',
        'service_id',
        'is_active',
    ];

    protected $casts = [
        'is_active'     => 'boolean',
        'reward_amount' => 'decimal:2',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function rewards()
    {
        return $this->hasMany(LoyaltyReward::class, 'rule_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
