<?php

namespace App\Models\Concerns;

// Stamps deleted_by with the authenticated user on every soft delete, no
// matter which controller or service triggers it. SoftDeletes' runSoftDelete
// only persists deleted_at/updated_at, so we write the column directly —
// without touching timestamps.
trait TracksDeletedBy
{
	protected static function bootTracksDeletedBy(): void
	{
		static::deleting(function ($model) {
			if ($model->isForceDeleting() || auth()->id() === null) {
				return;
			}

			$model->newModelQuery()
				->whereKey($model->getKey())
				->update(['deleted_by' => auth()->id()]);

			$model->deleted_by = auth()->id();
		});
	}
}
