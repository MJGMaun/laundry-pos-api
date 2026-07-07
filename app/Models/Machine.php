<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TracksDeletedBy;
use Illuminate\Database\Eloquent\SoftDeletes;

class Machine extends Model
{
    use SoftDeletes, TracksDeletedBy;

    protected $fillable = [
        'branch_id',
        'name',
        'type',
        'initial_cycle_count',
        'is_active',
    ];

    protected $casts = [
        'initial_cycle_count' => 'integer',
        'is_active' => 'boolean',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function cycleCounts()
    {
        return $this->hasMany(MachineCycleCount::class);
    }
}
