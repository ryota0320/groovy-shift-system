import { encodeShiftValue } from '@/components/shift-select';
import type { DailyShiftStaff, ShiftValue } from '@/types';

export type ShiftValues = Record<number, ShiftValue>;

export const valuesFromStaffs = (staffs: DailyShiftStaff[]): ShiftValues =>
    Object.fromEntries(
        staffs.map((staff) => [
            staff.id,
            {
                shift_type: staff.shift_type,
                start_time: staff.start_time,
                store_id: staff.store_id,
            },
        ]),
    );

export const serializeShiftValues = (values: ShiftValues) =>
    JSON.stringify(
        Object.entries(values)
            .sort(([left], [right]) => Number(left) - Number(right))
            .map(([staffId, value]) => [staffId, encodeShiftValue(value)]),
    );

export const hasUnsavedShiftChanges = (
    initialValues: ShiftValues,
    currentValues: ShiftValues,
) =>
    serializeShiftValues(currentValues) !== serializeShiftValues(initialValues);

export const shouldConfirmShiftNavigation = (dirty: boolean, saving: boolean) =>
    dirty && !saving;
