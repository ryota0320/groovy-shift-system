import { describe, expect, it } from 'vite-plus/test';
import {
    monthlyShiftOverrideKey,
    withoutMonthlyShiftOverride,
} from '@/lib/monthly-shift-state';

describe('monthly shift optimistic state', () => {
    it('keeps the same staff and date isolated by the displayed store', () => {
        expect(monthlyShiftOverrideKey(1, 36, '2026-09-01')).not.toBe(
            monthlyShiftOverrideKey(2, 36, '2026-09-01'),
        );
    });

    it('discards a completed optimistic value without changing other cells', () => {
        expect(
            withoutMonthlyShiftOverride(
                {
                    '1:36:2026-09-01': 'early@1',
                    '2:36:2026-09-01': 'time:19:00@2',
                },
                '2:36:2026-09-01',
            ),
        ).toEqual({
            '1:36:2026-09-01': 'early@1',
        });
    });
});
