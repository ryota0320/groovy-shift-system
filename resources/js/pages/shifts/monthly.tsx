import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays, Download, Rows3 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import MasterPageHeader from '@/components/master-page-header';
import ShiftSelect, {
    decodeShiftValue,
    encodeShiftValue,
} from '@/components/shift-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    MonthlyShiftDay,
    MonthlyShiftStaff,
    ShiftValue,
    StoreOption,
} from '@/types';
import { cn } from '@/lib/utils';
import { storeSelectionPlaceholder } from '@/lib/master-options';

type Props = {
    stores: StoreOption[];
    selected_store: StoreOption | null;
    month: string;
    days: MonthlyShiftDay[];
    staffs: MonthlyShiftStaff[];
};

export default function MonthlyShift({
    stores,
    selected_store: selectedStore,
    month,
    days,
    staffs,
}: Props) {
    const [overrides, setOverrides] = useState<Record<string, string>>({});
    const [savingCell, setSavingCell] = useState<string | null>(null);
    const storePlaceholder = storeSelectionPlaceholder(stores, selectedStore);

    const move = (storeId: string, targetMonth: string) => {
        router.get(
            '/shifts/monthly',
            { store_id: storeId, month: targetMonth },
            { preserveState: false },
        );
    };

    const saveCell = (
        staffId: number,
        date: string,
        previous: ShiftValue,
        next: ShiftValue,
    ) => {
        if (!selectedStore) return;

        const key = `${staffId}:${date}`;
        setOverrides((current) => ({
            ...current,
            [key]: encodeShiftValue(next),
        }));
        setSavingCell(key);
        router.put(
            '/shifts/cell',
            {
                store_id: selectedStore.id,
                staff_id: staffId,
                shift_date: date,
                shift_type: next.shift_type,
                start_time: next.start_time,
                work_store_id: next.store_id,
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    setOverrides((current) => ({
                        ...current,
                        [key]: encodeShiftValue(previous),
                    }));
                    toast.error(
                        String(
                            Object.values(errors)[0] ?? '保存に失敗しました。',
                        ),
                    );
                },
                onFinish: () => setSavingCell(null),
            },
        );
    };

    return (
        <>
            <Head title="月間シフト" />
            <div className="flex h-full min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
                <MasterPageHeader
                    title="月間シフト"
                    description="スタッフごとに、勤務開始時刻・早番・休み・急な休みと勤務店舗を登録します。"
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                disabled
                                title="PNG出力はフェーズ5で実装します"
                            >
                                <Download />
                                PNG出力（準備中）
                            </Button>
                            {selectedStore && (
                                <Button asChild>
                                    <Link
                                        href={`/shifts/daily?store_id=${selectedStore.id}&date=${month}-01`}
                                    >
                                        <Rows3 />
                                        日別入力
                                    </Link>
                                </Button>
                            )}
                        </div>
                    }
                />

                <section className="border-border bg-card flex flex-col gap-4 rounded-xl border p-4 shadow-sm sm:flex-row sm:items-end">
                    <div className="grid gap-2 sm:w-64">
                        <Label htmlFor="shift-store">店舗</Label>
                        <select
                            id="shift-store"
                            value={selectedStore?.id ?? ''}
                            onChange={(event) =>
                                move(event.target.value, month)
                            }
                            className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                        >
                            {storePlaceholder && (
                                <option value="">{storePlaceholder}</option>
                            )}
                            {stores.map((store) => (
                                <option key={store.id} value={store.id}>
                                    {store.name}
                                    {store.is_active ? '' : '（無効）'}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2 sm:w-48">
                        <Label htmlFor="shift-month">対象月</Label>
                        <Input
                            id="shift-month"
                            type="month"
                            value={month}
                            onChange={(event) =>
                                move(
                                    String(selectedStore?.id ?? ''),
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                    <div className="text-muted-foreground flex flex-wrap gap-3 text-xs sm:ml-auto sm:pb-2">
                        <span>00〜23：勤務開始時刻</span>
                        <span>早：早番</span>
                        <span>休：休み</span>
                        <span>勤務時は自店・ヘルプ先を選択</span>
                    </div>
                </section>

                {selectedStore && !selectedStore.is_active && (
                    <div className="border-destructive/30 bg-destructive/10 text-destructive rounded-lg border px-4 py-3 text-sm">
                        無効な店舗のため、過去シフトの閲覧のみ可能です。
                    </div>
                )}

                {!selectedStore ? (
                    <Empty message="シフトを管理する店舗がありません。" />
                ) : staffs.length === 0 ? (
                    <Empty message="この期間に所属するスタッフはいません。" />
                ) : (
                    <div className="border-border bg-card min-w-0 overflow-hidden rounded-xl border shadow-sm">
                        <div className="max-w-full overflow-x-auto">
                            <table className="w-max min-w-full border-separate border-spacing-0 text-sm">
                                <thead>
                                    <tr>
                                        <th className="bg-muted sticky top-0 left-0 z-30 min-w-40 border-r border-b px-3 py-2 text-left font-medium">
                                            スタッフ
                                        </th>
                                        {days.map((day) => (
                                            <th
                                                key={day.date}
                                                className={cn(
                                                    'bg-muted sticky top-0 z-20 min-w-16 border-r border-b px-1 py-2 text-center font-medium',
                                                    day.is_saturday &&
                                                        'bg-sky-50 text-sky-700 dark:bg-sky-950/40',
                                                    day.is_sunday &&
                                                        'bg-rose-50 text-rose-700 dark:bg-rose-950/40',
                                                    day.is_holiday &&
                                                        'bg-amber-50 dark:bg-amber-950/40',
                                                )}
                                            >
                                                <span className="block tabular-nums">
                                                    {day.day}
                                                </span>
                                                <span className="block text-xs">
                                                    （{day.weekday}）
                                                </span>
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {staffs.map((staff) => (
                                        <tr key={staff.id}>
                                            <th className="bg-card sticky left-0 z-10 border-r border-b px-3 py-2 text-left font-medium">
                                                <span className="block whitespace-nowrap">
                                                    {staff.name}
                                                </span>
                                                <span className="text-muted-foreground block text-xs font-normal">
                                                    {staff.employment_type ===
                                                    'employee'
                                                        ? '社員'
                                                        : 'アルバイト'}
                                                </span>
                                            </th>
                                            {staff.cells.map((cell, index) => {
                                                const day = days[index];
                                                const key = `${staff.id}:${cell.date}`;
                                                const initial = {
                                                    shift_type: cell.shift_type,
                                                    start_time: cell.start_time,
                                                    store_id: cell.store_id,
                                                };
                                                const value = overrides[key]
                                                    ? decodeShiftValue(
                                                          overrides[key],
                                                      )
                                                    : initial;

                                                return (
                                                    <td
                                                        key={cell.date}
                                                        className={cn(
                                                            'h-12 border-r border-b p-1 text-center',
                                                            day?.is_saturday &&
                                                                'bg-sky-50/50 dark:bg-sky-950/20',
                                                            day?.is_sunday &&
                                                                'bg-rose-50/50 dark:bg-rose-950/20',
                                                            day?.is_holiday &&
                                                                'bg-amber-50/70 dark:bg-amber-950/20',
                                                        )}
                                                    >
                                                        {cell.inconsistency ? (
                                                            <span
                                                                className="text-destructive inline-flex flex-col items-center gap-0.5 text-xs font-medium"
                                                                title={
                                                                    cell.inconsistency
                                                                }
                                                            >
                                                                <span>
                                                                    {
                                                                        cell.display
                                                                    }
                                                                </span>
                                                                <span>
                                                                    要確認
                                                                </span>
                                                            </span>
                                                        ) : day?.is_holiday ? (
                                                            <span className="text-muted-foreground text-xs">
                                                                店休
                                                            </span>
                                                        ) : cell.conflict_store ? (
                                                            <Badge
                                                                variant="secondary"
                                                                title={`${cell.conflict_store}に登録済み`}
                                                                className="px-1.5"
                                                            >
                                                                他店
                                                            </Badge>
                                                        ) : !cell.eligible ? (
                                                            <span className="text-muted-foreground">
                                                                —
                                                            </span>
                                                        ) : (
                                                            <ShiftSelect
                                                                compact
                                                                value={value}
                                                                stores={stores}
                                                                selectedStoreId={
                                                                    selectedStore.id
                                                                }
                                                                availableStoreIds={
                                                                    cell.available_store_ids
                                                                }
                                                                disabled={
                                                                    !cell.editable ||
                                                                    savingCell !==
                                                                        null
                                                                }
                                                                ariaLabel={`${staff.name} ${cell.date}のシフト`}
                                                                onChange={(
                                                                    next,
                                                                ) =>
                                                                    saveCell(
                                                                        staff.id,
                                                                        cell.date,
                                                                        value,
                                                                        next,
                                                                    )
                                                                }
                                                            />
                                                        )}
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

function Empty({ message }: { message: string }) {
    return (
        <div className="border-border bg-card text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">
            <CalendarDays className="mx-auto mb-3 size-8" />
            {message}
        </div>
    );
}

MonthlyShift.layout = {
    breadcrumbs: [{ title: '月間シフト', href: '/shifts/monthly' }],
};
