<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\StaffAdvance;
use App\Models\StaffAttendance;
use App\Models\StaffFine;
use App\Models\StaffFoodLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffPayrollController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $month       = $request->get('month', now()->format('Y-m'));
        $workingDays = (int) $request->get('working_days', 30);

        [$year, $mon] = explode('-', $month);

        $monthEnd = \Carbon\Carbon::createFromDate($year, $mon, 1)->endOfMonth()->toDateString();

        // Roster for the month = currently-active staff who had joined by then,
        // PLUS anyone (incl. since-deactivated staff) who has records in that
        // month — so past months keep showing them with all their data.
        $activeIds = Staff::where('is_active', true)
            ->where(function ($q) use ($monthEnd) {
                $q->whereNull('join_date')->orWhere('join_date', '<=', $monthEnd);
            })
            ->pluck('id');

        $recordIds = collect()
            ->merge(StaffAttendance::whereYear('date', $year)->whereMonth('date', $mon)->distinct()->pluck('staff_id'))
            ->merge(StaffFoodLog::whereYear('consumed_at', $year)->whereMonth('consumed_at', $mon)->distinct()->pluck('staff_id'))
            ->merge(StaffAdvance::where('repayment_month', $month)->distinct()->pluck('staff_id'))
            ->merge(StaffFine::whereYear('fine_date', $year)->whereMonth('fine_date', $mon)->distinct()->pluck('staff_id'));

        // Active staff first, then the since-deactivated ones, each alphabetical.
        $allStaff = Staff::whereIn('id', $activeIds->merge($recordIds)->unique())
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $payroll = $allStaff->map(function (Staff $staff) use ($year, $mon, $month, $workingDays) {
            $base      = $staff->salary;
            $dailyRate = $workingDays > 0 ? $base / $workingDays : 0;

            // Salary starts from the join date: for a staff who joined mid-month,
            // only count attendance on/after the day they joined — so days before
            // they started don't add to present days (or show as absent).
            $attendanceQuery = StaffAttendance::where('staff_id', $staff->id)
                ->whereYear('date', $year)->whereMonth('date', $mon);

            if ($staff->join_date) {
                $attendanceQuery->whereDate('date', '>=', $staff->join_date->toDateString());
            }

            $attendance = $attendanceQuery->get(['status']);

            $absentDays  = $attendance->where('status', 'absent')->count();
            $halfDays    = $attendance->where('status', 'half_day')->count();

            $absentDeduct   = (int) round($absentDays * $dailyRate);
            $halfDayDeduct  = (int) round($halfDays   * ($dailyRate / 2));

            $foodDeduct = (int) StaffFoodLog::where('staff_id', $staff->id)
                ->whereYear('consumed_at', $year)->whereMonth('consumed_at', $mon)
                ->sum('total_amount');

            $advanceDeduct = (int) StaffAdvance::where('staff_id', $staff->id)
                ->where('repayment_month', $month)
                ->sum('amount');

            // Fines (late arrivals + anything entered by hand) are deducted in
            // the month they fall in — there is no carry-forward as with advances.
            $fineDeduct = (int) StaffFine::where('staff_id', $staff->id)
                ->whereYear('fine_date', $year)->whereMonth('fine_date', $mon)
                ->sum('amount');

            $totalDeductions = $absentDeduct + $halfDayDeduct + $foodDeduct + $advanceDeduct + $fineDeduct;
            $netPayable      = max(0, $base - $totalDeductions);

            return [
                'staff_id'        => $staff->id,
                'staff_name'      => $staff->name,
                'role'            => $staff->role,
                'is_active'       => (bool) $staff->is_active,
                'base_salary'     => $base,
                'daily_rate'      => (int) round($dailyRate),
                // Was `['present', 'late']` — a late arrival was always a present
                // day, and `late` is no longer a status.
                'present_days'    => $attendance->where('status', 'present')->count(),
                'absent_days'     => $absentDays,
                'half_days'       => $halfDays,
                'absent_deduct'   => $absentDeduct,
                'half_day_deduct' => $halfDayDeduct,
                'food_deduct'     => $foodDeduct,
                'advance_deduct'  => $advanceDeduct,
                'fine_deduct'     => $fineDeduct,
                'total_deductions'=> $totalDeductions,
                'net_payable'     => $netPayable,
            ];
        });

        return response()->json([
            'month'             => $month,
            'working_days'      => $workingDays,
            'total_base_salary' => $payroll->sum('base_salary'),
            'total_payable'     => $payroll->sum('net_payable'),
            'staff'             => $payroll->values(),
        ]);
    }
}
