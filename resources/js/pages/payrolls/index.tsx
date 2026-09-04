import { Head, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Calculator,
    RefreshCw,
    Save,
    Trash2,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import FileDownloadButton from '@/components/file-download-button';
import MasterPageHeader from '@/components/master-page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    formatMinutes,
    payrollCardMetrics,
    payrollDisplayStatus,
    payrollStatementAvailable,
    validCommissionAmount,
    yen,
} from '@/lib/payroll-presentation';
import type { PayrollStaff } from '@/types';

type Props = {
    year: number;
    month: number;
    previous_period: string;
    next_period: string;
    staffs: PayrollStaff[];
};

export default function PayrollIndex({
    year,
    month,
    previous_period,
    next_period,
    staffs,
}: Props) {
    const [commissions, setCommissions] = useState<Record<number, string>>(
        commissionValues(staffs),
    );
    const [savingCommissionId, setSavingCommissionId] = useState<number | null>(
        null,
    );
    const [calculatingId, setCalculatingId] = useState<number | null>(null);
    const [calculatingAll, setCalculatingAll] = useState(false);
    const payablePayrolls = staffs
        .map((staff) => staff.payroll)
        .filter((payroll) => payroll !== null && payroll.gross_pay > 0);
    const canDownloadAll =
        payablePayrolls.length > 0 &&
        payablePayrolls.every(payrollStatementAvailable);
    const outputQuery = `year=${year}&month=${month}`;

    useEffect(() => setCommissions(commissionValues(staffs)), [staffs]);

    const move = (period: string) => {
        const [nextYear, nextMonth] = period.split('-').map(Number);
        router.get(
            '/payrolls',
            { year: nextYear, month: nextMonth },
            { preserveState: false },
        );
    };
    const saveCommission = (staff: PayrollStaff) => {
        const amount = validCommissionAmount(commissions[staff.staff_id]);
        if (amount === null) {
            toast.error('歩合は0円以上の整数で入力してください。');
            return;
        }
        setSavingCommissionId(staff.staff_id);
        router.put(
            '/commissions',
            { staff_id: staff.staff_id, year, month, amount },
            {
                preserveScroll: true,
                onError: (errors) =>
                    showError(errors, '歩合の保存に失敗しました。'),
                onFinish: () => setSavingCommissionId(null),
            },
        );
    };
    const deleteCommission = (staff: PayrollStaff) => {
        if (!window.confirm(`${staff.name}さんの歩合を0円へ戻しますか？`))
            return;
        setSavingCommissionId(staff.staff_id);
        router.delete(`/commissions/${staff.staff_id}/${year}/${month}`, {
            preserveScroll: true,
            onError: (errors) =>
                showError(errors, '歩合の削除に失敗しました。'),
            onFinish: () => setSavingCommissionId(null),
        });
    };
    const calculate = (staff: PayrollStaff) => {
        setCalculatingId(staff.staff_id);
        router.post(
            `/payrolls/${staff.staff_id}/calculate`,
            { year, month },
            {
                preserveScroll: true,
                onError: (errors) =>
                    showError(errors, '給与計算に失敗しました。'),
                onFinish: () => setCalculatingId(null),
            },
        );
    };
    const calculateAll = () => {
        if (
            !window.confirm(
                `${year}年${month}月の全アルバイト給与を再計算しますか？`,
            )
        )
            return;
        setCalculatingAll(true);
        router.post(
            '/payrolls/calculate-all',
            { year, month },
            {
                preserveScroll: true,
                onError: (errors) =>
                    showError(errors, '一括給与計算に失敗しました。'),
                onFinish: () => setCalculatingAll(false),
            },
        );
    };

    return (
        <>
            <Head title="給与計算" />
            <div className="flex h-full min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
                <MasterPageHeader
                    title="給与計算"
                    description="全店舗の勤怠を合算し、履歴単価と支給年の源泉徴収税額表で月次給与を計算します。"
                />
                <section className="border-border bg-card flex flex-col gap-4 rounded-xl border p-4 shadow-sm sm:flex-row sm:items-end sm:justify-between">
                    <div className="grid gap-2">
                        <Label htmlFor="payroll-period">給与対象月</Label>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="前月"
                                onClick={() => move(previous_period)}
                            >
                                <ArrowLeft />
                            </Button>
                            <Input
                                id="payroll-period"
                                type="month"
                                className="w-44"
                                value={`${year}-${String(month).padStart(2, '0')}`}
                                onChange={(event) => move(event.target.value)}
                            />
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="翌月"
                                onClick={() => move(next_period)}
                            >
                                <ArrowRight />
                            </Button>
                        </div>
                    </div>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <FileDownloadButton
                            variant="outline"
                            disabled={!canDownloadAll}
                            title={
                                canDownloadAll
                                    ? undefined
                                    : '支給額が1円以上の給与を計算してから出力してください'
                            }
                            url={`/payroll-statements.zip?${outputQuery}`}
                            label="給与明細一括ZIP"
                            fallbackFilename={`${year}年${String(month).padStart(2, '0')}月_給与明細一括.zip`}
                        />
                        <Button
                            disabled={calculatingAll || staffs.length === 0}
                            onClick={calculateAll}
                        >
                            <RefreshCw
                                className={calculatingAll ? 'animate-spin' : ''}
                            />
                            {calculatingAll ? '一括計算中…' : '全員を再計算'}
                        </Button>
                    </div>
                </section>

                {staffs.length === 0 ? (
                    <div className="border-border bg-card text-muted-foreground rounded-xl border p-10 text-center shadow-sm">
                        この月に在籍するアルバイトはいません。
                    </div>
                ) : (
                    <>
                        <div className="grid gap-3 xl:hidden">
                            {staffs.map((staff) => (
                                <PayrollCard
                                    key={staff.staff_id}
                                    year={year}
                                    month={month}
                                    staff={staff}
                                    commission={
                                        commissions[staff.staff_id] ?? '0'
                                    }
                                    setCommission={(value) =>
                                        setCommissions((current) => ({
                                            ...current,
                                            [staff.staff_id]: value,
                                        }))
                                    }
                                    savingCommission={
                                        savingCommissionId === staff.staff_id
                                    }
                                    calculating={
                                        calculatingId === staff.staff_id
                                    }
                                    saveCommission={() => saveCommission(staff)}
                                    deleteCommission={() =>
                                        deleteCommission(staff)
                                    }
                                    calculate={() => calculate(staff)}
                                />
                            ))}
                        </div>
                        <div className="border-border bg-card hidden overflow-x-auto rounded-xl border shadow-sm xl:block">
                            <table className="w-full min-w-[1450px] text-sm">
                                <thead className="bg-muted/70 text-muted-foreground">
                                    <tr>
                                        {[
                                            'スタッフ',
                                            '状態',
                                            '勤務',
                                            '深夜',
                                            '基本給',
                                            '深夜手当',
                                            '交通費',
                                            '歩合',
                                            '総支給',
                                            '所得税',
                                            '総控除',
                                            '差引支給',
                                            '操作',
                                        ].map((label) => (
                                            <th
                                                key={label}
                                                className="px-3 py-3 text-left font-medium"
                                            >
                                                {label}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {staffs.map((staff) => {
                                        const payroll = staff.payroll;
                                        return (
                                            <tr key={staff.staff_id}>
                                                <td className="px-3 py-3 font-medium">
                                                    {staff.name}
                                                </td>
                                                <td className="px-3 py-3">
                                                    <PayrollStatus
                                                        staff={staff}
                                                    />
                                                </td>
                                                <td className="px-3 py-3 whitespace-nowrap">
                                                    {payroll
                                                        ? formatMinutes(
                                                              payroll.working_minutes,
                                                          )
                                                        : '—'}
                                                </td>
                                                <td className="px-3 py-3 whitespace-nowrap">
                                                    {payroll
                                                        ? formatMinutes(
                                                              payroll.late_night_minutes,
                                                          )
                                                        : '—'}
                                                </td>
                                                <td className="px-3 py-3 whitespace-nowrap">
                                                    {payroll
                                                        ? yen(payroll.base_pay)
                                                        : '—'}
                                                </td>
                                                <td className="px-3 py-3 whitespace-nowrap">
                                                    {payroll
                                                        ? yen(
                                                              payroll.late_night_pay,
                                                          )
                                                        : '—'}
                                                </td>
                                                <td className="px-3 py-3 whitespace-nowrap">
                                                    {payroll ? (
                                                        <TransportationBreakdown
                                                            payroll={payroll}
                                                        />
                                                    ) : (
                                                        '—'
                                                    )}
                                                </td>
                                                <td className="w-44 px-3 py-3">
                                                    <CommissionInput
                                                        value={
                                                            commissions[
                                                                staff.staff_id
                                                            ] ?? '0'
                                                        }
                                                        onChange={(value) =>
                                                            setCommissions(
                                                                (current) => ({
                                                                    ...current,
                                                                    [staff.staff_id]:
                                                                        value,
                                                                }),
                                                            )
                                                        }
                                                        saving={
                                                            savingCommissionId ===
                                                            staff.staff_id
                                                        }
                                                        hasCommission={
                                                            staff.commission > 0
                                                        }
                                                        onSave={() =>
                                                            saveCommission(
                                                                staff,
                                                            )
                                                        }
                                                        onDelete={() =>
                                                            deleteCommission(
                                                                staff,
                                                            )
                                                        }
                                                    />
                                                </td>
                                                <td className="px-3 py-3 whitespace-nowrap">
                                                    {payroll
                                                        ? yen(payroll.gross_pay)
                                                        : '—'}
                                                </td>
                                                <td className="px-3 py-3 whitespace-nowrap">
                                                    {payroll
                                                        ? yen(
                                                              payroll.income_tax,
                                                          )
                                                        : '—'}
                                                </td>
                                                <td className="px-3 py-3 whitespace-nowrap">
                                                    {payroll
                                                        ? yen(
                                                              payroll.total_deductions,
                                                          )
                                                        : '—'}
                                                </td>
                                                <td className="px-3 py-3 font-semibold whitespace-nowrap">
                                                    {payroll
                                                        ? yen(payroll.net_pay)
                                                        : '—'}
                                                </td>
                                                <td className="px-3 py-3">
                                                    <div className="flex items-center gap-1">
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={
                                                                calculatingId ===
                                                                staff.staff_id
                                                            }
                                                            onClick={() =>
                                                                calculate(staff)
                                                            }
                                                        >
                                                            <Calculator />
                                                            {calculatingId ===
                                                            staff.staff_id
                                                                ? '計算中…'
                                                                : '再計算'}
                                                        </Button>
                                                        {payrollStatementAvailable(
                                                            payroll,
                                                        ) && (
                                                            <FileDownloadButton
                                                                size="icon"
                                                                variant="ghost"
                                                                title="給与明細PDF"
                                                                iconOnly
                                                                url={`/payrolls/${staff.staff_id}/statement?${outputQuery}`}
                                                                label="給与明細PDF"
                                                                fallbackFilename={`${year}年${String(month).padStart(2, '0')}月_${staff.name}_給与明細.pdf`}
                                                            />
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

type RowActions = {
    staff: PayrollStaff;
    year: number;
    month: number;
    commission: string;
    setCommission: (value: string) => void;
    savingCommission: boolean;
    calculating: boolean;
    saveCommission: () => void;
    deleteCommission: () => void;
    calculate: () => void;
};

function PayrollCard(props: RowActions) {
    const payroll = props.staff.payroll;
    return (
        <article className="border-border bg-card grid gap-4 rounded-xl border p-4 shadow-sm">
            <div className="flex items-center justify-between gap-3">
                <h2 className="font-semibold">{props.staff.name}</h2>
                <PayrollStatus staff={props.staff} />
            </div>
            {payroll ? (
                <dl className="grid grid-cols-2 gap-3 text-sm">
                    {payrollCardMetrics(payroll).map((metric) => (
                        <Metric key={metric.label} {...metric} />
                    ))}
                </dl>
            ) : (
                <p className="text-muted-foreground text-sm">
                    まだ計算されていません。
                </p>
            )}
            <div className="grid gap-3 border-t pt-4">
                <Label>歩合</Label>
                <CommissionInput
                    value={props.commission}
                    onChange={props.setCommission}
                    saving={props.savingCommission}
                    hasCommission={props.staff.commission > 0}
                    onSave={props.saveCommission}
                    onDelete={props.deleteCommission}
                />
                <Button
                    variant="outline"
                    disabled={props.calculating}
                    onClick={props.calculate}
                >
                    <Calculator />
                    {props.calculating ? '計算中…' : '給与を再計算'}
                </Button>
                {payrollStatementAvailable(payroll) && (
                    <FileDownloadButton
                        variant="outline"
                        url={`/payrolls/${props.staff.staff_id}/statement?year=${props.year}&month=${props.month}`}
                        label="給与明細PDF"
                        fallbackFilename={`${props.year}年${String(props.month).padStart(2, '0')}月_${props.staff.name}_給与明細.pdf`}
                    />
                )}
            </div>
        </article>
    );
}

function CommissionInput({
    value,
    onChange,
    saving,
    hasCommission,
    onSave,
    onDelete,
}: {
    value: string;
    onChange: (value: string) => void;
    saving: boolean;
    hasCommission: boolean;
    onSave: () => void;
    onDelete: () => void;
}) {
    return (
        <div className="flex items-center gap-1">
            <Input
                type="number"
                min={0}
                step={1}
                value={value}
                aria-label="歩合"
                onChange={(event) => onChange(event.target.value)}
                className="min-w-24"
            />
            <Button
                type="button"
                size="icon"
                variant="ghost"
                aria-label="歩合を保存"
                disabled={saving}
                onClick={onSave}
            >
                <Save />
            </Button>
            {hasCommission && (
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    className="text-destructive"
                    aria-label="歩合を削除"
                    disabled={saving}
                    onClick={onDelete}
                >
                    <Trash2 />
                </Button>
            )}
        </div>
    );
}

function PayrollStatus({ staff }: { staff: PayrollStaff }) {
    const status = payrollDisplayStatus(staff.payroll);
    if (status === 'not_calculated')
        return <Badge variant="secondary">未計算</Badge>;
    if (status === 'needs_recalculation')
        return <Badge variant="destructive">再計算が必要</Badge>;
    return <Badge variant="outline">計算済み</Badge>;
}

function TransportationBreakdown({
    payroll,
}: {
    payroll: NonNullable<PayrollStaff['payroll']>;
}) {
    return (
        <div>
            <div className="font-medium">
                {yen(payroll.transportation_fee_total)}
            </div>
            <div className="text-muted-foreground text-xs">
                課税 {yen(payroll.transportation_fee_taxable)} / 非課税{' '}
                {yen(payroll.transportation_fee_non_taxable)}
            </div>
        </div>
    );
}

function Metric({
    label,
    value,
    strong = false,
}: {
    label: string;
    value: string;
    strong?: boolean;
}) {
    return (
        <div className="bg-muted/50 rounded-lg p-3">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className={strong ? 'mt-1 font-bold' : 'mt-1 font-medium'}>
                {value}
            </dd>
        </div>
    );
}

const commissionValues = (staffs: PayrollStaff[]) =>
    Object.fromEntries(
        staffs.map((staff) => [staff.staff_id, String(staff.commission)]),
    );
const showError = (errors: Record<string, string>, fallback: string) =>
    toast.error(String(Object.values(errors)[0] ?? fallback));
