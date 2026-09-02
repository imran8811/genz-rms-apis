<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StaffFine;
use App\Services\LateFineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffFineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StaffFine::with('staff:id,name,role')->latest('fine_date')->latest('id');

        if ($request->has('staff_id')) {
            $query->where('staff_id', $request->staff_id);
        }
        if ($request->has('source')) {
            $query->where('source', $request->source);
        }
        if ($request->filled('month')) {
            [$year, $mon] = explode('-', $request->month);
            $query->whereYear('fine_date', $year)->whereMonth('fine_date', $mon);
        }
        if ($request->has('from') && $request->has('to')) {
            $query->whereBetween('fine_date', [$request->from, $request->to]);
        }

        return response()->json($query->get());
    }

    /**
     * Defaults for the "Add Fine" form and the rule the attendance screen shows
     * next to a late check-in, so the amount and grace live in one place.
     */
    public function rule(LateFineService $fines): JsonResponse
    {
        $shiftStart = $fines->shiftStartMinutes(null);

        return response()->json([
            'amount'              => $fines->amount(),
            'grace_minutes'       => $fines->graceMinutes(),
            'default_shift_start' => sprintf('%02d:%02d', intdiv($shiftStart, 60), $shiftStart % 60),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'staff_id'  => 'required|exists:staff,id',
            'amount'    => 'required|integer|min:1',
            'fine_date' => 'required|date',
            'reason'    => 'nullable|string|max:300',
            'notes'     => 'nullable|string|max:500',
        ]);

        $fine = StaffFine::create($data + ['source' => 'manual']);
        $fine->load('staff:id,name,role');

        return response()->json($fine, 201);
    }

    /**
     * Editing an automatic fine keeps it as `auto_late` but the next attendance
     * save for that day will overwrite the amount — the honest fix for a wrong
     * fine is to correct the check-in time.
     */
    public function update(Request $request, StaffFine $staffFine): JsonResponse
    {
        $data = $request->validate([
            'staff_id'  => 'sometimes|required|exists:staff,id',
            'amount'    => 'sometimes|required|integer|min:1',
            'fine_date' => 'sometimes|required|date',
            'reason'    => 'nullable|string|max:300',
            'notes'     => 'nullable|string|max:500',
        ]);

        $staffFine->update($data);
        $staffFine->load('staff:id,name,role');

        return response()->json($staffFine);
    }

    public function destroy(StaffFine $staffFine): JsonResponse
    {
        $staffFine->delete();

        return response()->json(null, 204);
    }
}
