<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\StaffAttendanceController;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\StaffFine;
use App\Services\LateFineService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The late-fine rule: a check-in more than 30 minutes past the shift start
 * costs Rs 200, applied when attendance is saved and re-applied (never
 * duplicated) every time that day is saved again.
 */
class LateFineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The restaurant's wall clock. The app runs on UTC, so every "2:31pm" below
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

    /** sqlite keeps "14:47" verbatim where MySQL stores "14:47:00" — compare the clock, not the format. */
    private function assertCheckIn(?string $expected, Staff $staff): void
    {
        $stored = StaffAttendance::where('staff_id', $staff->id)->value('check_in_time');

        $this->assertSame($expected, $stored === null ? null : substr($stored, 0, 5));
    }

    /** The save the attendance screen performs. */
    private function bulkStore(string $date, array $records): void
    {
        $request = Request::create('/api/staff-attendance/bulk', 'POST');
        $request->merge(['date' => $date, 'records' => $records]);

        app(StaffAttendanceController::class)->bulkStore($request, $this->fines);
    }

    private function staff(string $shift = '2PM–2AM'): Staff
    {
        return Staff::create([
            'name' => 'Bilal', 'role' => 'Cashier', 'shift' => $shift,
            'salary' => 30000, 'is_active' => true,
        ]);
    }

    public function test_a_2pm_shift_is_fined_from_1431(): void
    {
        $staff = $this->staff();

        $this->assertNull($this->fines->sync($staff, '2026-08-15', '14:30', 'present'));

        $fine = $this->fines->sync($staff, '2026-08-15', '14:31', 'present');

        $this->assertNotNull($fine);
        $this->assertSame(200, $fine->amount);
        $this->assertSame(31, $fine->minutes_late);
        $this->assertSame('auto_late', $fine->source);
    }

    public function test_the_shift_start_comes_from_the_staff_member(): void
    {
        // 14:45 is late for a 2PM shift and early for a 5PM one.
        $this->assertNotNull($this->fines->sync($this->staff('2PM–2AM'), '2026-08-15', '14:45', 'present'));
        $this->assertNull($this->fines->sync($this->staff('5PM–2AM'), '2026-08-15', '14:45', 'present'));
    }

    public function test_a_shift_without_a_time_falls_back_to_the_setting(): void
    {
        Setting::set('late_fine_shift_start', '09:00');

        $this->assertNotNull($this->fines->sync($this->staff('Morning'), '2026-08-15', '09:45', 'present'));
        $this->assertNull($this->fines->sync($this->staff('Morning'), '2026-08-15', '09:20', 'present'));
    }

    public function test_saving_the_same_day_twice_reprices_rather_than_stacks(): void
    {
        $staff = $this->staff();

        $this->fines->sync($staff, '2026-08-15', '15:00', 'present');
        $this->fines->sync($staff, '2026-08-15', '16:00', 'present');

        $this->assertSame(1, StaffFine::where('staff_id', $staff->id)->count());
        $this->assertSame(120, StaffFine::where('staff_id', $staff->id)->value('minutes_late'));
    }

    public function test_correcting_the_check_in_time_removes_the_fine(): void
    {
        $staff = $this->staff();

        $this->fines->sync($staff, '2026-08-15', '15:00', 'present');
        $this->fines->sync($staff, '2026-08-15', '14:05', 'present');

        $this->assertSame(0, StaffFine::where('staff_id', $staff->id)->count());
    }

    public function test_an_absent_staff_member_is_not_fined_for_being_late(): void
    {
        $staff = $this->staff();

        $this->fines->sync($staff, '2026-08-15', '15:00', 'present');
        $this->fines->sync($staff, '2026-08-15', '15:00', 'absent');

        $this->assertSame(0, StaffFine::where('staff_id', $staff->id)->count());
    }

    public function test_a_manual_fine_survives_the_attendance_sync(): void
    {
        $staff = $this->staff();
        StaffFine::create([
            'staff_id' => $staff->id, 'amount' => 500, 'fine_date' => '2026-08-15',
            'source' => 'manual', 'reason' => 'Broke a plate',
        ]);

        $this->fines->sync($staff, '2026-08-15', '14:00', 'present');

        $this->assertSame(1, StaffFine::where('staff_id', $staff->id)->count());
    }

    /**
     * The counter marks each person as they walk in, so marking *is* the
     * check-in — a save with no time typed is stamped with the moment of
     * marking, and fined on that.
     */
    public function test_marking_someone_in_stamps_the_time_of_marking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:47:00', self::TZ));
        $staff = $this->staff();

        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => null],
        ]);

        $this->assertCheckIn('14:47', $staff);
        $this->assertSame(200, StaffFine::where('staff_id', $staff->id)->value('amount'));
        // The row itself should read Late, not Present.
        $this->assertSame('late', StaffAttendance::where('staff_id', $staff->id)->value('status'));
    }

    public function test_an_explicit_half_day_is_not_rewritten_to_late(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:47:00', self::TZ));
        $staff = $this->staff();

        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'half_day', 'check_in_time' => null],
        ]);

        $this->assertSame('half_day', StaffAttendance::where('staff_id', $staff->id)->value('status'));
        $this->assertSame(200, StaffFine::where('staff_id', $staff->id)->value('amount'));
    }

    public function test_marking_someone_in_within_the_grace_period_costs_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:20:00', self::TZ));
        $staff = $this->staff();

        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => null],
        ]);

        $this->assertCheckIn('14:20', $staff);
        $this->assertSame(0, StaffFine::where('staff_id', $staff->id)->count());
    }

    public function test_an_absent_row_is_not_stamped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:47:00', self::TZ));
        $staff = $this->staff();

        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'absent', 'check_in_time' => null],
        ]);

        $this->assertCheckIn(null, $staff);
        $this->assertSame(0, StaffFine::where('staff_id', $staff->id)->count());
    }

    /** Back-filling last week must not borrow today's clock. */
    public function test_a_past_day_is_not_stamped_with_todays_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:47:00', self::TZ));
        $staff = $this->staff();

        $this->bulkStore('2026-08-10', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => null],
        ]);

        $this->assertCheckIn(null, $staff);
        $this->assertSame(0, StaffFine::where('staff_id', $staff->id)->count());
    }

    /** A typed time is a correction and beats the stamp. */
    public function test_a_typed_time_wins_over_the_stamp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 16:00:00', self::TZ));
        $staff = $this->staff();

        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '14:10'],
        ]);

        $this->assertCheckIn('14:10', $staff);
        $this->assertSame(0, StaffFine::where('staff_id', $staff->id)->count());
    }

    /**
     * The evening re-save. Someone already on the sheet as present — with no
     * time, as every row saved before this feature existed — must not be
     * stamped (and fined) just because Save was pressed again at 17:45.
     */
    public function test_re_saving_does_not_stamp_someone_already_marked_in(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:00:00', self::TZ));
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
        $this->assertSame(0, StaffFine::where('staff_id', $staff->id)->count());
    }

    /** Turning up late in the evening is still a late arrival. */
    public function test_marking_an_absent_person_in_later_stamps_that_moment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:00:00', self::TZ));
        $staff = $this->staff();
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'absent', 'check_in_time' => null],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-15 17:45:00', self::TZ));
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => null],
        ]);

        $this->assertCheckIn('17:45', $staff);
        $this->assertSame(200, StaffFine::where('staff_id', $staff->id)->value('amount'));
    }

    /** A stored time must survive a save that doesn't echo it back. */
    public function test_a_stored_time_is_not_wiped_by_a_save_that_omits_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:47:00', self::TZ));
        $staff = $this->staff();
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => null],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-15 21:00:00', self::TZ));
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'half_day', 'check_in_time' => null],
        ]);

        $this->assertCheckIn('14:47', $staff);
        $this->assertSame(200, StaffFine::where('staff_id', $staff->id)->value('amount'));
    }

    /** Re-saving the day later must not re-stamp everyone with the later clock. */
    public function test_a_stamped_time_survives_a_later_save(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 14:20:00', self::TZ));
        $staff = $this->staff();
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => null],
        ]);

        // The screen reloads the stored time and sends it back on the next save.
        Carbon::setTestNow(Carbon::parse('2026-08-15 20:00:00', self::TZ));
        $this->bulkStore('2026-08-15', [
            ['staff_id' => $staff->id, 'status' => 'present', 'check_in_time' => '14:20'],
        ]);

        $this->assertCheckIn('14:20', $staff);
        $this->assertSame(0, StaffFine::where('staff_id', $staff->id)->count());
    }

    public function test_the_amount_and_grace_are_settings(): void
    {
        Setting::set('late_fine_amount', 350);
        Setting::set('late_fine_grace_minutes', 15);

        $fine = $this->fines->sync($this->staff(), '2026-08-15', '14:20', 'present');

        $this->assertSame(350, $fine->amount);
    }
}
