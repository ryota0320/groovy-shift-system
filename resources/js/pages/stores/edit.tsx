import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, CalendarPlus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import MasterPageHeader from '@/components/master-page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Store = {
    id: number;
    name: string;
    opening_time: string;
    closing_time: string;
    is_active: boolean;
    holidays: Array<{ id: number; holiday_date: string }>;
};

type Props = {
    store: Store;
    holiday_month: string;
    holiday_month_label: string;
    holiday_month_end: string;
    previous_holiday_month: string;
    next_holiday_month: string;
};

export default function StoreEdit({
    store,
    holiday_month: holidayMonth,
    holiday_month_label: holidayMonthLabel,
    holiday_month_end: holidayMonthEnd,
    previous_holiday_month: previousHolidayMonth,
    next_holiday_month: nextHolidayMonth,
}: Props) {
    const [removingHolidayId, setRemovingHolidayId] = useState<number | null>(
        null,
    );

    const moveHolidayMonth = (month: string) => {
        router.get(
            `/stores/${store.id}/edit`,
            { holiday_month: month },
            { preserveScroll: true, preserveState: false, replace: true },
        );
    };

    const removeHoliday = (holidayId: number) => {
        if (
            removingHolidayId === null &&
            window.confirm('この店休日を削除しますか？')
        ) {
            setRemovingHolidayId(holidayId);
            router.delete(
                `/stores/${store.id}/holidays/${holidayId}?holiday_month=${holidayMonth}`,
                {
                    preserveScroll: true,
                    onError: () =>
                        toast.error(
                            '店休日を削除できませんでした。再試行してください。',
                        ),
                    onFinish: () => setRemovingHolidayId(null),
                },
            );
        }
    };

    return (
        <>
            <Head title={`${store.name} - 店舗編集`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    title={store.name}
                    description="店舗情報、開店・閉店時間、店休日を管理します。無効化しても過去データは保持されます。"
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/stores">
                                <ArrowLeft />
                                店舗一覧へ
                            </Link>
                        </Button>
                    }
                />

                <div className="grid gap-6 xl:grid-cols-2">
                    <section className="border-border bg-card rounded-xl border p-5 shadow-sm">
                        <h2 className="font-semibold">基本情報</h2>
                        <Form
                            action={`/stores/${store.id}`}
                            method="put"
                            options={{ preserveScroll: true }}
                            className="mt-4 space-y-5"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">店舗名</Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            defaultValue={store.name}
                                            required
                                        />
                                        <InputError message={errors.name} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="opening-time">
                                            開店時間
                                        </Label>
                                        <Input
                                            id="opening-time"
                                            name="opening_time"
                                            type="time"
                                            step="60"
                                            defaultValue={store.opening_time}
                                            required
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            シフトの勤務開始時刻は、この時刻以降から選択できます。
                                        </p>
                                        <InputError
                                            message={errors.opening_time}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="closing-time">
                                            閉店時間
                                        </Label>
                                        <Input
                                            id="closing-time"
                                            name="closing_time"
                                            type="time"
                                            step="60"
                                            defaultValue={store.closing_time}
                                            required
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            開店時間より早い時刻は、翌日の閉店時間として扱います。
                                        </p>
                                        <InputError
                                            message={errors.closing_time}
                                        />
                                    </div>
                                    <input
                                        type="hidden"
                                        name="is_active"
                                        value="0"
                                    />
                                    <label className="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border p-3">
                                        <Checkbox
                                            name="is_active"
                                            value="1"
                                            defaultChecked={store.is_active}
                                        />
                                        <span>
                                            <span className="block text-sm font-medium">
                                                有効な店舗
                                            </span>
                                            <span className="text-muted-foreground block text-xs">
                                                無効にすると新規入力の候補から除外します。
                                            </span>
                                        </span>
                                    </label>
                                    <Button disabled={processing}>
                                        更新する
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>

                    <section className="border-border bg-card rounded-xl border p-5 shadow-sm">
                        <h2 className="flex items-center gap-2 font-semibold">
                            <CalendarPlus className="text-primary size-5" />
                            店休日
                        </h2>

                        <div className="bg-muted/40 mt-4 rounded-lg border p-3">
                            <Label htmlFor="holiday-month">対象年月</Label>
                            <div className="mt-2 grid grid-cols-[auto_minmax(0,1fr)_auto] gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    aria-label="前月の店休日を表示"
                                    onClick={() =>
                                        moveHolidayMonth(previousHolidayMonth)
                                    }
                                >
                                    <ArrowLeft />
                                </Button>
                                <Input
                                    id="holiday-month"
                                    type="month"
                                    value={holidayMonth}
                                    onChange={(event) =>
                                        moveHolidayMonth(event.target.value)
                                    }
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    aria-label="翌月の店休日を表示"
                                    onClick={() =>
                                        moveHolidayMonth(nextHolidayMonth)
                                    }
                                >
                                    <ArrowRight />
                                </Button>
                            </div>
                        </div>

                        <div className="mt-5 flex items-center justify-between gap-3">
                            <h3 className="font-medium">
                                {holidayMonthLabel}に追加
                            </h3>
                            <span className="text-muted-foreground text-sm">
                                登録済み {store.holidays.length}件
                            </span>
                        </div>
                        <Form
                            action={`/stores/${store.id}/holidays`}
                            method="post"
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                            className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="holiday_month"
                                        value={holidayMonth}
                                    />
                                    <div className="grid flex-1 gap-2">
                                        <Label htmlFor="holiday-date">
                                            日付
                                        </Label>
                                        <Input
                                            id="holiday-date"
                                            name="holiday_date"
                                            type="date"
                                            min={`${holidayMonth}-01`}
                                            max={holidayMonthEnd}
                                            required
                                        />
                                        <InputError
                                            message={errors.holiday_date}
                                        />
                                    </div>
                                    <Button
                                        disabled={processing}
                                        className="sm:mt-6"
                                    >
                                        追加する
                                    </Button>
                                </>
                            )}
                        </Form>

                        <div className="mt-6 space-y-2">
                            {store.holidays.length === 0 ? (
                                <p className="text-muted-foreground rounded-lg border border-dashed p-6 text-center text-sm">
                                    {holidayMonthLabel}
                                    の店休日は登録されていません。
                                </p>
                            ) : (
                                store.holidays.map((holiday) => (
                                    <div
                                        key={holiday.id}
                                        className="flex items-center justify-between rounded-lg border px-3 py-2"
                                    >
                                        <time className="font-medium">
                                            {holiday.holiday_date}
                                        </time>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`${holiday.holiday_date}を削除`}
                                            disabled={
                                                removingHolidayId !== null
                                            }
                                            onClick={() =>
                                                removeHoliday(holiday.id)
                                            }
                                        >
                                            <Trash2 className="text-destructive" />
                                        </Button>
                                    </div>
                                ))
                            )}
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}

StoreEdit.layout = {
    breadcrumbs: [
        { title: '店舗管理', href: '/stores' },
        { title: '店舗編集', href: '#' },
    ],
};
