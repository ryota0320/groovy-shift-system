import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays, GripVertical, Plus, Rows3, Trash2 } from 'lucide-react';
import { type DragEvent, useEffect, useState } from 'react';
import { toast } from 'sonner';
import FileDownloadButton from '@/components/file-download-button';
import MasterPageHeader from '@/components/master-page-header';
import ShiftSelect, {
    decodeShiftValue,
    encodeShiftValue,
} from '@/components/shift-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    AddableMonthlyShiftStaff,
    MonthlyShiftDay,
    MonthlyShiftStaff,
    ShiftValue,
    StoreOption,
} from '@/types';
import { cn } from '@/lib/utils';
import { storeSelectionPlaceholder } from '@/lib/master-options';
import {
    monthlyShiftOverrideKey,
    withoutMonthlyShiftOverride,
} from '@/lib/monthly-shift-state';

type Props = {
    stores: StoreOption[];
    selected_store: StoreOption | null;
    month: string;
    days: MonthlyShiftDay[];
    staffs: MonthlyShiftStaff[];
    addable_staffs: AddableMonthlyShiftStaff[];
};

export default function MonthlyShift({
    stores,
    selected_store: selectedStore,
    month,
    days,
    staffs,
    addable_staffs: addableStaffs,
}: Props) {
    const [overrides, setOverrides] = useState<Record<string, string>>({});
    const [savingCell, setSavingCell] = useState<string | null>(null);
    const [orderedStaffs, setOrderedStaffs] = useState(staffs);
    const [draggedStaffId, setDraggedStaffId] = useState<number | null>(null);
    const [dragOverStaffId, setDragOverStaffId] = useState<number | null>(null);
    const [savingOrder, setSavingOrder] = useState(false);
    const [addDialogOpen, setAddDialogOpen] = useState(false);
    const [staffToAdd, setStaffToAdd] = useState('');
    const [addingStaff, setAddingStaff] = useState(false);
    const [removingStaffId, setRemovingStaffId] = useState<number | null>(null);
    const storePlaceholder = storeSelectionPlaceholder(stores, selectedStore);

    useEffect(() => {
        setOverrides({});
        setOrderedStaffs(staffs);
        setDraggedStaffId(null);
        setDragOverStaffId(null);
        setStaffToAdd('');
    }, [staffs, selectedStore?.id, month]);

    const move = (storeId: string, targetMonth: string) => {
        router.get(
            '/shifts/monthly',
            { store_id: storeId, month: targetMonth },
            { preserveState: false },
        );
    };

    const saveCell = (staffId: number, date: string, next: ShiftValue) => {
        if (!selectedStore) return;

        const key = monthlyShiftOverrideKey(selectedStore.id, staffId, date);
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
                    toast.error(
                        String(
                            Object.values(errors)[0] ?? '保存に失敗しました。',
                        ),
                    );
                },
                onFinish: () => {
                    setOverrides((current) =>
                        withoutMonthlyShiftOverride(current, key),
                    );
                    setSavingCell(null);
                },
            },
        );
    };

    const startDragging = (
        event: DragEvent<HTMLButtonElement>,
        staffId: number,
    ) => {
        setDraggedStaffId(staffId);
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(staffId));
    };

    const dropStaff = (
        event: DragEvent<HTMLTableRowElement>,
        targetStaffId: number,
    ) => {
        event.preventDefault();
        const sourceStaffId =
            draggedStaffId ?? Number(event.dataTransfer.getData('text/plain'));
        setDraggedStaffId(null);
        setDragOverStaffId(null);

        if (
            !selectedStore ||
            savingOrder ||
            !Number.isInteger(sourceStaffId) ||
            sourceStaffId === targetStaffId
        ) {
            return;
        }

        const sourceIndex = orderedStaffs.findIndex(
            (staff) => staff.id === sourceStaffId,
        );
        const targetIndex = orderedStaffs.findIndex(
            (staff) => staff.id === targetStaffId,
        );
        if (sourceIndex < 0 || targetIndex < 0) return;

        const previous = orderedStaffs;
        const next = [...orderedStaffs];
        const [moved] = next.splice(sourceIndex, 1);
        if (!moved) return;
        next.splice(targetIndex, 0, moved);
        setOrderedStaffs(next);
        setSavingOrder(true);

        router.put(
            '/shifts/monthly/order',
            {
                store_id: selectedStore.id,
                month,
                staff_ids: next.map((staff) => staff.id),
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () =>
                    toast.success('スタッフの並び順を保存しました。'),
                onError: (errors) => {
                    setOrderedStaffs(previous);
                    toast.error(
                        String(
                            Object.values(errors)[0] ??
                                '並び順の保存に失敗しました。',
                        ),
                    );
                },
                onFinish: () => setSavingOrder(false),
            },
        );
    };

    const addStaff = () => {
        if (!selectedStore || !staffToAdd) return;
        setAddingStaff(true);
        router.post(
            '/shifts/monthly/staffs',
            {
                store_id: selectedStore.id,
                staff_id: Number(staffToAdd),
                month,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setAddDialogOpen(false);
                    setStaffToAdd('');
                },
                onError: (errors) =>
                    toast.error(
                        String(
                            Object.values(errors)[0] ??
                                'スタッフの追加に失敗しました。',
                        ),
                    ),
                onFinish: () => setAddingStaff(false),
            },
        );
    };

    const removeStaff = (staff: MonthlyShiftStaff) => {
        if (
            !selectedStore ||
            !staff.can_remove ||
            removingStaffId !== null ||
            !window.confirm(
                `${staff.name}さんをこの月の一覧から削除しますか？\n表示中の店舗で勤務する対象月のシフトも削除されます。所属店舗・別店舗のシフト、休み、急な休み、スタッフ情報、所属情報、勤怠実績は削除されません。`,
            )
        ) {
            return;
        }

        setRemovingStaffId(staff.id);
        router.delete('/shifts/monthly/staffs', {
            data: {
                store_id: selectedStore.id,
                staff_id: staff.id,
                month,
            },
            preserveScroll: true,
            onError: (errors) =>
                toast.error(
                    String(
                        Object.values(errors)[0] ??
                            'スタッフの削除に失敗しました。',
                    ),
                ),
            onFinish: () => setRemovingStaffId(null),
        });
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
                            {selectedStore && (
                                <FileDownloadButton
                                    variant="outline"
                                    url={`/shifts/monthly.png?store_id=${selectedStore.id}&month=${month}`}
                                    label="PNG出力"
                                    fallbackFilename={`${month}_${selectedStore.name}_シフト.png`}
                                />
                            )}
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
                    <Button
                        type="button"
                        variant="outline"
                        disabled={
                            !selectedStore?.is_active ||
                            addableStaffs.length === 0
                        }
                        onClick={() => setAddDialogOpen(true)}
                    >
                        <Plus />
                        スタッフ追加
                    </Button>
                    <div className="text-muted-foreground flex flex-wrap gap-3 text-xs sm:ml-auto sm:pb-2">
                        <span>
                            {savingOrder
                                ? '並び順を保存中…'
                                : 'スタッフ名をドラッグして並び替え'}
                        </span>
                        <span>勤務店舗と開始時刻を1つの選択肢で指定</span>
                        <span>早：早番</span>
                        <span>休：休み</span>
                        <span>
                            店休日は入力不可（他店勤務は勤務先店舗へスタッフ追加）
                        </span>
                    </div>
                </section>

                {selectedStore && !selectedStore.is_active && (
                    <div className="border-destructive/30 bg-destructive/10 text-destructive rounded-lg border px-4 py-3 text-sm">
                        無効な店舗のため、過去シフトの閲覧のみ可能です。
                    </div>
                )}

                {!selectedStore ? (
                    <Empty message="シフトを管理する店舗がありません。" />
                ) : orderedStaffs.length === 0 ? (
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
                                    {orderedStaffs.map((staff, staffIndex) => (
                                        <tr
                                            key={staff.id}
                                            className={
                                                staffIndex % 2 === 1
                                                    ? 'bg-muted/45 dark:bg-muted/60'
                                                    : 'bg-card'
                                            }
                                            onDragOver={(event) => {
                                                if (
                                                    draggedStaffId !== null &&
                                                    draggedStaffId !== staff.id
                                                ) {
                                                    event.preventDefault();
                                                    event.dataTransfer.dropEffect =
                                                        'move';
                                                    setDragOverStaffId(
                                                        staff.id,
                                                    );
                                                }
                                            }}
                                            onDrop={(event) =>
                                                dropStaff(event, staff.id)
                                            }
                                        >
                                            <th
                                                className={cn(
                                                    'sticky left-0 z-10 border-r border-b px-2 py-2 text-left font-medium',
                                                    dragOverStaffId === staff.id
                                                        ? 'bg-primary/10'
                                                        : staffIndex % 2 === 1
                                                          ? 'bg-muted/45 dark:bg-muted/60'
                                                          : 'bg-card',
                                                )}
                                            >
                                                <div className="flex items-center gap-1.5">
                                                    <button
                                                        type="button"
                                                        draggable={!savingOrder}
                                                        aria-label={`${staff.name}を並び替え`}
                                                        title="ドラッグして並び替え"
                                                        className="text-muted-foreground hover:bg-muted hover:text-foreground cursor-grab rounded p-1 active:cursor-grabbing disabled:cursor-not-allowed disabled:opacity-50"
                                                        disabled={savingOrder}
                                                        onDragStart={(event) =>
                                                            startDragging(
                                                                event,
                                                                staff.id,
                                                            )
                                                        }
                                                        onDragEnd={() => {
                                                            setDraggedStaffId(
                                                                null,
                                                            );
                                                            setDragOverStaffId(
                                                                null,
                                                            );
                                                        }}
                                                    >
                                                        <GripVertical className="size-4" />
                                                    </button>
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block whitespace-nowrap">
                                                            {staff.name}
                                                        </span>
                                                        <span className="text-muted-foreground block text-xs font-normal">
                                                            {staff.employment_type ===
                                                            'employee'
                                                                ? '社員'
                                                                : 'アルバイト'}
                                                            {staff.is_added &&
                                                                '・追加'}
                                                            {!staff.is_added &&
                                                                staff.can_remove &&
                                                                '・所属外'}
                                                        </span>
                                                    </span>
                                                    {staff.can_remove && (
                                                        <button
                                                            type="button"
                                                            aria-label={`${staff.name}をこの月の一覧から削除`}
                                                            title="この月の一覧から削除"
                                                            className="text-muted-foreground hover:bg-destructive/10 hover:text-destructive ml-auto rounded p-1 disabled:cursor-not-allowed disabled:opacity-50"
                                                            disabled={
                                                                savingOrder ||
                                                                removingStaffId !==
                                                                    null
                                                            }
                                                            onClick={() =>
                                                                removeStaff(
                                                                    staff,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="size-4" />
                                                        </button>
                                                    )}
                                                </div>
                                            </th>
                                            {staff.cells.map((cell, index) => {
                                                const day = days[index];
                                                const key =
                                                    monthlyShiftOverrideKey(
                                                        selectedStore.id,
                                                        staff.id,
                                                        cell.date,
                                                    );
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
                                                                'bg-sky-50/50 dark:bg-sky-950/55',
                                                            day?.is_sunday &&
                                                                'bg-rose-50/50 dark:bg-rose-950/55',
                                                            day?.is_holiday &&
                                                                'bg-amber-50/70 dark:bg-amber-950/50',
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
                                                                {cell.shift_type ===
                                                                    'off' ||
                                                                cell.shift_type ===
                                                                    'absence'
                                                                    ? cell.display
                                                                    : '店休'}
                                                            </span>
                                                        ) : cell.conflict_store ? (
                                                            <div
                                                                title={`${cell.conflict_store}の予定が登録済み`}
                                                                className="flex justify-center"
                                                            >
                                                                <ShiftSelect
                                                                    combined
                                                                    compact
                                                                    value={{
                                                                        shift_type:
                                                                            null,
                                                                        start_time:
                                                                            null,
                                                                        store_id:
                                                                            null,
                                                                    }}
                                                                    stores={
                                                                        stores
                                                                    }
                                                                    selectedStoreId={
                                                                        selectedStore.id
                                                                    }
                                                                    availableStoreIds={
                                                                        cell.available_store_ids
                                                                    }
                                                                    disabled
                                                                    ariaLabel={`${staff.name} ${cell.date}のシフト（別の予定があるため入力不可）`}
                                                                    onChange={() =>
                                                                        undefined
                                                                    }
                                                                />
                                                            </div>
                                                        ) : !cell.eligible ? (
                                                            <span className="text-muted-foreground">
                                                                —
                                                            </span>
                                                        ) : (
                                                            <ShiftSelect
                                                                combined
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

                <Dialog open={addDialogOpen} onOpenChange={setAddDialogOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                月間シフトへスタッフを追加
                            </DialogTitle>
                            <DialogDescription>
                                他店所属のスタッフを{month.replace('-', '年')}
                                月の最下部へ追加します。
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-2">
                            <Label htmlFor="monthly-shift-staff">
                                スタッフ
                            </Label>
                            <select
                                id="monthly-shift-staff"
                                value={staffToAdd}
                                onChange={(event) =>
                                    setStaffToAdd(event.target.value)
                                }
                                className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
                            >
                                <option value="">スタッフを選択</option>
                                {addableStaffs.map((staff) => (
                                    <option key={staff.id} value={staff.id}>
                                        {staff.name}（
                                        {staff.employment_type_label}・所属：
                                        {staff.assignment_store_names.join(
                                            '、',
                                        )}
                                        ）
                                    </option>
                                ))}
                            </select>
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setAddDialogOpen(false)}
                            >
                                キャンセル
                            </Button>
                            <Button
                                type="button"
                                disabled={!staffToAdd || addingStaff}
                                onClick={addStaff}
                            >
                                <Plus />
                                {addingStaff ? '追加中…' : '最下部へ追加'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
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
