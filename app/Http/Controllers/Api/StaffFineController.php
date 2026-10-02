<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\StaffAttendance;
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
        $shiftStart = $fines->shiftStart();

        return response()->json([
            'amount'              => $fines->amount(),
            'grace_minutes'       => $fines->graceMinutes(),
            // Named `default_shift_start` from when staff could have their own
            // shift. There is one shift now, so this *is* the shift start.
            'default_shift_start' => sprintf('%02d:%02d', intdiv($shiftStart, 60), $shiftStart % 60),
        ]);
    }

    /**
     * Charge the late fine for one staff member on one day — the Add-fine link
     * on the attendance screen. Nothing charges this by itself any more, so this
     * endpoint is the only way a late fine is ever written.
     *
     * The lateness is taken from the **stored** attendance row, not from the
     * request, so the fine quotes the check-in time that is actually on file and
     * cannot be talked into a different number by a stale tab. That is also why
     * the day has to be saved first: an unsaved sheet has no arrival time to
     * charge against.
     *
     * Pressing it twice re-prices rather than stacking a second fine.
     */
    public function late(Request $request, LateFineService $fines): JsonResponse
    {
        $data = $request->validate([
            'staff_id'  => 'required|exists:staff,id',
            'fine_date' => 'required|date',
        ]);

        if ($fines->amount() <= 0) {
            return response()->json([
                'message' => 'The late-fine amount is set to 0 — set one in Settings before charging late fines.',
            ], 422);
        }

        $staff      = Staff::findOrFail($data['staff_id']);
        $attendance = StaffAttendance::where('staff_id', $staff->id)->where('date', $data['fine_date'])->first();

        if (! $attendance) {
            return response()->json([
                'message' => "No attendance is saved for {$staff->name} on {$data['fine_date']} — save the day first, then charge the fine.",
            ], 422);
        }

        // Someone who never turned up cannot be fined for turning up late. The
        // counter wanting to penalise an absence has the Fines tab for that, and
        // calling it a late fine would put a nonsense reason on the payslip.
        if ($attendance->status === 'absent') {
            return response()->json([
                'message' => "{$staff->name} is marked Absent on {$data['fine_date']} — mark them in before charging a late fine.",
            ], 422);
        }

        $fine = $fines->charge($staff, $data['fine_date'], $attendance->check_in_time);
        $fine->load('staff:id,name,role');

        return response()->json($fine, 201);
    }

    /**
     * Take the late fine for one staff member on one day back off — the Remove
     * link beside a charged fine. Keyed by (staff, date) rather than by fine id
     * so the attendance screen can call it from the row it is already showing.
     *
     * `404` when there is nothing charged, so pressing Remove on a row another
     * terminal has already cleared says so instead of reporting a removal that
     * did not happen. Manual fines are never touched — those are the Fines tab's.
     */
    public function clearLate(Request $request, LateFineService $fines): JsonResponse
    {
        $data = $request->validate([
            'staff_id'  => 'required|exists:staff,id',
            'fine_date' => 'required|date',
        ]);

        $staff = Staff::findOrFail($data['staff_id']);

        if (! $fines->clear($staff, $data['fine_date'])) {
            return response()->json([
                'message' => "No late fine is charged to {$staff->name} on {$data['fine_date']}.",
            ], 404);
        }

        return response()->json(null, 204);
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
     * Editing keeps whatever `source` the fine already had. A late fine edited
     * here now stays edited — attendance saves no longer rewrite it — but
     * pressing Add fine again on that day re-prices it from the rule, so an
     * amount typed here is only final until someone does that.
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
