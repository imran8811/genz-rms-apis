<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Staff;
use App\Models\StaffFine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Late-arrival fines.
 *
 * **Nothing here fires by itself.** The service works out who *looks* late and
 * what a late fine would cost, but a fine is only ever written when someone at
 * the counter asks for it — `charge()` from the Add-fine link on the attendance
 * screen, `clear()` from Remove. Marking attendance used to charge these
 * automatically; it no longer touches fines at all, so a save can neither
 * create nor delete one. (Rationale: whether a late arrival is actually worth
 * Rs 200 is a judgement about the person and the day — traffic, an errand, a
 * shift swapped by phone — and the counter has that context, the clock doesn't.)
 *
 * What is still automatic is *detection*: `minutesLate()` drives the "45 min
 * late" the attendance row shows, and the attendance row still flips itself to
 * `late` on a late stamp. That is a statement about the day, not a charge.
 *
 * **The restaurant runs one shift, 1PM–1AM.** Everyone is judged against the
 * same start, so there is no per-person shift arithmetic here: `shiftStart()` is
 * the whole answer. `staff.shift` is a descriptive label on the staff record and
 * is deliberately *not* read by this service — a typo or a stale string on one
 * person's row cannot change what they are judged against.
 *
 * Knobs, all `settings` rows with the defaults below:
 *   late_fine_amount        200           — rupees a late day costs when charged
 *   late_fine_shift_start   13:00         — when the shift starts, for everyone
 *   restaurant_timezone     Asia/Karachi  — the wall clock all of this is judged on
 *
 * ⚠ `late_fine_grace_minutes` (30) is **currently inert**. It used to decide
 * three things, all since removed: whether a save charged a fine automatically,
 * whether a stamp rewrote the status to `late`, and whether the attendance row
 * highlighted the lateness in red. Nothing in the app now consults it —
 * `charge()` charges the full amount whenever it is asked to, and the counter
 * decides what is worth charging. `graceMinutes()` survives only because
 * `GET /staff-fines/rule` still publishes it. Either give it a job again or
 * drop the setting, the getter and the endpoint field together; leaving a
 * configurable number that changes nothing is the worst of the three.
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
     * `now()` would record a 1:31 PM arrival as 08:31 and nobody would ever look
     * late. Everything that compares against a shift time must go through here.
     */
    public function localNow(): Carbon
    {
        return now()->setTimezone((string) Setting::get('restaurant_timezone', self::DEFAULT_TIMEZONE));
    }

    /**
     * When the shift starts, as minutes past midnight — the same for everyone,
     * from the `late_fine_shift_start` setting. One shift, so one answer; this
     * used to parse a per-staff shift string ("5PM–1AM" → 17:00) and no longer
     * does. A setting with no usable time in it falls back to the default
     * rather than returning something that would make nobody ever look late.
     */
    public function shiftStart(): int
    {
        return $this->toMinutes((string) Setting::get('late_fine_shift_start', self::DEFAULT_SHIFT_START))
            ?? (int) $this->toMinutes(self::DEFAULT_SHIFT_START);
    }

    /**
     * Minutes past the shift start, or null when there is no usable check-in
     * time. Negative (early) values are returned as 0 — early is never late.
     */
    public function minutesLate(?string $checkIn): ?int
    {
        $checkInMinutes = $this->toMinutes((string) $checkIn);
        if ($checkInMinutes === null) {
            return null;
        }

        return max(0, $checkInMinutes - $this->shiftStart());
    }

    /**
     * The late fine already standing against one staff member on one date, if
     * there is one. At most one exists per (staff, date): `charge()` re-prices
     * the row it finds rather than adding a second.
     *
     * Matches `fine_date` **exactly** rather than with `whereDate()`. The column
     * is a `date`, so the two agree — but `DATE(fine_date) = ?` wraps the column
     * in a function and throws away the `['staff_id', 'fine_date']` index, which
     * this lookup wants both for speed and so `charge()`'s locking read takes a
     * narrow gap lock instead of a wide one.
     */
    public function lateFine(Staff $staff, string $date): ?StaffFine
    {
        return $this->lateFineQuery($staff, $date)->first();
    }

    private function lateFineQuery(Staff $staff, string $date)
    {
        return StaffFine::where('staff_id', $staff->id)
            ->where('fine_date', $date)
            ->where('source', StaffFine::SOURCE_LATE);
    }

    /**
     * Charge the late fine for one staff member on one date, at the configured
     * amount, with the lateness worked out from `$checkIn`. Called only from an
     * explicit request. Charging a day that already has one re-prices it
     * instead of stacking a second, so the link is safe to press twice.
     *
     * A fine can be charged with no check-in time on record (someone turned up
     * late and the time was never typed): the amount stands, and the reason just
     * says "Late arrival" because there is no arithmetic to quote.
     *
     * ⚠ **The find-then-write runs inside a transaction, holding a lock on the
     * (staff, date) row, and that is load-bearing.** Without it two charges
     * racing — a double click, a retried request, two terminals — both read "no
     * fine yet" and both insert, and the person is fined twice for one late
     * morning. The locking read also takes a gap lock when it matches nothing,
     * so the second request waits and then finds the first one's row to re-price
     * instead of inserting beside it. Idempotency here is not a nicety: this is
     * money against someone's salary, and the link is designed to be pressable
     * twice.
     */
    public function charge(Staff $staff, string $date, ?string $checkIn): StaffFine
    {
        $late = $this->minutesLate($checkIn);

        $attributes = [
            'amount'       => $this->amount(),
            'minutes_late' => $late === null ? null : min(65535, $late),
            'reason'       => $late === null || $late === 0
                ? 'Late arrival'
                : sprintf(
                    'Late by %d min (checked in %s, shift starts %s)',
                    $late,
                    substr((string) $checkIn, 0, 5),
                    $this->formatMinutes($this->shiftStart())
                ),
        ];

        return DB::transaction(function () use ($staff, $date, $attributes) {
            $existing = $this->lateFineQuery($staff, $date)->lockForUpdate()->first();

            if ($existing) {
                $existing->update($attributes);

                return $existing;
            }

            return StaffFine::create($attributes + [
                'staff_id'  => $staff->id,
                'fine_date' => $date,
                'source'    => StaffFine::SOURCE_LATE,
            ]);
        });
    }

    /**
     * Drop the late fine for one staff member on one date. Returns false when
     * there was nothing to drop, so the caller can say so rather than reporting
     * a removal that never happened. Manual fines are never touched.
     */
    public function clear(Staff $staff, string $date): bool
    {
        $fine = $this->lateFine($staff, $date);

        return $fine !== null && $fine->delete() === true;
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
