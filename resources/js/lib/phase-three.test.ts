import { describe, expect, it } from 'vite-plus/test';
import {
    calculateAttendancePreview,
    changedAttendanceRecords,
    clockInOffsetFromTimeInput,
    clockOutOffsetFromTimeInput,
    hasUnsavedAttendanceChanges,
    offsetLabel,
} from '@/lib/attendance-form';

describe('Phase 3 attendance form', () => {
    it('ATT-017: displays next-day clock times without a prefix', () => {
        expect(offsetLabel(25 * 60)).toBe('1:00');
        expect(offsetLabel(29 * 60)).toBe('5:00');
    });

    it('ATT-023: maps manually entered times using the noon business-day cutoff', () => {
        expect(clockInOffsetFromTimeInput('12:00')).toBe(12 * 60);
        expect(clockInOffsetFromTimeInput('01:00')).toBe(25 * 60);
        expect(clockInOffsetFromTimeInput('11:45')).toBe(35 * 60 + 45);
        expect(clockOutOffsetFromTimeInput('13:00', 15 * 60)).toBe(37 * 60);
    });

    it('ATT-033/034/035: accepts preparation and late finish but rejects a 24-hour shift', () => {
        expect(
            calculateAttendancePreview(
                {
                    clock_in_offset_minutes: 15 * 60,
                    clock_out_offset_minutes: 37 * 60,
                },
                null,
            ),
        ).toMatchObject({ valid: true, workingMinutes: 22 * 60 });
        expect(
            calculateAttendancePreview(
                {
                    clock_in_offset_minutes: 15 * 60,
                    clock_out_offset_minutes: 39 * 60,
                },
                null,
            ).valid,
        ).toBe(false);
    });

    it('LNT-008: calculates late-night overlap from the business date interval', () => {
        expect(
            calculateAttendancePreview(
                {
                    clock_in_offset_minutes: 25 * 60,
                    clock_out_offset_minutes: 29 * 60,
                },
                null,
            ),
        ).toMatchObject({
            valid: true,
            workingMinutes: 240,
            lateNightMinutes: 240,
        });
    });

    it('ATT-012: warns at a 15-minute difference but permits the value', () => {
        expect(
            calculateAttendancePreview(
                {
                    clock_in_offset_minutes: 19 * 60 + 15,
                    clock_out_offset_minutes: 23 * 60,
                },
                19 * 60,
            ),
        ).toMatchObject({
            valid: true,
            warning: 'シフト予定19:00と実出勤19:15が異なります。',
        });
    });

    it('ATT-013/014: has no warning without a scheduled time', () => {
        expect(
            calculateAttendancePreview(
                {
                    clock_in_offset_minutes: 19 * 60 + 15,
                    clock_out_offset_minutes: 23 * 60,
                },
                null,
            ).warning,
        ).toBeNull();
    });

    it('detects only changed rows for atomic bulk saving', () => {
        const initial = {
            1: {
                clock_in_offset_minutes: 19 * 60,
                clock_out_offset_minutes: 23 * 60,
            },
            2: {
                clock_in_offset_minutes: null,
                clock_out_offset_minutes: null,
            },
        };
        const current = {
            ...initial,
            2: {
                clock_in_offset_minutes: 20 * 60,
                clock_out_offset_minutes: 24 * 60,
            },
        };

        expect(hasUnsavedAttendanceChanges(initial, current)).toBe(true);
        expect(changedAttendanceRecords(initial, current)).toEqual([
            {
                staff_id: 2,
                clock_in_offset_minutes: 20 * 60,
                clock_out_offset_minutes: 24 * 60,
            },
        ]);
    });
});
