<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Staff;
use App\Models\StaffFine;
use Carbon\Carbon;

/**
 * Late-arrival fines.
 *
 * Marking attendance is the only trigger: when a check-in time is saved that is
 * more than the grace period past the staff member's shift start, a fine is
 * written against that staff/date. Nothing else creates one, and nothing needs
 * to be run afterwards.
 *
 * The rule is deliberately re-runnable. `sync()` owns the `auto_late` row for a
 * (staff, date) pair completely: fixing a mis-typed check-in time re-prices the
 * fine, and clearing it (or marking the person absent) removes the fine, so
 * re-saving attendance can never stack duplicates on someone.
 *
 * Four knobs, all `settings` rows with the defaults below:
 *   late_fine_amount        200           — rupees per late day
 *   late_fine_grace_minutes 30            — minutes of lateness allowed for free
 *   late_fine_shift_start   13:00         — fallback shift start (staff.shift wins)
 *   restaurant_timezone     Asia/Karachi  — the wall clock all of this is judged on
 * With the defaults, a 1PM shift is fined from 13:31 onward.
 */
class LateFineService
{
    public const DEFAULT_AMOUNT = 200;

    public const DEFAULT_GRACE = 30;

    public const DEFAULT_SHIFT_START = '13:00';

    public const DEFAULT_TIMEZONE = 'Asia/Karachi';

    public function amount(): int
    {
        return max(0, (int) Setting::get('late_fine_amount', self::DEFAULT_AMOUNT));
    }

    public function graceMinutes(): int
    {
        return max(0, (int) Setting::get('late_fine_grace_minutes', self::DEFAULT_GRACE));
    }

    /**
     * The restaurant's wall clock. **The app itself runs on UTC** (`config/app.php`),
     * so `now()` is five hours behind the counter in Karachi — stamping a raw
     * `now()` would record a 2:31 PM arrival as 09:31 and no one would ever be
     * late. Everything that compares against a shift time must go through here.
     */
    public function localNow(): Carbon
    {
        return now()->setTimezone((string) Setting::get('restaurant_timezone', self::DEFAULT_TIMEZONE));
    }

    /**
     * Shift start as minutes past midnight. Read off the staff member's own
     * shift ("1PM–1AM" → 13:00, "5PM–1AM" → 17:00) so a 5PM starter isn't fined
     * for a 1PM clock; shifts without a time in them ("Full Day", "Morning") and
     * staff with no shift set fall back to the `late_fine_shift_start` setting.
     */
    public function shiftStartMinutes(?string $shift): int
    {
        if ($shift && preg_match('/(\d{1,2})(?::(\d{2}))?\s*(am|pm)/i', $shift, $m)) {
            $hour   = (int) $m[1] % 12;
            $minute = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
            if (strtolower($m[3]) === 'pm') {
                $hour += 12;
            }

            return $hour * 60 + $minute;
        }

        return $this->toMinutes((string) Setting::get('late_fine_shift_start', self::DEFAULT_SHIFT_START))
            ?? (int) $this->toMinutes(self::DEFAULT_SHIFT_START);
    }

    /**
     * Minutes past the shift start, or null when there is no usable check-in
     * time. Negative (early) values are returned as 0 — early is never late.
     */
    public function minutesLate(?string $shift, ?string $checkIn): ?int
    {
        $checkInMinutes = $this->toMinutes((string) $checkIn);
        if ($checkInMinutes === null) {
            return null;
        }

        return max(0, $checkInMinutes - $this->shiftStartMinutes($shift));
    }

    /**
     * Bring the automatic fine for one staff member on one date in line with the
     * attendance just saved. Returns the fine if there is one, null if there
     * isn't (having removed a stale one first, if it existed).
     */
    public function sync(Staff $staff, string $date, ?string $checkIn, string $status): ?StaffFine
    {
        $existing = StaffFine::where('staff_id', $staff->id)
            ->whereDate('fine_date', $date)
            ->where('source', 'auto_late')
            ->first();

        // Someone who never turned up can't be fined for turning up late.
        $late = $status === 'absent' ? null : $this->minutesLate($staff->shift, $checkIn);

        if ($late === null || $late <= $this->graceMinutes() || $this->amount() <= 0) {
            $existing?->delete();

            return null;
        }

        $attributes = [
            'amount'       => $this->amount(),
            'minutes_late' => min(65535, $late),
            'reason'       => sprintf(
                'Late by %d min (checked in %s, shift starts %s)',
                $late,
                substr((string) $checkIn, 0, 5),
                $this->formatMinutes($this->shiftStartMinutes($staff->shift))
            ),
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return StaffFine::create($attributes + [
            'staff_id'  => $staff->id,
            'fine_date' => $date,
            'source'    => 'auto_late',
        ]);
    }

    private function toMinutes(?string $time): ?int
    {
        if (! $time || ! preg_match('/^(\d{1,2}):(\d{2})/', trim($time), $m)) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    private function formatMinutes(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }
}
