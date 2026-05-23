<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyCashBalance extends Model
{
    protected $fillable = ['branch_id', 'date', 'starting_balance', 'set_by'];

    protected $casts = [
        'date'             => 'date',
        'starting_balance' => 'decimal:2',
    ];

    public function setBy()
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
