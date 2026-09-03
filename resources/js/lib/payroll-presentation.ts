import type { PayrollResult } from '@/types';

export type PayrollCardMetric = {
    label: string;
    value: string;
    strong?: boolean;
};

export type PayrollDisplayStatus =
    | 'not_calculated'
    | 'needs_recalculation'
    | 'calculated';

export const yen = (amount: number) => `${amount.toLocaleString('ja-JP')}円`;

export const formatMinutes = (minutes: number) =>
    `${Math.floor(minutes / 60)}時間${minutes % 60 > 0 ? `${minutes % 60}分` : ''}`;

export const payrollCardMetrics = (
    payroll: PayrollResult,
): PayrollCardMetric[] => [
    {
        label: '勤務時間',
        value: formatMinutes(payroll.working_minutes),
    },
    {
        label: '深夜時間',
        value: formatMinutes(payroll.late_night_minutes),
    },
    { label: '基本給', value: yen(payroll.base_pay) },
    { label: '深夜手当', value: yen(payroll.late_night_pay) },
    {
        label: '交通費合計',
        value: yen(payroll.transportation_fee_total),
    },
    {
        label: '課税交通費',
        value: yen(payroll.transportation_fee_taxable),
    },
    {
        label: '非課税交通費',
        value: yen(payroll.transportation_fee_non_taxable),
    },
    { label: '総支給額', value: yen(payroll.gross_pay) },
    { label: '所得税', value: yen(payroll.income_tax) },
    { label: '総控除額', value: yen(payroll.total_deductions) },
    { label: '差引支給額', value: yen(payroll.net_pay), strong: true },
];

export const payrollDisplayStatus = (
    payroll: PayrollResult | null,
): PayrollDisplayStatus => {
    if (payroll === null) return 'not_calculated';

    return payroll.needs_recalculation ? 'needs_recalculation' : 'calculated';
};

export const validCommissionAmount = (value: string): number | null => {
    const amount = Number(value);

    return Number.isInteger(amount) && amount >= 0 ? amount : null;
};
