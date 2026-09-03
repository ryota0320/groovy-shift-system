import { describe, expect, it } from 'vite-plus/test';
import {
    attendanceTimeOptions,
    calculateAttendancePreview,
    changedAttendanceRecords,
    hasUnsavedAttendanceChanges,
    offsetLabel,
} from '@/lib/attendance-form';

describe('Phase 3 attendance form', () => {
    it('ATT-017: displays next-day clock times without a prefix', () => {
        expect(offsetLabel(25 * 60)).toBe('1:00');
        expect(offsetLabel(29 * 60)).toBe('5:00');
    });

    it('ATT-023: shows one unambiguous sequence from 17:00 through 10:00', () => {
        const labels = attendanceTimeOptions.map(offsetLabel);

        expect(labels[0]).toBe('17:00');
        expect(labels.at(-1)).toBe('10:00');
        expect(labels).toHaveLength(new Set(labels).size);
        expect(labels.indexOf('0:00')).toBe(labels.indexOf('23:45') + 1);
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
