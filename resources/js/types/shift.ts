export type ShiftType = 'time' | 'early' | 'off' | 'absence';

export type ShiftCell = {
    shift_type: ShiftType | null;
    start_time: string | null;
    store_id: number | null;
    display: string;
    eligible: boolean;
    editable: boolean;
    available_store_ids: number[];
    conflict_store: string | null;
    inconsistency: string | null;
};

export type MonthlyShiftDay = {
    date: string;
    day: number;
    weekday: string;
    is_saturday: boolean;
    is_sunday: boolean;
    is_holiday: boolean;
};

export type MonthlyShiftStaff = {
    id: number;
    name: string;
    employment_type: 'employee' | 'part_time';
    cells: (ShiftCell & { date: string })[];
};

export type DailyShiftStaff = ShiftCell & {
    id: number;
    name: string;
    employment_type: 'employee' | 'part_time';
    employment_type_label: string;
};

export type AddableShiftStaff = {
    id: number;
    name: string;
    employment_type: 'employee' | 'part_time';
    employment_type_label: string;
    assignment_store_names: string[];
    available_store_ids: number[];
};

export type ShiftValue = {
    shift_type: ShiftType | null;
    start_time: string | null;
    store_id: number | null;
};
