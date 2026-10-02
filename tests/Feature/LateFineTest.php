<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\StaffAttendanceController;
use App\Http\Controllers\Api\StaffFineController;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\StaffFine;
use App\Services\LateFineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Late fines: **detected automatically, charged by hand.**
 *
 * The rule works out that someone is 45 minutes late and what that would cost
 * (Rs 200 past 30 minutes' grace). No fine is written until someone presses Add
 * fine. The first group of tests below pins that saving attendance charges
 * nothing — that is the behaviour this module used to have, and must not drift
 * back into.
 *
 * Saving also no longer touches the **status**: there is no `late` status, and a
 * row marked `present` stays `present` however late the stamp is. The arrival
 * time is the record of lateness.
 */
class LateFineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The restaurant's wall clock. The app runs on UTC, so every "1:31pm" below
     * is stated in this zone — exactly as the counter would read it.
     */
    private const TZ = LateFineService::DEFAULT_TIMEZONE;

    private LateFineService $fines;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fines = new LateFineService;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** sqlite keeps "13:47" verbatim where MySQL stores "13:47:00" — compare the clock, not the format. */
    private function assertCheckIn(?string $expected, Staff $staff): void
    {
        $stored = StaffAttendance::where('staff_id', $staff->id)->value('check_in_time');

        $this->assertSame($expected, $stored === null ? null : substr($stored, 0, 5));
    }

    private function assertNotFined(Staff $staff): void
    {
        $this->assertSame(0, StaffFine::where('staff_id', $staff->id)->count());
    }

    /** The save the attendance screen performs. */
    private function bulkStore(string $date, array $records): void
    {
        $request = Request::create('/api/staff-attendance/bulk', 'POST');
        $request->merge(['date' => $date, 'records' => $records]);

        app(StaffAttendanceController::class)->bulkStore($request, $this->fines);
    }

    /** The Add-fine link: POST /staff-fines/late. */
    private function addFine(Staff $staff, string $date): JsonResponse
    {
        $request = Request::create('/api/staff-fines/late', 'POST');
        $request->merge(['staff_id' => $staff->id, 'fine_date' => $date]);

        return app(StaffFineController::class)->late($request, $this->fines);
    }

    /** The Remove link: DELETE /staff-fines/late. */
    private function removeFine(Staff $staff, string $date): JsonResponse
    {
        $request = Request::create('/api/staff-fines/late', 'DELETE');
        $request->merge(['staff_id' => $staff->id, 'fine_date' => $date]);

        return app(StaffFineController::class)->clearLate($request, $this->fines);
    }

    /**
     * One shift, 1PM–1AM, for everyone. `shift` is a label on the staff record
     * and is **not** read by the fine rule — the tests below set it to something
     * else on purpose to prove that.
     */
    private function staff(string $shift = '1PM–1AM'): Staff
    {
        return Staff::create([
            'name' => 'Bilal', 'role' => 'Cashier', 'shift' => $shift,
            'salary' => 30000, 'is_active' => true,
        ]);
    }

    // ── Saving attendance charges nothing ────────────────────────────────────

    /**
     * The headline rule. Someone marked in two hours late stays `present` — the
     * 15:00 stamp is the record of it — and the sheet costs them nothing until
     * it is charged.
     */
    public function test_saving_attendance_charges_no_fine(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 15:00:00', self::TZ));
        $staff = $this->staff();

        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => null],
        ]);

        $this->assertCheckIn('15:00', $staff);
        $this->assertSame('present', StaffAttendance::where('staff_id', $staff->id)->value('status'));
        $this->assertNotFined($staff);
    }

    /** Re-saving an already-charged day leaves the fine exactly as charged. */
    public function test_saving_attendance_neither_reprices_nor_removes_a_charged_fine(): void
    {
        $staff = $this->staff();
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '15:00'],
        ]);
        $this->addFine($staff, '2026-08-15');

        // Correct the time back inside the grace period and save again. Under
        // the old rule this deleted the fine; the charge is now the counter's,
        // and only the counter takes it back off.
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '13:05'],
        ]);

        $this->assertSame(1, StaffFine::where('staff_id', $staff->id)->count());
        $this->assertSame(120, StaffFine::where('staff_id', $staff->id)->value('minutes_late'));
    }

    /** The response still carries a `fines` key, always empty, for older tabs. */
    public function test_the_save_response_reports_no_fines(): void
    {
        $staff   = $this->staff();
        $request = Request::create('/api/staff-attendance/bulk', 'POST');
        $request->merge(['date' => '2026-08-15', 'records' => [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '15:00'],
        ]]);

        $response = app(StaffAttendanceController::class)->bulkStore($request, $this->fines);
        $body     = json_decode($response->getContent(), true);

        $this->assertSame([], $body['fines']);
        $this->assertCount(1, $body['records']);
    }

    // ── Detection: who looks late, and by how much ───────────────────────────

    public function test_the_shift_starts_at_1pm_and_1331_is_the_first_late_minute(): void
    {
        // 30 minutes' grace: 13:30 is inside it, 13:31 is the first fineable minute.
        $this->assertSame(13 * 60, $this->fines->shiftStart());
        $this->assertSame(30, $this->fines->minutesLate('13:30'));
        $this->assertSame(31, $this->fines->minutesLate('13:31'));
        $this->assertSame(30, $this->fines->graceMinutes());
    }

    /**
     * There is one shift. Whatever `staff.shift` happens to say — a stale
     * `5PM–1AM`, a `Morning`, nothing at all — everyone is judged against the
     * same start, so a label on one person's row cannot buy them four hours.
     */
    public function test_the_shift_start_ignores_whatever_the_staff_row_says(): void
    {
        foreach (['1PM–1AM', '5PM–1AM', 'Morning', 'Full Day', ''] as $label) {
            $staff = $this->staff($label);
            $this->bulkStore('2026-08-15', [
                ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '13:45'],
            ]);

            $this->addFine($staff, '2026-08-15');

            $this->assertSame(
                45,
                StaffFine::where('staff_id', $staff->id)->value('minutes_late'),
                "shift label {$label} should not change the clock",
            );
        }
    }

    public function test_the_shift_start_is_a_setting(): void
    {
        Setting::set('late_fine_shift_start', '09:00');

        $this->assertSame(9 * 60, $this->fines->shiftStart());
        $this->assertSame(45, $this->fines->minutesLate('09:45'));
        $this->assertSame(0, $this->fines->minutesLate('08:30'));
    }

    public function test_early_is_never_late(): void
    {
        $this->assertSame(0, $this->fines->minutesLate('12:00'));
        $this->assertNull($this->fines->minutesLate(null));
    }

    public function test_the_amount_and_grace_are_settings(): void
    {
        Setting::set('late_fine_amount', 350);
        Setting::set('late_fine_grace_minutes', 15);
        $staff = $this->staff();
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '13:20'],
        ]);

        $this->addFine($staff, '2026-08-15');

        $this->assertSame(350, StaffFine::where('staff_id', $staff->id)->value('amount'));
        $this->assertSame(15, $this->fines->graceMinutes());
    }

    // ── Charging one by hand ─────────────────────────────────────────────────

    public function test_adding_a_fine_charges_the_rule_amount_and_the_stored_lateness(): void
    {
        $staff = $this->staff();
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '13:31'],
        ]);

        $response = $this->addFine($staff, '2026-08-15');
        $fine     = StaffFine::where('staff_id', $staff->id)->first();

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(200, $fine->amount);
        $this->assertSame(31, $fine->minutes_late);
        $this->assertSame(StaffFine::SOURCE_LATE, $fine->source);
        $this->assertStringContainsString('Late by 31 min', $fine->reason);
        $this->assertStringContainsString('shift starts 13:00', $fine->reason);
    }

    /**
     * `staff_id` must come back **typed as an integer**, not as whatever the
     * database driver felt like. The attendance screen matches a fine to its row
     * with `f.staff_id === s.id`; a string fails that match silently, so someone
     * already fined still gets offered an "Add fine" link and a second press
     * charges them twice. This is a one-line cast guarding a money bug.
     */
    public function test_a_fine_serialises_its_staff_id_as_an_integer(): void
    {
        $staff = $this->staff();
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '15:00'],
        ]);

        $response = $this->addFine($staff, '2026-08-15');
        $body     = json_decode($response->getContent(), true);

        $this->assertIsInt($body['staff_id']);
        $this->assertSame($staff->id, $body['staff_id']);
    }

    /**
     * The lateness is read off the saved attendance row, so a stale tab cannot
     * talk the fine into a different number by sending its own time.
     */
    public function test_the_fine_quotes_the_stored_check_in_not_the_request(): void
    {
        $staff = $this->staff();
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '14:00'],
        ]);

        $request = Request::create('/api/staff-fines/late', 'POST');
        $request->merge(['staff_id' => $staff->id, 'fine_date' => '2026-08-15', 'check_in_time' => '13:01']);
        app(StaffFineController::class)->late($request, $this->fines);

        $this->assertSame(60, StaffFine::where('staff_id', $staff->id)->value('minutes_late'));
    }

    /**
     * Pressing Add fine twice on the same person and day must leave **one**
     * fine, at one amount. This is money against a salary, so the duplicate is
     * not a cosmetic bug — it is charging someone 400 for one late morning.
     */
    public function test_charging_the_same_person_and_day_twice_never_doubles(): void
    {
        $staff = $this->staff();
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '15:00'],
        ]);

        $this->addFine($staff, '2026-08-15');
        $this->addFine($staff, '2026-08-15');
        $this->addFine($staff, '2026-08-15');

        $this->assertSame(1, StaffFine::where('staff_id', $staff->id)->count());
        $this->assertSame(200, (int) StaffFine::where('staff_id', $staff->id)->sum('amount'));
    }

    /** The link is safe to press twice — and re-prices if the time was corrected. */
    public function test_adding_twice_reprices_rather_than_stacks(): void
    {
        $staff = $this->staff();
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '15:00'],
        ]);
        $this->addFine($staff, '2026-08-15');

        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '16:00'],
        ]);
        $this->addFine($staff, '2026-08-15');

        $this->assertSame(1, StaffFine::where('staff_id', $staff->id)->count());
        $this->assertSame(180, StaffFine::where('staff_id', $staff->id)->value('minutes_late'));
    }

    /**
     * Someone on the sheet with no arrival time recorded can still be fined —
     * they turned up late and nobody typed it — but there is no arithmetic to
     * quote, so the reason says only that.
     */
    public function test_a_fine_with_no_check_in_time_on_record_says_just_late_arrival(): void
    {
        $staff = $this->staff();
        StaffAttendance::insert([
            'staff_id' => $staff->id, 'date' => '2026-08-15', 'status' => 'present', 'check_in_time' => null,
        ]);

        $this->addFine($staff, '2026-08-15');
        $fine = StaffFine::where('staff_id', $staff->id)->first();

        $this->assertSame(200, $fine->amount);
        $this->assertNull($fine->minutes_late);
        $this->assertSame('Late arrival', $fine->reason);
    }

    public function test_an_absent_staff_member_cannot_be_fined_for_being_late(): void
    {
        $staff = $this->staff();
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'absent', 'check_in_time' => null],
        ]);

        $response = $this->addFine($staff, '2026-08-15');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotFined($staff);
    }

    public function test_a_day_with_no_attendance_saved_cannot_be_fined(): void
    {
        $staff = $this->staff();

        $response = $this->addFine($staff, '2026-08-15');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotFined($staff);
    }

    /** A zero amount is a misconfiguration, not a free fine. */
    public function test_a_zero_amount_setting_refuses_the_charge(): void
    {
        Setting::set('late_fine_amount', 0);
        $staff = $this->staff();
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '15:00'],
        ]);

        $response = $this->addFine($staff, '2026-08-15');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotFined($staff);
    }

    // ── Taking one back off ──────────────────────────────────────────────────

    public function test_removing_a_fine_deletes_it(): void
    {
        $staff = $this->staff();
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '15:00'],
        ]);
        $this->addFine($staff, '2026-08-15');

        $response = $this->removeFine($staff, '2026-08-15');

        $this->assertSame(204, $response->getStatusCode());
        $this->assertNotFined($staff);
    }

    /** Pressing Remove on a row another terminal already cleared says so. */
    public function test_removing_a_fine_that_is_not_there_is_a_404(): void
    {
        $staff = $this->staff();

        $this->assertSame(404, $this->removeFine($staff, '2026-08-15')->getStatusCode());
    }

    public function test_removing_a_late_fine_leaves_a_manual_fine_alone(): void
    {
        $staff = $this->staff();
        StaffFine::create([
            'staff_id' => $staff->id, 'amount' => 500, 'fine_date' => '2026-08-15',
            'source' => StaffFine::SOURCE_MANUAL, 'reason' => 'Broke a plate',
        ]);
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '15:00'],
        ]);
        $this->addFine($staff, '2026-08-15');
        $this->assertSame(2, StaffFine::where('staff_id', $staff->id)->count());

        $this->removeFine($staff, '2026-08-15');

        $remaining = StaffFine::where('staff_id', $staff->id)->get();
        $this->assertCount(1, $remaining);
        $this->assertSame(StaffFine::SOURCE_MANUAL, $remaining->first()->source);
    }

    /** Charging a late fine doesn't disturb a manual one on the same day. */
    public function test_a_manual_fine_and_a_late_fine_coexist_on_one_day(): void
    {
        $staff = $this->staff();
        StaffFine::create([
            'staff_id' => $staff->id, 'amount' => 500, 'fine_date' => '2026-08-15',
            'source' => StaffFine::SOURCE_MANUAL, 'reason' => 'Broke a plate',
        ]);
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '15:00'],
        ]);

        $this->addFine($staff, '2026-08-15');

        $this->assertSame(700, (int) StaffFine::where('staff_id', $staff->id)->sum('amount'));
    }

    // ── Stamping: marking someone in *is* the check-in (unchanged) ───────────

    /**
     * The counter marks each person as they walk in, so marking *is* the
     * check-in — a save with no time typed is stamped with the moment of
     * marking. The status is left exactly as it was set.
     */
    public function test_marking_someone_in_stamps_the_time_of_marking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 13:47:00', self::TZ));
        $staff = $this->staff();

        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => null],
        ]);

        $this->assertCheckIn('13:47', $staff);
        $this->assertSame('present', StaffAttendance::where('staff_id', $staff->id)->value('status'));
        $this->assertNotFined($staff);
    }

    public function test_a_half_day_keeps_its_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 13:47:00', self::TZ));
        $staff = $this->staff();

        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'half_day', 'check_in_time' => null],
        ]);

        $this->assertSame('half_day', StaffAttendance::where('staff_id', $staff->id)->value('status'));
    }

    /**
     * However late the stamp is, `present` stays `present`. A stamp past the
     * grace period used to rewrite the status to `late`; there is no such status
     * now, and the arrival time says everything it said.
     */
    public function test_the_status_is_never_rewritten_however_late_the_stamp(): void
    {
        foreach (['13:20' => '13:20', '19:30' => '19:30'] as $clock => $expected) {
            Carbon::setTestNow(Carbon::parse("2026-08-15 {$clock}:00", self::TZ));
            $staff = $this->staff();

            $this->bulkStore('2026-08-15', [
                ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => null],
            ]);

            $this->assertCheckIn($expected, $staff);
            $this->assertSame('present', StaffAttendance::where('staff_id', $staff->id)->value('status'));
        }
    }

    /** `late` is not a status the API will accept any more. */
    public function test_a_late_status_is_rejected(): void
    {
        $staff = $this->staff();

        $this->expectException(ValidationException::class);

        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'late', 'check_in_time' => '15:00'],
        ]);
    }

    public function test_an_absent_row_is_not_stamped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 13:47:00', self::TZ));
        $staff = $this->staff();

        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'absent', 'check_in_time' => null],
        ]);

        $this->assertCheckIn(null, $staff);
    }

    /** Back-filling last week must not borrow today's clock. */
    public function test_a_past_day_is_not_stamped_with_todays_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 13:47:00', self::TZ));
        $staff = $this->staff();

        $this->bulkStore('2026-08-10', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => null],
        ]);

        $this->assertCheckIn(null, $staff);
    }

    /** A typed time is a correction and beats the stamp. */
    public function test_a_typed_time_wins_over_the_stamp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 16:00:00', self::TZ));
        $staff = $this->staff();

        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '13:10'],
        ]);

        $this->assertCheckIn('13:10', $staff);
    }

    /**
     * The evening re-save. Someone already on the sheet as present — with no
     * time, as every row saved before this feature existed — must not be
     * stamped just because Save was pressed again at 17:45.
     */
    public function test_re_saving_does_not_stamp_someone_already_marked_in(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 13:00:00', self::TZ));
        $staff = $this->staff();
        // Straight insert (no date cast) so the row looks exactly like one saved
        // before check-in times existed: present, no time.
        StaffAttendance::insert([
            'staff_id' => $staff->id, 'date' => '2026-08-15', 'status' => 'present', 'check_in_time' => null,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-15 17:45:00', self::TZ));
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => null],
        ]);

        $this->assertCheckIn(null, $staff);
        $this->assertSame('present', StaffAttendance::where('staff_id', $staff->id)->value('status'));
    }

    /** Turning up late in the evening is still a late arrival. */
    public function test_marking_an_absent_person_in_later_stamps_that_moment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 13:00:00', self::TZ));
        $staff = $this->staff();
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'absent', 'check_in_time' => null],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-15 17:45:00', self::TZ));
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => null],
        ]);

        $this->assertCheckIn('17:45', $staff);
        $this->assertSame('present', StaffAttendance::where('staff_id', $staff->id)->value('status'));
    }

    /** A stored time must survive a save that doesn't echo it back. */
    public function test_a_stored_time_is_not_wiped_by_a_save_that_omits_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 13:47:00', self::TZ));
        $staff = $this->staff();
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => null],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-15 21:00:00', self::TZ));
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'half_day', 'check_in_time' => null],
        ]);

        $this->assertCheckIn('13:47', $staff);
    }

    /** Re-saving the day later must not re-stamp everyone with the later clock. */
    public function test_a_stamped_time_survives_a_later_save(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 13:20:00', self::TZ));
        $staff = $this->staff();
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => null],
        ]);

        // The screen reloads the stored time and sends it back on the next save.
        Carbon::setTestNow(Carbon::parse('2026-08-15 20:00:00', self::TZ));
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '13:20'],
        ]);

        $this->assertCheckIn('13:20', $staff);
    }
}
