import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    BarChart3,
    CalendarDays,
    Clock3,
    Store,
    UsersRound,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import type { StoreOption } from '@/types';

type Props = {
    stores: StoreOption[];
    selected_store: StoreOption | null;
    today: string;
    today_label: string;
    today_shift_count: number;
    attendance_missing_count: number;
    today_assigned_count: number;
    today_assigned_working_count: number;
    today_help_count: number;
    today_off_count: number;
    today_other_store_count: number;
    today_unscheduled_count: number;
};

export default function Dashboard({
    stores,
    selected_store: selectedStore,
    today,
    today_label: todayLabel,
    today_shift_count: todayShiftCount,
    attendance_missing_count: attendanceMissingCount,
    today_assigned_count: todayAssignedCount,
    today_assigned_working_count: todayAssignedWorkingCount,
    today_help_count: todayHelpCount,
    today_off_count: todayOffCount,
    today_other_store_count: todayOtherStoreCount,
    today_unscheduled_count: todayUnscheduledCount,
}: Props) {
    const [selectingStore, setSelectingStore] = useState(false);

    const selectStore = (storeId: string) => {
        setSelectingStore(true);
        router.put(
            '/selected-store',
            { store_id: Number(storeId) },
            {
                preserveScroll: true,
                onFinish: () => setSelectingStore(false),
            },
        );
    };

    return (
        <>
            <Head title="ダッシュボード" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <header>
                    <p className="text-muted-foreground text-sm">
                        株式会社Groovy
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        ダッシュボード
                    </h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        {todayLabel}
                    </p>
                </header>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <DashboardCard title="本日の勤務予定" icon={UsersRound}>
                        <p className="mt-2 text-2xl font-semibold tabular-nums">
                            {selectedStore ? `${todayShiftCount}人` : '—'}
                        </p>
                        {selectedStore ? (
                            <>
                                <p className="text-muted-foreground mt-2 text-xs leading-5">
                                    所属{todayAssignedCount}人：自店勤務
                                    {todayAssignedWorkingCount}人・休み
                                    {todayOffCount}人・未設定
                                    {todayUnscheduledCount}人
                                    {todayOtherStoreCount > 0 &&
                                        `・他店勤務${todayOtherStoreCount}人`}
                                    {todayHelpCount > 0 &&
                                        `／ヘルプ${todayHelpCount}人を含む`}
                                </p>
                                <Button
                                    variant="link"
                                    className="mt-2 h-auto p-0"
                                    asChild
                                >
                                    <Link
                                        href={`/shifts/daily?store_id=${selectedStore.id}&date=${today}`}
                                    >
                                        日別シフトを確認
                                        <ArrowRight />
                                    </Link>
                                </Button>
                            </>
                        ) : (
                            <p className="text-muted-foreground mt-4 text-sm">
                                利用できる店舗がありません。
                            </p>
                        )}
                    </DashboardCard>

                    <DashboardCard title="勤怠未入力" icon={Clock3}>
                        <p className="mt-2 text-2xl font-semibold tabular-nums">
                            {selectedStore
                                ? `${attendanceMissingCount}人`
                                : '—'}
                        </p>
                        {selectedStore ? (
                            <>
                                <p className="text-muted-foreground mt-2 text-xs">
                                    本日の勤務予定者のうち、勤怠が未入力の人数です。
                                </p>
                                <Button
                                    variant="link"
                                    className="mt-2 h-auto p-0"
                                    asChild
                                >
                                    <Link
                                        href={`/attendance/daily?store_id=${selectedStore.id}&date=${today}`}
                                    >
                                        日次勤怠を入力
                                        <ArrowRight />
                                    </Link>
                                </Button>
                            </>
                        ) : (
                            <p className="text-muted-foreground mt-4 text-sm">
                                利用できる店舗がありません。
                            </p>
                        )}
                    </DashboardCard>

                    <DashboardCard title="選択店舗" icon={Store}>
                        {stores.length === 0 ? (
                            <>
                                <p className="mt-2 text-2xl font-semibold">
                                    未選択
                                </p>
                                <p className="text-muted-foreground mt-4 text-sm">
                                    有効な店舗がありません。
                                </p>
                            </>
                        ) : (
                            <>
                                <label
                                    htmlFor="dashboard-store"
                                    className="sr-only"
                                >
                                    選択店舗
                                </label>
                                <select
                                    id="dashboard-store"
                                    aria-label="選択店舗"
                                    value={selectedStore?.id ?? ''}
                                    disabled={selectingStore}
                                    onChange={(event) =>
                                        selectStore(event.target.value)
                                    }
                                    className="border-input bg-background mt-3 h-10 w-full rounded-md border px-3 text-sm font-medium disabled:opacity-60"
                                >
                                    {stores.map((store) => (
                                        <option key={store.id} value={store.id}>
                                            {store.name}
                                        </option>
                                    ))}
                                </select>
                                <p className="text-muted-foreground mt-3 text-sm">
                                    シフト画面の初期店舗にも反映されます。
                                </p>
                            </>
                        )}
                    </DashboardCard>
                </div>

                <section className="border-border bg-card rounded-xl border p-5 shadow-sm">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-start gap-3">
                            <CalendarDays className="text-primary mt-0.5 size-5 shrink-0" />
                            <div>
                                <h2 className="font-semibold">シフト管理</h2>
                                <p className="text-muted-foreground mt-1 text-sm">
                                    {selectedStore
                                        ? `${selectedStore.name}の月間シフトを確認・編集できます。`
                                        : '店舗を登録するとシフト管理を開始できます。'}
                                </p>
                            </div>
                        </div>
                        {selectedStore && (
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <Button variant="outline" asChild>
                                    <Link
                                        href={`/attendance/daily?store_id=${selectedStore.id}&date=${today}`}
                                    >
                                        今日の勤怠
                                        <Clock3 />
                                    </Link>
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link
                                        href={`/aggregations?store_id=${selectedStore.id}&year=${today.slice(0, 4)}&month=${Number(today.slice(5, 7))}`}
                                    >
                                        月次集計
                                        <BarChart3 />
                                    </Link>
                                </Button>
                                <Button asChild>
                                    <Link
                                        href={`/shifts/monthly?store_id=${selectedStore.id}&month=${today.slice(0, 7)}`}
                                    >
                                        月間シフトを開く
                                        <ArrowRight />
                                    </Link>
                                </Button>
                            </div>
                        )}
                    </div>
                </section>
            </div>
        </>
    );
}

function DashboardCard({
    title,
    icon: Icon,
    children,
}: {
    title: string;
    icon: typeof UsersRound;
    children: React.ReactNode;
}) {
    return (
        <section className="border-border bg-card rounded-xl border p-5 shadow-sm">
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0 flex-1">
                    <p className="text-muted-foreground text-sm font-medium">
                        {title}
                    </p>
                    {children}
                </div>
                <div className="bg-primary/10 text-primary shrink-0 rounded-lg p-2.5">
                    <Icon className="size-5" />
                </div>
            </div>
        </section>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'ダッシュボード',
            href: dashboard(),
        },
    ],
};
