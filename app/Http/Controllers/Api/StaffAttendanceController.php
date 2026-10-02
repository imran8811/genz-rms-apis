<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Services\LateFineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffAttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StaffAttendance::with('staff:id,name,role');

        if ($request->has('date')) {
            $query->where('date', $request->date);
        }
        if ($request->has('from') && $request->has('to')) {
            $query->whereBetween('date', [$request->from, $request->to]);
        }
        if ($request->has('month')) {
            [$year, $mon] = explode('-', $request->month);
            $query->whereYear('date', $year)->whereMonth('date', $mon);
        }
        if ($request->has('staff_id')) {
            $query->where('staff_id', $request->staff_id);
        }

        return response()->json($query->orderBy('date', 'desc')->orderBy('staff_id')->get());
    }

    public function bulkStore(Request $request, LateFineService $fines): JsonResponse
    {
        $request->validate([
            'date'                        => 'required|date',
            'records'                     => 'required|array|min:1',
            'records.*.staff_id'          => 'required|exists:staff,id',
            'records.*.status'            => 'required|in:present,absent,half_day',
            'records.*.check_in_time'     => 'nullable|date_format:H:i,H:i:s',
        ]);

        $date = $request->date;
        $now  = now();

        // Marking someone in *is* the check-in: when no time was typed, the
        // moment they are marked in is their arrival time, and lateness is
        // judged on that. Read off the restaurant's wall clock, never the app's
        // UTC `now()`. Back-filling an older day never stamps (it must not
        // borrow today's clock), and a typed time always wins — that is how a
        // wrong stamp gets corrected.
        $localNow = $fines->localNow();
        $isToday  = $date === $localNow->toDateString();
        $staffIds = array_column($request->records, 'staff_id');

        // What this day already looks like. The stamp records the moment someone
        // is *marked in* — not the moment Save is pressed — so anyone already on
        // the sheet as present keeps whatever time they have (usually none, for
        // rows predating this feature) instead of being re-stamped, and re-saving
        // an evening correction can't show the whole roster as arriving at 17:45.
        $existing = StaffAttendance::where('date', $date)->whereIn('staff_id', $staffIds)
            ->get()->keyBy('staff_id');

        // The status is taken exactly as the counter set it. There is **no
        // `late` status any more**: a stamp past the grace period used to rewrite
        // `present` to `late`, which only ever restated what `check_in_time`
        // already says, and left payroll and the summary having to add the two
        // buckets back together. Who was late is read off the arrival time.
        $records = array_map(function ($rec) use ($existing, $isToday, $localNow) {
            $typed = ($rec['check_in_time'] ?? null) ?: null;
            $prev  = $existing->get($rec['staff_id']);

            if ($rec['status'] === 'absent') {
                $stamp = null;                      // never turned up: no arrival time
            } elseif ($typed) {
                $stamp = $typed;                    // a typed time is a correction, and wins
            } elseif ($prev && $prev->status !== 'absent') {
                $stamp = $prev->check_in_time;      // already marked in earlier — leave it be
            } elseif ($isToday) {
                $stamp = $localNow->format('H:i');  // being marked in right now
            } else {
                $stamp = null;                      // back-filling: type the time in
            }

            return [...$rec, 'check_in_time' => $stamp];
        }, $request->records);

        $rows = array_map(fn ($rec) => [
            'staff_id'      => $rec['staff_id'],
            'date'          => $date,
            'status'        => $rec['status'],
            'check_in_time' => $rec['check_in_time'],
            'created_at'    => $now,
            'updated_at'    => $now,
        ], $records);

        StaffAttendance::upsert(
            $rows,
            ['staff_id', 'date'],
            ['status', 'check_in_time', 'updated_at']
        );

        // **Saving attendance charges nothing.** It used to write a late fine for
        // everyone past the grace period (and delete the fine again when a time
        // was corrected downward). Fines are now charged one at a time from the
        // attendance screen's Add-fine link — `POST /staff-fines/late` — because
        // whether a late arrival is actually worth Rs 200 is a judgement the
        // counter makes, not one the clock makes. A save therefore neither
        // creates nor removes a fine, and `fines` is **always empty**: it is kept
        // on the response so a frontend that predates this change keeps working
        // (it reported what the save had charged) rather than erroring on a
        // missing key.
        return response()->json([
            'records' => StaffAttendance::where('date', $date)->whereIn('staff_id', $staffIds)->get(),
            'fines'   => [],
        ], 201);
    }

    public function summary(Request $request): JsonResponse
    {
        $month = $request->get('month', now()->format('Y-m'));
        [$year, $mon] = explode('-', $month);

        // Include active staff plus anyone (incl. since-deactivated staff) who
        // has attendance records that month, so history stays complete.
        $recordIds = StaffAttendance::whereYear('date', $year)->whereMonth('date', $mon)
            ->distinct()->pluck('staff_id');
        $activeIds = Staff::where('is_active', true)->pluck('id');

        $allStaff = Staff::whereIn('id', $activeIds->merge($recordIds)->unique())
            ->orderBy('name')->get(['id', 'name', 'role', 'salary']);

        $data = $allStaff->map(function (Staff $staff) use ($year, $mon) {
            $records = StaffAttendance::where('staff_id', $staff->id)
                ->whereYear('date', $year)
                ->whereMonth('date', $mon)
                ->get(['status']);

            return [
                'staff_id'    => $staff->id,
                'staff_name'  => $staff->name,
                'role'        => $staff->role,
                // `present` no longer has to union in `late`, and the `late`
                // count is gone with the status — nothing consumed it.
                'present'     => $records->where('status', 'present')->count(),
                'absent'      => $records->where('status', 'absent')->count(),
                'half_day'    => $records->where('status', 'half_day')->count(),
                'total_logged'=> $records->count(),
            ];
        });

        return response()->json(['month' => $month, 'data' => $data]);
    }
}
