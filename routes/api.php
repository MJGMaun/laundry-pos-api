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

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
	// Auth
	Route::post('/logout', [AuthController::class, 'logout']);
	Route::get('/user', function (Request $request) {
		return $request->user();
	});

	// Services
	Route::apiResource('services', ServiceController::class);
	Route::patch('services/{service}/toggle', [ServiceController::class, 'toggle']);

	// Customers
	Route::apiResource('customers', CustomerController::class);

	// Orders
	Route::apiResource('orders', OrderController::class);
	Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus']);

	// Loads
	Route::patch('loads/{load}/status', [LoadController::class, 'updateStatus']);

	// Expenses
	Route::apiResource('expenses', ExpenseController::class)->except(['show']);
	Route::apiResource('expense-categories', ExpenseCategoryController::class)->only(['index', 'store', 'destroy']);

	// Reports
	Route::prefix('reports')->group(function () {
		Route::get('sales-summary', [ReportsController::class, 'salesSummary']);
		Route::get('revenue',       [ReportsController::class, 'revenue']);
		Route::get('top-customers', [ReportsController::class, 'topCustomers']);
		Route::get('services',      [ReportsController::class, 'services']);
		Route::get('profit-loss',   [ReportsController::class, 'profitLoss']);
	});

	// Payments
	Route::get('orders/{order}/payments', [PaymentController::class, 'index']);
	Route::post('orders/{order}/payments', [PaymentController::class, 'store']);

	// Route::get('orders', [OrderController::class, 'index']);
	// Route::post('orders', [OrderController::class, 'store']);
	// Route::get('orders/{order}', [OrderController::class, 'show']);
});
