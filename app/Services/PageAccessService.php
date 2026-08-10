<?php

namespace App\Services;

use App\Models\BranchPageAccess;
use App\Support\PageRegistry;

/**
 * Resolves who may reach which page for a given branch. The single place that
 * answers the question — the API middleware and the payload the frontend uses
 * to build its menu both come through here, so a page can never be hidden in
 * the UI while still being reachable over the API, or the reverse.
 */
class PageAccessService
{
	/**
	 * Effective access for one role at one branch: registry defaults with the
	 * branch's stored overrides laid on top.
	 *
	 * @return array<string, array{view: bool, edit: bool}>
	 */
	public function matrix(?int $branchId, string $role): array
	{
		if ($role === 'super_admin') {
			return $this->everything();
		}

		$overrides = $branchId === null
			? collect()
			: BranchPageAccess::where('branch_id', $branchId)->where('role', $role)->get()->keyBy('page');

		$matrix = [];

		foreach (PageRegistry::all() as $page => $meta) {
			$default = PageRegistry::default($page, $role);

			// A locked page is never grantable, so overrides are ignored rather
			// than trusted — a stale row cannot hand out Data Management.
			if (! $meta['configurable'] || ! isset($overrides[$page])) {
				$matrix[$page] = $default;
				continue;
			}

			$row = $overrides[$page];

			$matrix[$page] = [
				'view' => (bool) $row->can_view,
				// Editing without viewing is meaningless; keep the pair coherent
				// so the UI never has to defend against it.
				'edit' => (bool) $row->can_view && (bool) $row->can_edit,
			];
		}

		return $matrix;
	}

	public function allows(?int $branchId, string $role, string $page, string $ability = 'view'): bool
	{
		$matrix = $this->matrix($branchId, $role);

		return (bool) ($matrix[$page][$ability] ?? false);
	}

	private function everything(): array
	{
		$matrix = [];
		foreach (PageRegistry::keys() as $page) {
			$matrix[$page] = ['view' => true, 'edit' => true];
		}

		return $matrix;
	}
}
