<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $fillable = [
        'branch_id',
        'customer_id',
        'user_id',
        'scheduled_at',
        'address',
        'notes',
        'status',
        'picked_up_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'picked_up_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
