<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Machine;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

// Super-admin audit log of soft-deleted records across all branches.
// View-only: nothing here restores or purges. deleted_by is stamped by the
// TracksDeletedBy model trait on every soft delete.
class DeletedRecordsController extends Controller implements HasMiddleware
{
	public static function middleware(): array
	{
		return [
			new Middleware('role:super_admin'),
		];
	}

	public function index(Request $request)
	{
		$type = $request->input('type', 'payments');

		$query = match ($type) {
			'payments' => Payment::onlyTrashed()
				->join('orders', 'payments.order_id', '=', 'orders.id')
				->leftJoin('customers', 'orders.customer_id', '=', 'customers.id')
				->leftJoin('branches', 'orders.branch_id', '=', 'branches.id')
				->leftJoin('users as deleters', 'payments.deleted_by', '=', 'deleters.id')
				->select([
					'payments.id',
					'payments.method',
					'payments.type',
					'payments.amount',
					'payments.created_at',
					'payments.deleted_at',
					'orders.id as order_id',
					'orders.order_number',
					'customers.name as customer_name',
					'branches.name as branch_name',
					'deleters.name as deleted_by_name',
				]),

			'orders' => Order::onlyTrashed()
				->leftJoin('customers', 'orders.customer_id', '=', 'customers.id')
				->leftJoin('branches', 'orders.branch_id', '=', 'branches.id')
				->leftJoin('users as deleters', 'orders.deleted_by', '=', 'deleters.id')
				->select([
					'orders.id',
					'orders.order_number',
					'orders.total_amount as amount',
					'orders.status',
					'orders.created_at',
					'orders.deleted_at',
					'customers.name as customer_name',
					'branches.name as branch_name',
					'deleters.name as deleted_by_name',
				]),

			'expenses' => Expense::onlyTrashed()
				->leftJoin('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
				->leftJoin('branches', 'expenses.branch_id', '=', 'branches.id')
				->leftJoin('users as deleters', 'expenses.deleted_by', '=', 'deleters.id')
				->select([
					'expenses.id',
					'expenses.description',
					'expenses.amount',
					'expenses.expense_date',
					'expenses.created_at',
					'expenses.deleted_at',
					'expense_categories.name as category_name',
					'branches.name as branch_name',
					'deleters.name as deleted_by_name',
				]),

			'customers' => Customer::onlyTrashed()
				->leftJoin('branches', 'customers.branch_id', '=', 'branches.id')
				->leftJoin('users as deleters', 'customers.deleted_by', '=', 'deleters.id')
				->select([
					'customers.id',
					'customers.name',
					'customers.phone',
					'customers.total_spent as amount',
					'customers.created_at',
					'customers.deleted_at',
					'branches.name as branch_name',
					'deleters.name as deleted_by_name',
				]),

			'services' => Service::onlyTrashed()
				->leftJoin('service_categories', 'services.category_id', '=', 'service_categories.id')
				->leftJoin('users as deleters', 'services.deleted_by', '=', 'deleters.id')
				->select([
					'services.id',
					'services.name',
					'services.price as amount',
					'services.created_at',
					'services.deleted_at',
					'service_categories.name as category_name',
					'deleters.name as deleted_by_name',
				]),

			'machines' => Machine::onlyTrashed()
				->leftJoin('branches', 'machines.branch_id', '=', 'branches.id')
				->leftJoin('users as deleters', 'machines.deleted_by', '=', 'deleters.id')
				->select([
					'machines.id',
					'machines.name',
					'machines.type',
					'machines.created_at',
					'machines.deleted_at',
					'branches.name as branch_name',
					'deleters.name as deleted_by_name',
				]),

			default => abort(422, 'Unknown type.'),
		};

		return response()->json(
			['type' => $type] + $query->orderByDesc($this->deletedAtColumn($type))->paginate(50)->toArray()
		);
	}

	private function deletedAtColumn(string $type): string
	{
		return match ($type) {
			'payments'  => 'payments.deleted_at',
			'orders'    => 'orders.deleted_at',
			'expenses'  => 'expenses.deleted_at',
			'customers' => 'customers.deleted_at',
			'services'  => 'services.deleted_at',
			'machines'  => 'machines.deleted_at',
		};
	}
}
