<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\LoadController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\BranchController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
	// Auth (no branch context needed)
	Route::post('/logout', [AuthController::class, 'logout']);
	Route::get('/user', function (Request $request) {
		return $request->user()->load(['branches' => function ($q) {
			$q->withPivot('is_primary');
		}]);
	});

	// Services (global, not branch-scoped)
	Route::apiResource('services', ServiceController::class);
	Route::patch('services/{service}/toggle', [ServiceController::class, 'toggle']);

	// Expense categories (global, not branch-scoped)
	Route::apiResource('expense-categories', ExpenseCategoryController::class)->only(['index', 'store', 'destroy']);

	// Branches (management — no branch context needed)
	Route::apiResource('branches', BranchController::class)->except(['show']);
	Route::get('branches/{branch}/users',                            [BranchController::class, 'users']);
	Route::post('branches/{branch}/users',                           [BranchController::class, 'assignUser']);
	Route::delete('branches/{branch}/users/{user}',                  [BranchController::class, 'removeUser']);
	Route::get('branches/{branch}/services',                         [BranchController::class, 'branchServices']);
	Route::post('branches/{branch}/services',                        [BranchController::class, 'createBranchService']);
	Route::put('branches/{branch}/services/{service}',               [BranchController::class, 'updateBranchService']);
	Route::delete('branches/{branch}/services/{service}',            [BranchController::class, 'deleteBranchService']);

	// Branch-scoped routes
	Route::middleware('branch')->group(function () {
		// Customers
		Route::apiResource('customers', CustomerController::class);

		// Orders
		Route::apiResource('orders', OrderController::class);
		Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus']);

		// Loads
		Route::patch('loads/{load}/status', [LoadController::class, 'updateStatus']);

		// Expenses
		Route::apiResource('expenses', ExpenseController::class)->except(['show']);

		// Settings (global with branch override)
		Route::get('settings', [SettingController::class, 'index']);
		Route::put('settings/{key}', [SettingController::class, 'update']);

		// Reports
		Route::prefix('reports')->group(function () {
			Route::get('branches',      [ReportsController::class, 'branchComparison']);
			Route::get('sales-summary', [ReportsController::class, 'salesSummary']);
			Route::get('revenue',       [ReportsController::class, 'revenue']);
			Route::get('top-customers', [ReportsController::class, 'topCustomers']);
			Route::get('services',      [ReportsController::class, 'services']);
			Route::get('profit-loss',   [ReportsController::class, 'profitLoss']);
		});

		// Payments
		Route::get('orders/{order}/payments', [PaymentController::class, 'index']);
		Route::post('orders/{order}/payments', [PaymentController::class, 'store']);
	});
});
