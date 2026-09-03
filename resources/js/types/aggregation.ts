export type AggregationCostValues = {
    attendance_days: number;
    working_minutes: number;
    late_night_minutes: number;
    base_pay: number | null;
    late_night_pay: number | null;
    transportation_fee: number | null;
    labor_cost: number | null;
};

export type StoreAggregationRow = AggregationCostValues & {
    staff_id: number;
    name: string;
    employment_type: 'employee' | 'part_time';
    employment_type_label: string;
};

export type DailyAggregationGroup = {
    date: string;
    rows: StoreAggregationRow[];
    totals: AggregationCostValues & {
        base_pay: number;
        late_night_pay: number;
        transportation_fee: number;
        labor_cost: number;
    };
};

export type CrossStoreAggregationRow = {
    staff_id: number;
    name: string;
    employment_type: 'employee' | 'part_time';
    employment_type_label: string;
    attendance_days: number;
    store_minutes: {
        store_id: number;
        store_name: string;
        working_minutes: number;
    }[];
    working_minutes: number;
    late_night_minutes: number;
    payroll: {
        base_pay: number;
        late_night_pay: number;
        transportation_fee: number;
        gross_pay: number;
        needs_recalculation: boolean;
    } | null;
};
