import { describe, expect, it } from 'vite-plus/test';
import {
    payrollCardMetrics,
    payrollDisplayStatus,
    validCommissionAmount,
} from '@/lib/payroll-presentation';
import type { PayrollResult } from '@/types';

const payroll: PayrollResult = {
    id: 1,
    payment_date: '2026-10-10',
    tax_year: 2026,
    working_minutes: 375,
    late_night_minutes: 255,
    base_pay: 7875,
    late_night_pay: 638,
    transportation_fee_total: 1100,
    transportation_fee_taxable: 500,
    transportation_fee_non_taxable: 600,
    commission: 120000,
    gross_pay: 129613,
    taxable_pay: 129013,
    social_insurance_deduction: 0,
    tax_table_reference_amount: 129013,
    income_tax: 1440,
    other_deductions: 0,
    total_deductions: 1440,
    net_pay: 128173,
    needs_recalculation: false,
    calculated_at: '2026-10-01T12:00:00+09:00',
};

describe('Phase 4 payroll presentation', () => {
    it('PAY-016: shows every calculated payroll metric on the mobile card', () => {
        expect(
            payrollCardMetrics(payroll).map((metric) => metric.label),
        ).toEqual([
            '勤務時間',
            '深夜時間',
            '基本給',
            '深夜手当',
            '交通費合計',
            '課税交通費',
            '非課税交通費',
            '総支給額',
            '所得税',
            '総控除額',
            '差引支給額',
        ]);
    });

    it('PAY-010: distinguishes uncalculated, stale, and calculated payrolls', () => {
        expect(payrollDisplayStatus(null)).toBe('not_calculated');
        expect(
            payrollDisplayStatus({
                ...payroll,
                needs_recalculation: true,
            }),
        ).toBe('needs_recalculation');
        expect(payrollDisplayStatus(payroll)).toBe('calculated');
    });

    it('PAY-009: accepts only non-negative integer commissions', () => {
        expect(validCommissionAmount('120000')).toBe(120000);
        expect(validCommissionAmount('0')).toBe(0);
        expect(validCommissionAmount('-1')).toBeNull();
        expect(validCommissionAmount('1.5')).toBeNull();
    });
});
