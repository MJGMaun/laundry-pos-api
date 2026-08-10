<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BranchPageAccess extends Model
{
	protected $table = 'branch_page_access';

	protected $fillable = ['branch_id', 'page', 'role', 'can_view', 'can_edit'];

	protected $casts = [
		'can_view' => 'boolean',
		'can_edit' => 'boolean',
	];

	public function branch()
	{
		return $this->belongsTo(Branch::class);
	}
}
