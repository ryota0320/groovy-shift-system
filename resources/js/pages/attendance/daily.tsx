import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    CalendarOff,
    Clock3,
    Plus,
    Save,
    Trash2,
    UserRoundPlus,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import MasterPageHeader from '@/components/master-page-header';
import { Badge } from '@/components/ui/badge';
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
import {
    attendanceValuesFromStaffs,
    calculateAttendancePreview,
    changedAttendanceRecords,
    clockInOffsetFromTimeInput,
    clockOutOffsetFromTimeInput,
    hasUnsavedAttendanceChanges,
    offsetInputValue,
} from '@/lib/attendance-form';
import type { AttendanceValues } from '@/lib/attendance-form';
import { storeSelectionPlaceholder } from '@/lib/master-options';
import type {
    AddableAttendanceStaff,
    AttendanceSummary,
    AttendanceValue,
    DailyAttendanceStaff,
    StoreOption,
} from '@/types';

type Props = {
    stores: StoreOption[];
    selected_store: StoreOption | null;
    date: string;
    previous_date: string;
    next_date: string;
    weekday: string;
    is_holiday: boolean;
    staffs: DailyAttendanceStaff[];
    addable_staffs: AddableAttendanceStaff[];
    summary: AttendanceSummary;
};

export default function DailyAttendance({
    stores,
    selected_store: selectedStore,
    date,
    previous_date: previousDate,
    next_date: nextDate,
    weekday,
    is_holiday: isHoliday,
    staffs,
    addable_staffs: addableStaffs,
}: Props) {
    const [addedStaffIds, setAddedStaffIds] = useState<number[]>([]);
    const [staffToAdd, setStaffToAdd] = useState('');
    const visibleStaffs = useMemo(
        () => [
            ...staffs,
            ...addedStaffIds.flatMap((staffId) => {
                const staff = addableStaffs.find(
                    (candidate) => candidate.id === staffId,
                );
                if (!staff) return [];

                return [suddenStaff(staff)];
            }),
        ],
        [addableStaffs, addedStaffIds, staffs],
    );
    const initialValues = useMemo(
        () => attendanceValuesFromStaffs(staffs),
        [staffs],
    );
    const [values, setValues] = useState<AttendanceValues>(initialValues);
    const [saving, setSaving] = useState(false);
    const [deletingAttendanceId, setDeletingAttendanceId] = useState<
        number | null
    >(null);
    const [markingAbsentStaffId, setMarkingAbsentStaffId] = useState<
        number | null
    >(null);
    const [replacementForStaff, setReplacementForStaff] =
        useState<DailyAttendanceStaff | null>(null);
    const [replacementStaffId, setReplacementStaffId] = useState('');
    const [replacementShiftKind, setReplacementShiftKind] = useState('');
    const [savingReplacement, setSavingReplacement] = useState(false);
    const savingRef = useRef(false);
    const dirty = hasUnsavedAttendanceChanges(initialValues, values);
    const storePlaceholder = storeSelectionPlaceholder(stores, selectedStore);

    useEffect(() => {
        setAddedStaffIds([]);
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
                dirty &&
                !savingRef.current &&
                !window.confirm(
                    '未保存の勤怠変更があります。移動してもよろしいですか？',
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
            '/attendance/daily',
            {
                store_id: storeId,
                date: targetDate,
            },
            { preserveState: false },
        );
    };

    const updateValue = (staffId: number, value: AttendanceValue) => {
        setValues((current) => ({ ...current, [staffId]: value }));
    };

    const addStaff = () => {
        const staffId = Number(staffToAdd);
        if (!staffId || addedStaffIds.includes(staffId)) return;
        setAddedStaffIds((current) => [...current, staffId]);
        setValues((current) => ({
            ...current,
            [staffId]: {
                clock_in_offset_minutes: null,
                clock_out_offset_minutes: null,
            },
        }));
        setStaffToAdd('');
    };

    const removeAddedStaff = (staffId: number) => {
        setAddedStaffIds((current) => current.filter((id) => id !== staffId));
        setValues((current) => {
            const next = { ...current };
            delete next[staffId];
            return next;
        });
    };

    const save = () => {
        if (!selectedStore || !dirty) return;
        const records = changedAttendanceRecords(initialValues, values);
        if (
            records.some(
                (record) =>
                    record.clock_in_offset_minutes === null ||
                    record.clock_out_offset_minutes === null,
            )
        ) {
            toast.error('変更したスタッフの実出勤と実退勤を選択してください。');
            return;
        }
        if (
            isHoliday &&
            !window.confirm('この店舗は店休日です。勤務実績を登録しますか？')
        ) {
            return;
        }

        savingRef.current = true;
        setSaving(true);
        router.put(
            '/attendance/daily',
            {
                store_id: selectedStore.id,
                work_date: date,
                holiday_confirmed: isHoliday,
                records,
            },
            {
                preserveScroll: true,
                onError: (errors) =>
                    toast.error(
                        String(
                            Object.values(errors)[0] ?? '保存に失敗しました。',
                        ),
                    ),
                onFinish: () => {
                    savingRef.current = false;
                    setSaving(false);
                },
            },
        );
    };

    const deleteAttendance = (staff: DailyAttendanceStaff) => {
        if (!staff.attendance || deletingAttendanceId !== null) return;
        const message = dirty
            ? `未保存の変更は破棄されます。${staff.name}さんの勤怠を削除しますか？`
            : `${staff.name}さんの勤怠を削除しますか？`;
        if (!window.confirm(message)) return;

        savingRef.current = true;
        setDeletingAttendanceId(staff.attendance.id);
        router.delete(`/attendance/${staff.attendance.id}`, {
            preserveScroll: true,
            onError: () =>
                toast.error('勤怠を削除できませんでした。再試行してください。'),
            onFinish: () => {
                savingRef.current = false;
                setDeletingAttendanceId(null);
            },
        });
    };

    const markAbsent = (staff: DailyAttendanceStaff) => {
        if (!selectedStore || !canMarkAbsent(staff)) return;
        const message = dirty
            ? `未保存の変更は破棄されます。${staff.name}さんを急な休みに変更しますか？`
            : `${staff.name}さんを急な休みに変更しますか？`;
        if (!window.confirm(message)) return;

        savingRef.current = true;
        setMarkingAbsentStaffId(staff.staff_id);
        router.put(
            '/shifts/cell',
            {
                store_id: selectedStore.id,
                staff_id: staff.staff_id,
                shift_date: date,
                shift_type: 'absence',
                start_time: null,
                work_store_id: null,
            },
            {
                preserveScroll: true,
                onError: (errors) =>
                    toast.error(
                        String(
                            Object.values(errors)[0] ??
                                '急な休みへの変更に失敗しました。',
                        ),
                    ),
                onFinish: () => {
                    savingRef.current = false;
                    setMarkingAbsentStaffId(null);
                },
            },
        );
    };

    const openReplacement = (staff: DailyAttendanceStaff) => {
        if (staff.shift.type !== 'absence') return;
        setReplacementForStaff(staff);
        setReplacementStaffId('');
        setReplacementShiftKind('');
    };

    const closeReplacement = () => {
        if (savingReplacement) return;
        setReplacementForStaff(null);
        setReplacementStaffId('');
        setReplacementShiftKind('');
    };

    const saveReplacement = () => {
        if (
            !selectedStore ||
            !replacementForStaff ||
            !replacementStaffId ||
            !replacementShiftKind
        ) {
            return;
        }
        if (
            dirty &&
            !window.confirm(
                '未保存の勤怠変更は破棄されます。代替スタッフを設定しますか？',
            )
        ) {
            return;
        }

        const early = replacementShiftKind === 'early';
        savingRef.current = true;
        setSavingReplacement(true);
        router.post(
            '/shifts',
            {
                store_id: selectedStore.id,
                staff_id: Number(replacementStaffId),
                shift_date: date,
                shift_type: early ? 'early' : 'time',
                start_time: early ? null : replacementShiftKind,
                work_store_id: selectedStore.id,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setReplacementForStaff(null);
                    setReplacementStaffId('');
                    setReplacementShiftKind('');
                },
                onError: (errors) =>
                    toast.error(
                        String(
                            Object.values(errors)[0] ??
                                '代替スタッフの設定に失敗しました。',
                        ),
                    ),
                onFinish: () => {
                    savingRef.current = false;
                    setSavingReplacement(false);
                },
            },
        );
    };

    const remainingAddable = addableStaffs.filter(
        (staff) => !addedStaffIds.includes(staff.id),
    );
    const replacementCandidates: AddableAttendanceStaff[] = [
        ...staffs
            .filter((staff) => staff.source === 'unplanned')
            .map((staff) => ({
                id: staff.staff_id,
                name: staff.name,
                employment_type: staff.employment_type,
                employment_type_label: staff.employment_type_label,
                assignment_store_names: selectedStore
                    ? [selectedStore.name]
                    : [],
            })),
        ...remainingAddable,
    ];
    const previews = visibleStaffs.map((staff) =>
        calculateAttendancePreview(
            values[staff.staff_id] ?? emptyValue,
            staff.shift.start_offset_minutes,
        ),
    );
    const currentSummary = {
        attendanceCount: previews.filter((preview) => preview.valid).length,
        workingMinutes: previews.reduce(
            (total, preview) => total + preview.workingMinutes,
            0,
        ),
        lateNightMinutes: previews.reduce(
            (total, preview) => total + preview.lateNightMinutes,
            0,
        ),
    };

    return (
        <>
            <Head title="日次勤怠" />
            <div className="flex h-full min-w-0 flex-1 flex-col gap-5 p-4 pb-24 md:p-6 md:pb-6">
                <MasterPageHeader
                    title="日次勤怠"
                    description="時刻は15分単位で手入力できます。12:00を営業日の切替とし、0:00〜11:59は翌日の実日時として扱います。"
                />

                <section className="border-border bg-card grid gap-4 rounded-xl border p-4 shadow-sm sm:grid-cols-[minmax(180px,1fr)_minmax(170px,1fr)_auto] sm:items-end">
                    <div className="grid gap-2">
                        <Label htmlFor="attendance-store">店舗</Label>
                        <select
                            id="attendance-store"
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
                        <Label htmlFor="attendance-date">営業日</Label>
                        <Input
                            id="attendance-date"
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
                        店休日です。勤務実績は保存前の確認後に登録できます。
                    </div>
                )}

                {selectedStore && !selectedStore.is_active && (
                    <div className="border-border bg-muted text-muted-foreground rounded-lg border px-4 py-3 text-sm">
                        無効店舗では既存勤怠の修正・削除だけ行えます。
                    </div>
                )}

                {selectedStore && (
                    <section className="grid gap-3 sm:grid-cols-3">
                        <SummaryCard
                            label="出勤人数"
                            value={`${currentSummary.attendanceCount}人`}
                        />
                        <SummaryCard
                            label="実働合計"
                            value={formatMinutes(currentSummary.workingMinutes)}
                        />
                        <SummaryCard
                            label="深夜加算対象"
                            value={formatMinutes(
                                currentSummary.lateNightMinutes,
                            )}
                        />
                    </section>
                )}

                {selectedStore && remainingAddable.length > 0 && (
                    <section className="border-border bg-card flex flex-col gap-3 rounded-xl border p-4 shadow-sm sm:flex-row sm:items-end">
                        <div className="grid min-w-0 flex-1 gap-2">
                            <Label htmlFor="attendance-add-staff">
                                急な出勤のスタッフ
                            </Label>
                            <select
                                id="attendance-add-staff"
                                value={staffToAdd}
                                onChange={(event) =>
                                    setStaffToAdd(event.target.value)
                                }
                                className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                            >
                                <option value="">スタッフを選択</option>
                                {remainingAddable.map((staff) => (
                                    <option key={staff.id} value={staff.id}>
                                        {staff.name}（
                                        {staff.employment_type_label}・所属：
                                        {staff.assignment_store_names.join(
                                            ' / ',
                                        )}
                                        ）
                                    </option>
                                ))}
                            </select>
                        </div>
                        <Button
                            variant="outline"
                            disabled={!staffToAdd}
                            onClick={addStaff}
                        >
                            <Plus />
                            スタッフを追加
                        </Button>
                    </section>
                )}

                {!selectedStore ? (
                    <Empty message="勤怠を管理する店舗がありません。" />
                ) : visibleStaffs.length === 0 ? (
                    <Empty message="勤務シフトまたは登録済み勤怠がありません。上の「スタッフを追加」から急な出勤を登録できます。" />
                ) : (
                    <>
                        <div className="grid gap-3 lg:hidden">
                            {visibleStaffs.map((staff) => (
                                <AttendanceCard
                                    key={staff.staff_id}
                                    staff={staff}
                                    value={values[staff.staff_id] ?? emptyValue}
                                    onChange={(value) =>
                                        updateValue(staff.staff_id, value)
                                    }
                                    onDelete={() => deleteAttendance(staff)}
                                    onMarkAbsent={() => markAbsent(staff)}
                                    onAddReplacement={() =>
                                        openReplacement(staff)
                                    }
                                    replacementCandidatesAvailable={
                                        replacementCandidates.length > 0
                                    }
                                    markingAbsent={
                                        markingAbsentStaffId === staff.staff_id
                                    }
                                    deleting={
                                        deletingAttendanceId ===
                                        staff.attendance?.id
                                    }
                                    onRemove={() =>
                                        removeAddedStaff(staff.staff_id)
                                    }
                                />
                            ))}
                        </div>
                        <AttendanceTable
                            staffs={visibleStaffs}
                            values={values}
                            onChange={updateValue}
                            onDelete={deleteAttendance}
                            onMarkAbsent={markAbsent}
                            onAddReplacement={openReplacement}
                            replacementCandidatesAvailable={
                                replacementCandidates.length > 0
                            }
                            markingAbsentStaffId={markingAbsentStaffId}
                            deletingAttendanceId={deletingAttendanceId}
                            onRemove={removeAddedStaff}
                        />
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
                            disabled={!dirty || saving || !selectedStore}
                        >
                            <Save />
                            {saving ? '保存中…' : '一括保存'}
                        </Button>
                    </div>
                </div>

                <Dialog
                    open={replacementForStaff !== null}
                    onOpenChange={(open) => !open && closeReplacement()}
                >
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>代替スタッフを設定</DialogTitle>
                            <DialogDescription>
                                {replacementForStaff?.name}
                                さんの急な休みに代わって勤務するスタッフを選択します。
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 py-2">
                            <div className="grid gap-2">
                                <Label htmlFor="replacement-attendance-staff">
                                    代替スタッフ
                                </Label>
                                <select
                                    id="replacement-attendance-staff"
                                    value={replacementStaffId}
                                    onChange={(event) =>
                                        setReplacementStaffId(
                                            event.target.value,
                                        )
                                    }
                                    className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                                >
                                    <option value="">スタッフを選択</option>
                                    {replacementCandidates.map((staff) => (
                                        <option key={staff.id} value={staff.id}>
                                            {staff.name}（
                                            {staff.employment_type_label}
                                            ・所属：
                                            {staff.assignment_store_names.join(
                                                ' / ',
                                            )}
                                            ）
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="replacement-attendance-shift">
                                    勤務開始
                                </Label>
                                <select
                                    id="replacement-attendance-shift"
                                    value={replacementShiftKind}
                                    onChange={(event) =>
                                        setReplacementShiftKind(
                                            event.target.value,
                                        )
                                    }
                                    className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                                >
                                    <option value="">勤務開始を選択</option>
                                    {replacementShiftOptions.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <p className="text-muted-foreground text-xs">
                                他店所属者も選択できます。選択中店舗の勤務シフトとして登録され、日次勤怠に別行で表示されます。
                            </p>
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={savingReplacement}
                                onClick={closeReplacement}
                            >
                                キャンセル
                            </Button>
                            <Button
                                type="button"
                                disabled={
                                    savingReplacement ||
                                    !replacementStaffId ||
                                    !replacementShiftKind
                                }
                                onClick={saveReplacement}
                            >
                                <UserRoundPlus />
                                {savingReplacement ? '設定中…' : '設定する'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </>
    );
}

const emptyValue: AttendanceValue = {
    clock_in_offset_minutes: null,
    clock_out_offset_minutes: null,
};

const replacementShiftOptions = [
    ...Array.from({ length: 24 }, (_, hour) => {
        const time = `${String(hour).padStart(2, '0')}:00`;

        return { value: time, label: time };
    }),
    { value: 'early', label: '早番' },
];

function suddenStaff(staff: AddableAttendanceStaff): DailyAttendanceStaff {
    return {
        staff_id: staff.id,
        name: staff.name,
        employment_type: staff.employment_type,
        employment_type_label: staff.employment_type_label,
        source: 'sudden',
        eligible: true,
        editable: true,
        conflict_store: null,
        shift: { type: null, display: '急な出勤', start_offset_minutes: null },
        attendance: null,
    };
}

function AttendanceCard({
    staff,
    value,
    onChange,
    onDelete,
    onMarkAbsent,
    onAddReplacement,
    replacementCandidatesAvailable,
    markingAbsent,
    deleting,
    onRemove,
}: AttendanceRowProps) {
    const preview = calculateAttendancePreview(
        value,
        staff.shift.start_offset_minutes,
    );
    return (
        <article className="border-border bg-card rounded-xl border p-4 shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="font-semibold">{staff.name}</h2>
                    <p className="text-muted-foreground text-sm">
                        {staff.employment_type_label}・予定{' '}
                        {staff.shift.display}
                    </p>
                </div>
                <Badge
                    variant={
                        staff.shift.type === 'absence'
                            ? 'destructive'
                            : staff.source === 'sudden'
                              ? 'secondary'
                              : 'outline'
                    }
                >
                    {staff.shift.type === 'absence'
                        ? '急な休み'
                        : staff.source === 'sudden'
                          ? '急な出勤'
                          : staff.source === 'unplanned'
                            ? 'シフト未設定'
                            : 'シフトあり'}
                </Badge>
            </div>
            {staff.conflict_store ? (
                <div className="bg-destructive/10 text-destructive mt-4 rounded-md p-3 text-sm">
                    {staff.conflict_store}に同日の勤怠があります。
                </div>
            ) : (
                <div className="mt-4 grid grid-cols-2 gap-3">
                    <TimeInput
                        label="実出勤"
                        ariaLabel={`${staff.name}の実出勤`}
                        kind="clock-in"
                        value={value.clock_in_offset_minutes}
                        disabled={!staff.editable}
                        onChange={(next) =>
                            onChange({
                                ...value,
                                clock_in_offset_minutes: next,
                                clock_out_offset_minutes:
                                    next !== null &&
                                    value.clock_out_offset_minutes !== null &&
                                    value.clock_out_offset_minutes > next &&
                                    value.clock_out_offset_minutes - next <
                                        24 * 60
                                        ? value.clock_out_offset_minutes
                                        : null,
                            })
                        }
                    />
                    <TimeInput
                        label="実退勤"
                        ariaLabel={`${staff.name}の実退勤`}
                        kind="clock-out"
                        value={value.clock_out_offset_minutes}
                        clockInOffset={value.clock_in_offset_minutes}
                        disabled={
                            !staff.editable ||
                            value.clock_in_offset_minutes === null
                        }
                        onChange={(next) =>
                            onChange({
                                ...value,
                                clock_out_offset_minutes: next,
                            })
                        }
                    />
                </div>
            )}
            <AttendanceMetrics preview={preview} />
            {!staff.eligible && (
                <Warning message="対象日は在籍または店舗所属期間外です。既存勤怠だけ修正できます。" />
            )}
            {preview.warning && <Warning message={preview.warning} />}
            <RowAction
                staff={staff}
                onDelete={onDelete}
                onMarkAbsent={onMarkAbsent}
                onAddReplacement={onAddReplacement}
                replacementCandidatesAvailable={replacementCandidatesAvailable}
                markingAbsent={markingAbsent}
                deleting={deleting}
                onRemove={onRemove}
            />
        </article>
    );
}

type AttendanceRowProps = {
    staff: DailyAttendanceStaff;
    value: AttendanceValue;
    onChange: (value: AttendanceValue) => void;
    onDelete: () => void;
    onMarkAbsent: () => void;
    onAddReplacement: () => void;
    replacementCandidatesAvailable: boolean;
    markingAbsent: boolean;
    deleting: boolean;
    onRemove: () => void;
};

function AttendanceTable({
    staffs,
    values,
    onChange,
    onDelete,
    onMarkAbsent,
    onAddReplacement,
    replacementCandidatesAvailable,
    markingAbsentStaffId,
    deletingAttendanceId,
    onRemove,
}: {
    staffs: DailyAttendanceStaff[];
    values: AttendanceValues;
    onChange: (staffId: number, value: AttendanceValue) => void;
    onDelete: (staff: DailyAttendanceStaff) => void;
    onMarkAbsent: (staff: DailyAttendanceStaff) => void;
    onAddReplacement: (staff: DailyAttendanceStaff) => void;
    replacementCandidatesAvailable: boolean;
    markingAbsentStaffId: number | null;
    deletingAttendanceId: number | null;
    onRemove: (staffId: number) => void;
}) {
    return (
        <div className="border-border bg-card hidden overflow-x-auto rounded-xl border shadow-sm lg:block">
            <table className="w-full min-w-[1050px] text-sm">
                <thead className="bg-muted/70 text-muted-foreground">
                    <tr>
                        {[
                            'スタッフ',
                            '予定',
                            '実出勤',
                            '実退勤',
                            '実働',
                            '深夜加算対象',
                            '警告',
                            '操作',
                        ].map((label) => (
                            <th
                                key={label}
                                className="px-4 py-3 text-left font-medium"
                            >
                                {label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {staffs.map((staff) => {
                        const value = values[staff.staff_id] ?? emptyValue;
                        const preview = calculateAttendancePreview(
                            value,
                            staff.shift.start_offset_minutes,
                        );
                        return (
                            <tr key={staff.staff_id}>
                                <td className="px-4 py-3">
                                    <span className="font-medium">
                                        {staff.name}
                                    </span>
                                    <span className="text-muted-foreground block text-xs">
                                        {staff.employment_type_label}
                                    </span>
                                </td>
                                <td className="px-4 py-3">
                                    {staff.shift.display}
                                </td>
                                <td className="w-36 px-4 py-3">
                                    <TimeInput
                                        ariaLabel={`${staff.name}の実出勤`}
                                        kind="clock-in"
                                        value={value.clock_in_offset_minutes}
                                        disabled={!staff.editable}
                                        onChange={(next) =>
                                            onChange(staff.staff_id, {
                                                ...value,
                                                clock_in_offset_minutes: next,
                                                clock_out_offset_minutes:
                                                    next !== null &&
                                                    value.clock_out_offset_minutes !==
                                                        null &&
                                                    value.clock_out_offset_minutes >
                                                        next &&
                                                    value.clock_out_offset_minutes -
                                                        next <
                                                        24 * 60
                                                        ? value.clock_out_offset_minutes
                                                        : null,
                                            })
                                        }
                                    />
                                </td>
                                <td className="w-36 px-4 py-3">
                                    <TimeInput
                                        ariaLabel={`${staff.name}の実退勤`}
                                        kind="clock-out"
                                        value={value.clock_out_offset_minutes}
                                        clockInOffset={
                                            value.clock_in_offset_minutes
                                        }
                                        disabled={
                                            !staff.editable ||
                                            value.clock_in_offset_minutes ===
                                                null
                                        }
                                        onChange={(next) =>
                                            onChange(staff.staff_id, {
                                                ...value,
                                                clock_out_offset_minutes: next,
                                            })
                                        }
                                    />
                                </td>
                                <td className="px-4 py-3 whitespace-nowrap">
                                    {preview.valid
                                        ? formatMinutes(preview.workingMinutes)
                                        : '—'}
                                </td>
                                <td className="px-4 py-3 whitespace-nowrap">
                                    {preview.valid
                                        ? formatMinutes(
                                              preview.lateNightMinutes,
                                          )
                                        : '—'}
                                </td>
                                <td className="px-4 py-3 text-amber-700 dark:text-amber-300">
                                    {attendanceRowWarning(staff, preview)}
                                </td>
                                <td className="px-4 py-3">
                                    <RowAction
                                        staff={staff}
                                        onDelete={() => onDelete(staff)}
                                        onMarkAbsent={() => onMarkAbsent(staff)}
                                        onAddReplacement={() =>
                                            onAddReplacement(staff)
                                        }
                                        replacementCandidatesAvailable={
                                            replacementCandidatesAvailable
                                        }
                                        markingAbsent={
                                            markingAbsentStaffId ===
                                            staff.staff_id
                                        }
                                        deleting={
                                            deletingAttendanceId ===
                                            staff.attendance?.id
                                        }
                                        onRemove={() =>
                                            onRemove(staff.staff_id)
                                        }
                                    />
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function TimeInput({
    label,
    ariaLabel,
    kind,
    value,
    clockInOffset,
    disabled,
    onChange,
}: {
    label?: string;
    ariaLabel: string;
    kind: 'clock-in' | 'clock-out';
    value: number | null;
    clockInOffset?: number | null;
    disabled: boolean;
    onChange: (value: number | null) => void;
}) {
    return (
        <label className="grid gap-1.5 text-sm">
            {label && <span className="text-muted-foreground">{label}</span>}
            <input
                type="time"
                step={15 * 60}
                value={value === null ? '' : offsetInputValue(value)}
                disabled={disabled}
                aria-label={ariaLabel}
                onChange={(event) => {
                    const next = event.target.value;
                    if (next === '') {
                        onChange(null);
                        return;
                    }

                    if (kind === 'clock-in') {
                        onChange(clockInOffsetFromTimeInput(next));
                        return;
                    }

                    if (clockInOffset !== null && clockInOffset !== undefined) {
                        onChange(
                            clockOutOffsetFromTimeInput(next, clockInOffset),
                        );
                    }
                }}
                className="border-input bg-background h-10 rounded-md border px-2 text-sm invalid:border-red-500 disabled:opacity-60"
            />
        </label>
    );
}

function AttendanceMetrics({
    preview,
}: {
    preview: ReturnType<typeof calculateAttendancePreview>;
}) {
    return (
        <div className="bg-muted/60 mt-3 grid grid-cols-2 gap-3 rounded-lg p-3 text-sm">
            <span>
                実働{' '}
                <strong>
                    {preview.valid
                        ? formatMinutes(preview.workingMinutes)
                        : '—'}
                </strong>
            </span>
            <span>
                深夜{' '}
                <strong>
                    {preview.valid
                        ? formatMinutes(preview.lateNightMinutes)
                        : '—'}
                </strong>
            </span>
        </div>
    );
}

function Warning({ message }: { message: string }) {
    return (
        <div className="mt-3 flex gap-2 rounded-md bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
            <AlertTriangle className="size-4 shrink-0" />
            {message}
        </div>
    );
}

function RowAction({
    staff,
    onDelete,
    onMarkAbsent,
    onAddReplacement,
    replacementCandidatesAvailable,
    markingAbsent,
    deleting,
    onRemove,
}: {
    staff: DailyAttendanceStaff;
    onDelete: () => void;
    onMarkAbsent: () => void;
    onAddReplacement: () => void;
    replacementCandidatesAvailable: boolean;
    markingAbsent: boolean;
    deleting: boolean;
    onRemove: () => void;
}) {
    if (staff.attendance) {
        return (
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="text-destructive"
                disabled={deleting}
                onClick={onDelete}
            >
                <Trash2 />
                {deleting ? '削除中…' : '削除'}
            </Button>
        );
    }
    if (staff.source === 'sudden') {
        return (
            <Button type="button" variant="ghost" size="sm" onClick={onRemove}>
                追加を解除
            </Button>
        );
    }
    if (staff.shift.type === 'absence') {
        return (
            <div className="flex flex-wrap items-center gap-2">
                <Badge variant="destructive">急休登録済み</Badge>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={!replacementCandidatesAvailable}
                    onClick={onAddReplacement}
                >
                    <UserRoundPlus />
                    {replacementCandidatesAvailable
                        ? '代替を設定'
                        : '代替候補なし'}
                </Button>
            </div>
        );
    }
    if (canMarkAbsent(staff)) {
        return (
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="border-amber-500/60 text-amber-700 dark:text-amber-300"
                disabled={markingAbsent}
                onClick={onMarkAbsent}
            >
                <CalendarOff />
                {markingAbsent ? '変更中…' : '急休にする'}
            </Button>
        );
    }
    return <span className="text-muted-foreground text-xs">—</span>;
}

function canMarkAbsent(staff: DailyAttendanceStaff) {
    return (
        staff.source === 'scheduled' &&
        (staff.shift.type === 'time' ||
            staff.shift.type === 'early' ||
            staff.shift.type === 'help') &&
        staff.attendance === null &&
        staff.editable
    );
}

function SummaryCard({ label, value }: { label: string; value: string }) {
    return (
        <div className="border-border bg-card rounded-xl border p-4 shadow-sm">
            <p className="text-muted-foreground text-xs">{label}</p>
            <p className="mt-1 text-xl font-semibold tabular-nums">{value}</p>
        </div>
    );
}

function formatMinutes(minutes: number) {
    if (minutes === 0) return '0分';
    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;
    return `${hours > 0 ? `${hours}時間` : ''}${remainder > 0 ? `${remainder}分` : ''}`;
}

function attendanceRowWarning(
    staff: DailyAttendanceStaff,
    preview: ReturnType<typeof calculateAttendancePreview>,
) {
    if (staff.conflict_store) {
        return `${staff.conflict_store}に登録済み`;
    }
    if (!staff.eligible) {
        return '在籍・所属期間外（既存勤怠のみ修正可）';
    }
    if (staff.shift.type === 'absence') {
        return '急な休み';
    }
    if (staff.source === 'unplanned') {
        return 'シフト未設定';
    }

    return preview.warning ?? '—';
}

function Empty({ message }: { message: string }) {
    return (
        <div className="border-border bg-card text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">
            <Clock3 className="mx-auto mb-3 size-6" />
            {message}
        </div>
    );
}

DailyAttendance.layout = {
    breadcrumbs: [{ title: '日次勤怠', href: '/attendance/daily' }],
};
