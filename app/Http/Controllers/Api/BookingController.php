<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class BookingController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('role:admin,super_admin'),
        ];
    }

    public function index(Request $request)
    {
        $query = Booking::with('customer')
            ->orderBy('scheduled_at');

        $this->scopeToBranch($query, $request);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date')) {
            $query->whereDate('scheduled_at', $request->date);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('scheduled_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('scheduled_at', '<=', $request->date_to);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id'  => 'required|exists:customers,id',
            'scheduled_at' => 'required|date',
            'address'      => 'nullable|string|max:500',
            'notes'        => 'nullable|string',
        ]);

        $booking = Booking::create([
            'branch_id'    => $this->branchId($request),
            'customer_id'  => $validated['customer_id'],
            'user_id'      => $request->user()->id,
            'scheduled_at' => $validated['scheduled_at'],
            'address'      => $validated['address'] ?? null,
            'notes'        => $validated['notes'] ?? null,
        ]);

        return response()->json($booking->load('customer'), 201);
    }

    public function update(Request $request, Booking $booking)
    {
        $validated = $request->validate([
            'scheduled_at' => 'sometimes|date',
            'address'      => 'sometimes|nullable|string|max:500',
            'notes'        => 'sometimes|nullable|string',
            'status'       => 'sometimes|in:scheduled,cancelled',
        ]);

        $booking->update($validated);

        return response()->json($booking->load('customer'));
    }

    public function destroy(Booking $booking)
    {
        $booking->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Booking cancelled.']);
    }

    public function markPickedUp(Request $request, Booking $booking)
    {
        if ($booking->status !== 'scheduled') {
            return response()->json(['message' => 'Booking is not in scheduled status.'], 422);
        }

        $booking->update([
            'status'       => 'picked_up',
            'picked_up_at' => now(),
        ]);

        return response()->json($booking->load('customer'));
    }
}
