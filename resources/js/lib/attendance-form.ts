import type { AttendanceValue, DailyAttendanceStaff } from '@/types/attendance';

export type AttendanceValues = Record<number, AttendanceValue>;

const FIRST_ATTENDANCE_OFFSET_MINUTES = 17 * 60;
const LAST_ATTENDANCE_OFFSET_MINUTES = 34 * 60;

export const attendanceTimeOptions = Array.from(
    {
        length:
            (LAST_ATTENDANCE_OFFSET_MINUTES - FIRST_ATTENDANCE_OFFSET_MINUTES) /
                15 +
            1,
    },
    (_, index) => FIRST_ATTENDANCE_OFFSET_MINUTES + index * 15,
);

export const attendanceValuesFromStaffs = (
    staffs: DailyAttendanceStaff[],
): AttendanceValues =>
    Object.fromEntries(
        staffs.map((staff) => [
            staff.staff_id,
            staff.attendance
                ? {
                      clock_in_offset_minutes:
                          staff.attendance.clock_in_offset_minutes,
                      clock_out_offset_minutes:
                          staff.attendance.clock_out_offset_minutes,
                  }
                : {
                      clock_in_offset_minutes: null,
                      clock_out_offset_minutes: null,
                  },
        ]),
    );

export const offsetLabel = (offset: number) => {
    const normalized = offset % (24 * 60);
    const hour = Math.floor(normalized / 60);
    const minute = normalized % 60;

    return `${hour}:${String(minute).padStart(2, '0')}`;
};

export const calculateAttendancePreview = (
    value: AttendanceValue,
    scheduledOffset: number | null,
) => {
    const clockIn = value.clock_in_offset_minutes;
    const clockOut = value.clock_out_offset_minutes;

    if (clockIn === null || clockOut === null || clockOut <= clockIn) {
        return {
            valid: false,
            workingMinutes: 0,
            lateNightMinutes: 0,
            warning: null,
        };
    }

    const lateStart = 22 * 60;
    const lateEnd = 32 * 60;
    const overlap = Math.max(
        0,
        Math.min(clockOut, lateEnd) - Math.max(clockIn, lateStart),
    );

    return {
        valid: true,
        workingMinutes: clockOut - clockIn,
        lateNightMinutes: overlap,
        warning:
            scheduledOffset !== null &&
            Math.abs(clockIn - scheduledOffset) >= 15
                ? `シフト予定${offsetLabel(scheduledOffset)}と実出勤${offsetLabel(clockIn)}が異なります。`
                : null,
    };
};

export const changedAttendanceRecords = (
    initial: AttendanceValues,
    current: AttendanceValues,
) =>
    Object.entries(current)
        .filter(([staffId, value]) => {
            const before = initial[Number(staffId)] ?? {
                clock_in_offset_minutes: null,
                clock_out_offset_minutes: null,
            };

            return (
                before.clock_in_offset_minutes !==
                    value.clock_in_offset_minutes ||
                before.clock_out_offset_minutes !==
                    value.clock_out_offset_minutes
            );
        })
        .map(([staffId, value]) => ({ staff_id: Number(staffId), ...value }));

export const hasUnsavedAttendanceChanges = (
    initial: AttendanceValues,
    current: AttendanceValues,
) => changedAttendanceRecords(initial, current).length > 0;
