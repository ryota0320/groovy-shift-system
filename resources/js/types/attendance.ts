export type AttendanceValue = {
    clock_in_offset_minutes: number | null;
    clock_out_offset_minutes: number | null;
};

export type AttendancePayload = {
    id: number;
    clock_in_offset_minutes: number;
    clock_out_offset_minutes: number;
    clock_in_label: string;
    clock_out_label: string;
    working_minutes: number;
    late_night_minutes: number;
    warning: string | null;
};

export type AttendanceShift = {
    type: 'time' | 'early' | 'off' | 'absence' | null;
    display: string;
    start_offset_minutes: number | null;
};

export type DailyAttendanceStaff = {
    staff_id: number;
    name: string;
    employment_type: 'employee' | 'part_time';
    employment_type_label: string;
    source: 'scheduled' | 'sudden' | 'unplanned';
    eligible: boolean;
    editable: boolean;
    conflict_store: string | null;
    shift: AttendanceShift;
    attendance: AttendancePayload | null;
};

export type AddableAttendanceStaff = {
    id: number;
    name: string;
    employment_type: 'employee' | 'part_time';
    employment_type_label: string;
    assignment_store_names: string[];
};

export type AttendanceSummary = {
    attendance_count: number;
    working_minutes: number;
    late_night_minutes: number;
};
