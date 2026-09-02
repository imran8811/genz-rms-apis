<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffLeave;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffLeaveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StaffLeave::with('staff:id,name,role')->latest('start_date');

        if ($request->has('staff_id')) {
            $query->where('staff_id', $request->staff_id);
        }
        if ($request->has('month')) {
            [$year, $mon] = explode('-', $request->month);
            $query->whereYear('start_date', $year)->whereMonth('start_date', $mon);
        }

        return response()->json($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'staff_id'   => 'required|exists:staff,id',
            'leave_type' => 'required|in:sick,casual,annual,unpaid',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
            'reason'     => 'nullable|string|max:300',
            'notes'      => 'nullable|string|max:500',
        ]);

        $data['days_count'] = Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) + 1;

        $leave = StaffLeave::create($data);
        $leave->load('staff:id,name,role');

        return response()->json($leave, 201);
    }

    public function destroy(StaffLeave $staffLeave): JsonResponse
    {
        $staffLeave->delete();
        return response()->json(null, 204);
    }
}
