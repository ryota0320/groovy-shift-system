export type SelectOption = {
    value: string;
    label: string;
};

export type StoreOption = {
    id: number;
    name: string;
    opening_time: string;
    closing_time: string;
    is_active: boolean;
};

export type EffectivePeriod = {
    id: number;
    effective_from: string;
    effective_to: string | null;
};

export type StaffAssignment = EffectivePeriod & {
    store_id: number;
    store_name: string;
};

export type StaffWageRate = EffectivePeriod & {
    hourly_wage: number;
};

export type StaffTransportationFee = EffectivePeriod & {
    store_id: number;
    store_name: string;
    amount_per_day: number;
    tax_type: string;
    tax_type_label: string;
};

export type StaffIncomeTaxSetting = EffectivePeriod & {
    tax_category: string;
    tax_category_label: string;
    dependent_count: number;
};

export type StaffMaster = {
    id: number;
    name: string;
    employment_type: 'employee' | 'part_time';
    employment_type_label: string;
    hired_at: string | null;
    retired_at: string | null;
    user: { id: number; name: string; email: string } | null;
    assignments: StaffAssignment[];
    wage_rates: StaffWageRate[];
    transportation_fees: StaffTransportationFee[];
    income_tax_settings: StaffIncomeTaxSetting[];
};
