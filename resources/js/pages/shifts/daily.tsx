import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    CalendarOff,
    Grid3X3,
    Save,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import MasterPageHeader from '@/components/master-page-header';
import ShiftSelect from '@/components/shift-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { storeSelectionPlaceholder } from '@/lib/master-options';
import {
    hasUnsavedShiftChanges,
    shouldConfirmShiftNavigation,
    valuesFromStaffs,
} from '@/lib/shift-form';
import type { ShiftValues } from '@/lib/shift-form';
import type { DailyShiftStaff, ShiftValue, StoreOption } from '@/types';

type Props = {
    stores: StoreOption[];
    selected_store: StoreOption | null;
    date: string;
    previous_date: string;
    next_date: string;
    weekday: string;
    is_holiday: boolean;
    staffs: DailyShiftStaff[];
};

export default function DailyShift({
    stores,
    selected_store: selectedStore,
    date,
    previous_date: previousDate,
    next_date: nextDate,
    weekday,
    is_holiday: isHoliday,
    staffs,
}: Props) {
    const initialValues = useMemo(() => valuesFromStaffs(staffs), [staffs]);
    const [values, setValues] = useState<ShiftValues>(initialValues);
    const [saving, setSaving] = useState(false);
    const savingRef = useRef(false);
    const dirty = hasUnsavedShiftChanges(initialValues, values);
    const storePlaceholder = storeSelectionPlaceholder(stores, selectedStore);

    useEffect(() => {
        setValues(initialValues);
    }, [initialValues]);

    useEffect(() => {
        const beforeUnload = (event: BeforeUnloadEvent) => {
            if (!dirty) return;
            event.preventDefault();
        };
        window.addEventListener('beforeunload', beforeUnload);

        const removeBeforeListener = router.on('before', (event) => {
            if (
                shouldConfirmShiftNavigation(dirty, savingRef.current) &&
                !window.confirm(
                    '未保存の変更があります。移動してもよろしいですか？',
                )
            ) {
                event.preventDefault();
            }
        });

        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            removeBeforeListener();
        };
    }, [dirty]);

    const move = (storeId: string, targetDate: string) => {
        router.get(
            '/shifts/daily',
            { store_id: storeId, date: targetDate },
            { preserveState: false },
        );
    };

    const updateValue = (staffId: number, next: ShiftValue) => {
        setValues((current) => ({ ...current, [staffId]: next }));
    };

    const save = () => {
        if (!selectedStore || isHoliday) return;

        savingRef.current = true;
        setSaving(true);
        router.put(
            '/shifts/daily',
            {
                store_id: selectedStore.id,
                shift_date: date,
                shifts: staffs
                    .filter((staff) => staff.editable)
                    .map((staff) => ({
                        staff_id: staff.id,
                        shift_type: values[staff.id]?.shift_type ?? null,
                        start_time: values[staff.id]?.start_time ?? null,
                    })),
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    toast.error(
                        String(
                            Object.values(errors)[0] ?? '保存に失敗しました。',
                        ),
                    );
                },
                onFinish: () => {
                    savingRef.current = false;
                    setSaving(false);
                },
            },
        );
    };

    return (
        <>
            <Head title="日別シフト" />
            <div className="flex h-full min-w-0 flex-1 flex-col gap-5 p-4 pb-24 md:p-6 md:pb-6">
                <MasterPageHeader
                    title="日別シフト"
                    description="1日分のシフトをまとめて編集し、最後に一括保存します。"
                    actions={
                        selectedStore && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={`/shifts/monthly?store_id=${selectedStore.id}&month=${date.slice(0, 7)}`}
                                >
                                    <Grid3X3 />
                                    月間表示
                                </Link>
                            </Button>
                        )
                    }
                />

                <section className="border-border bg-card grid gap-4 rounded-xl border p-4 shadow-sm sm:grid-cols-[minmax(180px,1fr)_minmax(170px,1fr)_auto] sm:items-end">
                    <div className="grid gap-2">
                        <Label htmlFor="daily-store">店舗</Label>
                        <select
                            id="daily-store"
                            value={selectedStore?.id ?? ''}
                            onChange={(event) => move(event.target.value, date)}
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
                    <div className="grid gap-2">
                        <Label htmlFor="daily-date">対象日</Label>
                        <Input
                            id="daily-date"
                            type="date"
                            value={date}
                            onChange={(event) =>
                                move(
                                    String(selectedStore?.id ?? ''),
                                    event.target.value,
                                )
                            }
                        />
                    </div>
                    {selectedStore && (
                        <div className="flex items-center justify-between gap-2 sm:justify-end">
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="前日"
                                onClick={() =>
                                    move(String(selectedStore.id), previousDate)
                                }
                            >
                                <ArrowLeft />
                            </Button>
                            <span className="min-w-24 text-center text-sm font-medium">
                                {date.replaceAll('-', '/')}（{weekday}）
                            </span>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="翌日"
                                onClick={() =>
                                    move(String(selectedStore.id), nextDate)
                                }
                            >
                                <ArrowRight />
                            </Button>
                        </div>
                    )}
                </section>

                {isHoliday && (
                    <div className="flex items-center gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                        <CalendarOff className="size-5 shrink-0" />
                        この日は店舗休日です。シフトは登録できません。
                    </div>
                )}

                {selectedStore && !selectedStore.is_active && (
                    <div className="border-destructive/30 bg-destructive/10 text-destructive rounded-lg border px-4 py-3 text-sm">
                        無効な店舗のため、過去シフトの閲覧のみ可能です。
                    </div>
                )}

                {!selectedStore ? (
                    <Empty message="シフトを管理する店舗がありません。" />
                ) : staffs.length === 0 ? (
                    <Empty message="この日に所属するスタッフはいません。" />
                ) : (
                    <>
                        <div className="grid gap-3 md:hidden">
                            {staffs.map((staff) => (
                                <StaffShiftCard
                                    key={staff.id}
                                    staff={staff}
                                    value={values[staff.id]}
                                    isHoliday={isHoliday}
                                    onChange={(next) =>
                                        updateValue(staff.id, next)
                                    }
                                />
                            ))}
                        </div>

                        <div className="border-border bg-card hidden overflow-hidden rounded-xl border shadow-sm md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/70 text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3 text-left font-medium">
                                            スタッフ
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            雇用区分
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            シフト
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium">
                                            状態
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {staffs.map((staff) => (
                                        <tr key={staff.id}>
                                            <td className="px-4 py-3 font-medium">
                                                {staff.name}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-3">
                                                {staff.employment_type_label}
                                            </td>
                                            <td className="w-56 px-4 py-3">
                                                {staff.inconsistency ? (
                                                    <span
                                                        className="text-destructive text-sm font-medium"
                                                        title={
                                                            staff.inconsistency
                                                        }
                                                    >
                                                        {staff.display}
                                                        （要確認）
                                                    </span>
                                                ) : isHoliday ? (
                                                    <span className="text-muted-foreground">
                                                        店休
                                                    </span>
                                                ) : staff.conflict_store ? (
                                                    <Badge variant="secondary">
                                                        他店（
                                                        {staff.conflict_store}）
                                                    </Badge>
                                                ) : (
                                                    <ShiftSelect
                                                        value={values[staff.id]}
                                                        disabled={
                                                            !staff.editable
                                                        }
                                                        ariaLabel={`${staff.name}のシフト`}
                                                        onChange={(next) =>
                                                            updateValue(
                                                                staff.id,
                                                                next,
                                                            )
                                                        }
                                                    />
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <StaffState
                                                    staff={staff}
                                                    isHoliday={isHoliday}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}

                <div className="bg-background/95 supports-[backdrop-filter]:bg-background/75 fixed inset-x-0 bottom-0 z-40 border-t p-3 backdrop-blur md:static md:border-0 md:bg-transparent md:p-0 md:backdrop-blur-none">
                    <div className="mx-auto flex max-w-screen-2xl items-center justify-between gap-4">
                        <span className="text-muted-foreground text-sm">
                            {dirty
                                ? '未保存の変更があります'
                                : '変更は保存されています'}
                        </span>
                        <Button
                            onClick={save}
                            disabled={
                                !dirty ||
                                saving ||
                                isHoliday ||
                                !selectedStore?.is_active
                            }
                        >
                            <Save />
                            {saving ? '保存中…' : '一括保存'}
                        </Button>
                    </div>
                </div>
            </div>
        </>
    );
}

function StaffShiftCard({
    staff,
    value,
    isHoliday,
    onChange,
}: {
    staff: DailyShiftStaff;
    value: ShiftValue;
    isHoliday: boolean;
    onChange: (value: ShiftValue) => void;
}) {
    return (
        <article className="border-border bg-card rounded-xl border p-4 shadow-sm">
            <div className="mb-3 flex items-start justify-between gap-3">
                <div>
                    <h2 className="font-semibold">{staff.name}</h2>
                    <p className="text-muted-foreground text-sm">
                        {staff.employment_type_label}
                    </p>
                </div>
                <StaffState staff={staff} isHoliday={isHoliday} />
            </div>
            {staff.inconsistency ? (
                <div
                    className="border-destructive/30 bg-destructive/10 text-destructive rounded-md border px-3 py-2 text-sm"
                    title={staff.inconsistency}
                >
                    {staff.display}（要確認）— {staff.inconsistency}
                </div>
            ) : isHoliday ? (
                <div className="bg-muted text-muted-foreground rounded-md px-3 py-2 text-sm">
                    店休
                </div>
            ) : staff.conflict_store ? (
                <div className="bg-muted rounded-md px-3 py-2 text-sm">
                    他店（{staff.conflict_store}）に登録済み
                </div>
            ) : (
                <ShiftSelect
                    value={value}
                    disabled={!staff.editable}
                    ariaLabel={`${staff.name}のシフト`}
                    onChange={onChange}
                />
            )}
        </article>
    );
}

function StaffState({
    staff,
    isHoliday,
}: {
    staff: DailyShiftStaff;
    isHoliday: boolean;
}) {
    if (staff.inconsistency) return <Badge variant="destructive">要確認</Badge>;
    if (isHoliday) return <Badge variant="secondary">店休</Badge>;
    if (staff.conflict_store) return <Badge variant="secondary">他店</Badge>;
    if (!staff.eligible) return <Badge variant="outline">対象外</Badge>;
    return <Badge variant="outline">入力可</Badge>;
}

function Empty({ message }: { message: string }) {
    return (
        <div className="border-border bg-card text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">
            {message}
        </div>
    );
}

DailyShift.layout = {
    breadcrumbs: [{ title: '日別シフト', href: '/shifts/daily' }],
};
