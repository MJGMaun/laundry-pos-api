<?php

namespace App\Http\Middleware;

use App\Services\PageAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard for the per-branch page matrix: `page:expenses,edit`.
 *
 * This is the real boundary. Hiding a menu item in the UI is presentation;
 * without this a cashier could still call the endpoint directly. Must run
 * after SetBranchContext, which resolves the branch this access is judged
 * against.
 */
class CheckPageAccess
{
	public function __construct(private PageAccessService $access)
	{
	}

	public function handle(Request $request, Closure $next, string $page, string $ability = 'view'): Response
	{
		$user = $request->user();

		if (! $user) {
			return response()->json(['message' => 'Unauthorized.'], 403);
		}

		// Super admins are never locked out — they are the ones who fix a
		// misconfigured matrix, so they must always reach Settings and Branches.
		if ($user->isSuperAdmin()) {
			return $next($request);
		}

		$branchId = $request->attributes->get('branch')?->id;

		// `page:cash-balance|day-summary,view` passes if ANY of the listed pages
		// grants it. Some endpoints genuinely back more than one page — Day
		// Summary reads the cash balance — and without this, granting the second
		// page would leave it broken.
		$granted = false;
		foreach (explode('|', $page) as $candidate) {
			if ($this->access->allows($branchId, $user->role, $candidate, $ability)) {
				$granted = true;
				break;
			}
		}

		if (! $granted) {
			return response()->json([
				'message' => 'You do not have access to this.',
				'page'    => $page,
				'ability' => $ability,
			], 403);
		}

		return $next($request);
	}
}
