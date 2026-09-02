<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\StaffFoodLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffFoodLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StaffFoodLog::with('staff:id,name,role')
            ->latest('consumed_at')
            ->latest('id');

        if ($request->has('staff_id')) {
            $query->where('staff_id', $request->staff_id);
        }
        if ($request->has('month')) {
            [$year, $mon] = explode('-', $request->month);
            $query->whereYear('consumed_at', $year)->whereMonth('consumed_at', $mon);
        }

        return response()->json($query->paginate(100));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'staff_id'    => 'required|exists:staff,id',
            'item_name'   => 'required|string|max:200',
            'quantity'    => 'required|integer|min:1',
            'unit_price'  => 'required|integer|min:1',
            'consumed_at' => 'required|date',
            'notes'       => 'nullable|string|max:500',
            'added_by'    => 'nullable|string|max:100',
        ]);

        $data['total_amount'] = $data['quantity'] * $data['unit_price'];

        $log = StaffFoodLog::create($data);
        $log->load('staff:id,name,role');

        return response()->json($log, 201);
    }

    /**
     * Delete a food entry. Admins only — anyone may log staff food, but once an
     * entry exists only an admin can take it back off the record.
     */
    public function destroy(Request $request, StaffFoodLog $staffFoodLog): JsonResponse
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json(['message' => 'Only an admin can delete a staff food entry.'], 403);
        }

        $staffFoodLog->delete();

        return response()->json(null, 204);
    }

    public function summary(Request $request): JsonResponse
    {
        $month = $request->get('month', now()->format('Y-m'));
        [$year, $mon] = explode('-', $month);

        $total = StaffFoodLog::whereYear('consumed_at', $year)
            ->whereMonth('consumed_at', $mon)
            ->sum('total_amount');

        $staffIds = StaffFoodLog::whereYear('consumed_at', $year)
            ->whereMonth('consumed_at', $mon)
            ->distinct()
            ->pluck('staff_id');

        $byStaff = Staff::whereIn('id', $staffIds)
            ->get(['id', 'name', 'role', 'salary'])
            ->map(function (Staff $staff) use ($year, $mon) {
                $entries = StaffFoodLog::where('staff_id', $staff->id)
                    ->whereYear('consumed_at', $year)
                    ->whereMonth('consumed_at', $mon)
                    ->orderBy('consumed_at')
                    ->get(['id', 'item_name', 'quantity', 'unit_price', 'total_amount', 'consumed_at', 'notes']);

                return [
                    'staff_id'    => $staff->id,
                    'staff_name'  => $staff->name,
                    'role'        => $staff->role,
                    'salary'      => $staff->salary,
                    'total'       => $entries->sum('total_amount'),
                    'entries'     => $entries,
                ];
            })
            ->sortByDesc('total')
            ->values();

        return response()->json([
            'month'    => $month,
            'total'    => $total,
            'by_staff' => $byStaff,
        ]);
    }
}
