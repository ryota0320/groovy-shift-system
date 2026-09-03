import { describe, expect, it } from 'vite-plus/test';
import {
    selectableHistoryStores,
    storeSelectionPlaceholder,
} from '@/lib/master-options';
import {
    hasUnsavedShiftChanges,
    shouldConfirmShiftNavigation,
} from '@/lib/shift-form';
import type { StoreOption } from '@/types';

const stores: StoreOption[] = [
    { id: 1, name: '有効店舗', is_active: true },
    { id: 2, name: '無効店舗', is_active: false },
];

describe('Phase 1 master options', () => {
    it('MST-014: excludes inactive stores from new histories', () => {
        expect(selectableHistoryStores(stores)).toEqual([stores[0]]);
        expect(selectableHistoryStores(stores, 2)).toEqual(stores);
    });

    it('DASH-005: exposes an explicit empty selection when no active store exists', () => {
        expect(storeSelectionPlaceholder([stores[1]], null)).toBe('店舗を選択');
        expect(storeSelectionPlaceholder([], null)).toBe('店舗なし');
    });
});

describe('Phase 2 daily shift state', () => {
    it('SFT-011: detects changes independently of staff key order', () => {
        const initial = {
            2: { shift_type: null, start_time: null },
            1: { shift_type: 'time' as const, start_time: '19:00' },
        };
        const reordered = {
            1: { shift_type: 'time' as const, start_time: '19:00' },
            2: { shift_type: null, start_time: null },
        };
        const changed = {
            ...reordered,
            2: { shift_type: 'early' as const, start_time: null },
        };

        expect(hasUnsavedShiftChanges(initial, reordered)).toBe(false);
        expect(hasUnsavedShiftChanges(initial, changed)).toBe(true);
    });

    it('SFT-012: requests confirmation only for unsaved non-saving changes', () => {
        expect(shouldConfirmShiftNavigation(true, false)).toBe(true);
        expect(shouldConfirmShiftNavigation(true, true)).toBe(false);
        expect(shouldConfirmShiftNavigation(false, false)).toBe(false);
    });
});
