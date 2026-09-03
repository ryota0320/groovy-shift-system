export type PayrollResult = {
    id: number;
    payment_date: string;
    tax_year: number;
    working_minutes: number;
    late_night_minutes: number;
    base_pay: number;
    late_night_pay: number;
    transportation_fee_total: number;
    transportation_fee_taxable: number;
    transportation_fee_non_taxable: number;
    commission: number;
    gross_pay: number;
    taxable_pay: number;
    social_insurance_deduction: number;
    tax_table_reference_amount: number;
    income_tax: number;
    other_deductions: number;
    total_deductions: number;
    net_pay: number;
    needs_recalculation: boolean;
    calculated_at: string | null;
};

export type PayrollStaff = {
    staff_id: number;
    name: string;
    commission: number;
    payroll: PayrollResult | null;
};
