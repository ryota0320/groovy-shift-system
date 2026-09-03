import { describe, expect, it } from 'vite-plus/test';
import {
    decodeShiftValue,
    encodeShiftValue,
    selectableShiftStores,
    shiftTimeOptions,
} from '@/components/shift-select';
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
    {
        id: 1,
        name: '有効店舗',
        opening_time: '17:00',
        closing_time: '10:00',
        is_active: true,
    },
    {
        id: 2,
        name: '無効店舗',
        opening_time: '17:00',
        closing_time: '10:00',
        is_active: false,
    },
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
            2: { shift_type: null, start_time: null, store_id: null },
            1: {
                shift_type: 'time' as const,
                start_time: '19:00',
                store_id: 1,
            },
        };
        const reordered = {
            1: {
                shift_type: 'time' as const,
                start_time: '19:00',
                store_id: 1,
            },
            2: { shift_type: null, start_time: null, store_id: null },
        };
        const changed = {
            ...reordered,
            2: {
                shift_type: 'early' as const,
                start_time: null,
                store_id: 2,
            },
        };

        expect(hasUnsavedShiftChanges(initial, reordered)).toBe(false);
        expect(hasUnsavedShiftChanges(initial, changed)).toBe(true);
    });

    it('SFT-012: requests confirmation only for unsaved non-saving changes', () => {
        expect(shouldConfirmShiftNavigation(true, false)).toBe(true);
        expect(shouldConfirmShiftNavigation(true, true)).toBe(false);
        expect(shouldConfirmShiftNavigation(false, false)).toBe(false);
    });

    it('SFT-016: preserves the selected work store in shift values', () => {
        const value = {
            shift_type: 'time' as const,
            start_time: '20:00',
            store_id: 3,
        };

        expect(decodeShiftValue(encodeShiftValue(value))).toEqual(value);
    });

    it('SFT-018: includes newly supplied active stores automatically', () => {
        const addedStore = {
            id: 3,
            name: '新店舗',
            opening_time: '17:00',
            closing_time: '10:00',
            is_active: true,
        };

        expect(selectableShiftStores([...stores, addedStore], [1, 3])).toEqual([
            stores[0],
            addedStore,
        ]);
    });

    it('SFT-019: preserves urgent absence separately from scheduled off', () => {
        const absence = {
            shift_type: 'absence' as const,
            start_time: null,
            store_id: null,
        };

        expect(decodeShiftValue(encodeShiftValue(absence))).toEqual(absence);
        expect(encodeShiftValue(absence)).not.toBe('off');
    });

    it('SFT-026: limits and orders start times from the work store opening time', () => {
        const options = shiftTimeOptions('17:30:00', '02:30:00');

        expect(options.map((option) => option.time)).toEqual([
            '18:00',
            '19:00',
            '20:00',
            '21:00',
            '22:00',
            '23:00',
            '00:00',
            '01:00',
            '02:00',
        ]);
    });
});
