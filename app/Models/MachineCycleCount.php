<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineCycleCount extends Model
{
    protected $fillable = [
        'machine_id',
        'date',
        'cycle_count',
        'recorded_by',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'cycle_count' => 'integer',
    ];

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
