<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use App\Models\ServiceCategory;

class ServiceCategoryController extends Controller implements HasMiddleware
{
    public function index()
    {
        return response()->json(ServiceCategory::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'      => 'required|string|max:255|unique:service_categories,name',
            'icon'      => 'nullable|string|max:10',
            'load_rule' => 'nullable|in:quantity,per_order,none',
        ]);

        $category = ServiceCategory::create($validated);

        return response()->json($category, 201);
    }

    public function update(Request $request, ServiceCategory $serviceCategory)
    {
        $validated = $request->validate([
            'name'      => 'required|string|max:255|unique:service_categories,name,' . $serviceCategory->id,
            'icon'      => 'nullable|string|max:10',
            'load_rule' => 'nullable|in:quantity,per_order,none',
        ]);

        $serviceCategory->update($validated);

        return response()->json($serviceCategory);
    }

    public function destroy(ServiceCategory $serviceCategory)
    {
        $serviceCategory->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public static function middleware(): array
    {
        return [
            new Middleware('role:admin', only: ['store', 'update', 'destroy']),
        ];
    }
}
