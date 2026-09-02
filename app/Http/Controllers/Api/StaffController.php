<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    /**
     * The staff list: everyone on the books, active or not — an inactive member
     * still has to be visible to be edited or brought back. Deleting is what
     * takes someone off it, permanently. `?active=1` narrows it to the working
     * roster, which is what the POS and the attendance/payroll screens want.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Staff::query();

        if ($request->has('role')) {
            $query->where('role', $request->role);
        }
        if ($request->has('active')) {
            $query->where('is_active', true);
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => 'required|string|max:100',
            'role'      => 'required|in:Manager,Chef,Cashier,Rider,Waiter,Helper',
            'phone'     => 'nullable|string|max:20',
            'shift'     => 'nullable|string|max:50',
            'salary'    => 'integer|min:0',
            'join_date' => 'nullable|date',
            'is_active' => 'boolean',
            'notes'     => 'nullable|string',
        ]);

        return response()->json(Staff::create($data), 201);
    }

    public function show(Staff $staff): JsonResponse
    {
        return response()->json($staff);
    }

    public function update(Request $request, Staff $staff): JsonResponse
    {
        $data = $request->validate([
            'name'      => 'string|max:100',
            'role'      => 'in:Manager,Chef,Cashier,Rider,Waiter,Helper',
            'phone'     => 'nullable|string|max:20',
            'shift'     => 'nullable|string|max:50',
            'salary'    => 'integer|min:0',
            'join_date' => 'nullable|date',
            'is_active' => 'boolean',
            'notes'     => 'nullable|string',
        ]);
        $staff->update($data);

        return response()->json($staff);
    }

    /**
     * Permanent. The row goes, and with it every record that hangs off it —
     * attendance, leaves, advances and food logs all `cascadeOnDelete`, so past
     * months' attendance summaries, food reports and payroll change to match.
     * That is the intent: this is for someone gone for good whose records aren't
     * wanted anywhere.
     *
     * It is *not* how you retire someone from the roster — that is
     * `is_active = false`, which keeps them listed, reversible, history intact.
     */
    public function destroy(Staff $staff): JsonResponse
    {
        $staff->delete();

        return response()->json(null, 204);
    }
}
