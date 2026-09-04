import { Head, router } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';
import FileDownloadButton from '@/components/file-download-button';
import MasterPageHeader from '@/components/master-page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatMinutes, yen } from '@/lib/payroll-presentation';
import type {
    CrossStoreAggregationRow,
    DailyAggregationGroup,
    StoreAggregationRow,
} from '@/types';

type StoreOption = { id: number; name: string };
type Totals = {
    attendance_days: number;
    working_minutes: number;
    late_night_minutes: number;
    base_pay: number;
    late_night_pay: number;
    transportation_fee: number;
    labor_cost: number;
};
type Props = {
    year: number;
    month: number;
    previous_period: string;
    next_period: string;
    stores: StoreOption[];
    selected_store: StoreOption | null;
    store_rows: StoreAggregationRow[];
    store_totals: Totals;
    daily_groups: DailyAggregationGroup[];
    cross_store_rows: CrossStoreAggregationRow[];
};

export default function AggregationIndex(props: Props) {
    const move = (period: string, storeId = props.selected_store?.id) => {
        const [year, month] = period.split('-').map(Number);
        router.get('/aggregations', { year, month, store_id: storeId });
    };
    const period = `${props.year}-${String(props.month).padStart(2, '0')}`;
    const exportQuery = new URLSearchParams({
        year: String(props.year),
        month: String(props.month),
        ...(props.selected_store
            ? { store_id: String(props.selected_store.id) }
            : {}),
    });

    return (
        <>
            <Head title="月次集計" />
            <div className="flex h-full min-w-0 flex-1 flex-col gap-5 p-4 md:p-6">
                <MasterPageHeader
                    title="月次集計"
                    description="店舗別人件費、日別人件費、全店舗横断の勤務時間を同じ計算結果から確認します。"
                />
                <section className="border-border bg-card flex flex-col gap-4 rounded-xl border p-4 shadow-sm lg:flex-row lg:items-end lg:justify-between">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div className="grid gap-2">
                            <Label htmlFor="aggregation-store">店舗</Label>
                            <select
                                id="aggregation-store"
                                value={props.selected_store?.id ?? ''}
                                disabled={props.stores.length === 0}
                                onChange={(event) =>
                                    move(period, Number(event.target.value))
                                }
                                className="border-input bg-background h-9 min-w-48 rounded-md border px-3 text-sm"
                            >
                                {props.stores.length === 0 && (
                                    <option value="">店舗なし</option>
                                )}
                                {props.stores.map((store) => (
                                    <option key={store.id} value={store.id}>
                                        {store.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="aggregation-period">対象月</Label>
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="icon"
                                    aria-label="前月"
                                    onClick={() => move(props.previous_period)}
                                >
                                    <ArrowLeft />
                                </Button>
                                <Input
                                    id="aggregation-period"
                                    type="month"
                                    className="w-44"
                                    value={period}
                                    onChange={(event) =>
                                        move(event.target.value)
                                    }
                                />
                                <Button
                                    variant="outline"
                                    size="icon"
                                    aria-label="翌月"
                                    onClick={() => move(props.next_period)}
                                >
                                    <ArrowRight />
                                </Button>
                            </div>
                        </div>
                    </div>
                    <FileDownloadButton
                        variant="outline"
                        url={`/aggregations.xlsx?${exportQuery}`}
                        label="XLSX出力"
                        fallbackFilename={`${props.year}年${String(props.month).padStart(2, '0')}月_勤怠人件費集計.xlsx`}
                    />
                </section>

                <Section
                    title={`${props.selected_store?.name ?? '店舗未選択'} 店舗別月次集計`}
                    description="スタッフ単位で丸め前金額を合算し、最後に1円未満を切り上げています。歩合は含みません。"
                >
                    <CostTable
                        rows={props.store_rows}
                        totals={props.store_totals}
                    />
                </Section>

                <Section
                    title="日別人件費"
                    description="営業日ごとに、当日の丸め前金額を最後に切り上げています。"
                >
                    {props.daily_groups.length === 0 ? (
                        <Empty />
                    ) : (
                        <div className="grid gap-3">
                            {props.daily_groups.map((group) => (
                                <details
                                    key={group.date}
                                    className="border-border rounded-lg border"
                                >
                                    <summary className="hover:bg-muted cursor-pointer px-4 py-3 font-medium">
                                        {dateLabel(group.date)}・
                                        {group.rows.length}人・
                                        {yen(group.totals.labor_cost)}
                                    </summary>
                                    <div className="p-3 pt-0">
                                        <CostTable
                                            rows={group.rows}
                                            totals={group.totals as Totals}
                                        />
                                    </div>
                                </details>
                            ))}
                        </div>
                    )}
                </Section>

                <Section
                    title="全店舗横断スタッフ集計"
                    description="社員を含む全スタッフの店舗別勤務時間です。アルバイト給与は保存済みの正式月次給与を表示します。"
                >
                    <CrossStoreTable
                        rows={props.cross_store_rows}
                        stores={props.stores}
                    />
                </Section>
            </div>
        </>
    );
}

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: React.ReactNode;
}) {
    return (
        <section className="border-border bg-card min-w-0 rounded-xl border shadow-sm">
            <div className="border-border border-b p-4">
                <h2 className="font-semibold">{title}</h2>
                <p className="text-muted-foreground mt-1 text-sm">
                    {description}
                </p>
            </div>
            <div className="p-4">{children}</div>
        </section>
    );
}

function CostTable({
    rows,
    totals,
}: {
    rows: StoreAggregationRow[];
    totals: Totals;
}) {
    if (rows.length === 0) return <Empty />;
    const headings = [
        'スタッフ',
        '区分',
        '出勤',
        '勤務時間',
        '深夜時間',
        '基本給相当',
        '深夜手当',
        '交通費',
        '人件費',
    ];

    return (
        <div className="max-w-full overflow-x-auto">
            <table className="w-full min-w-[980px] text-sm">
                <thead className="bg-muted text-muted-foreground">
                    <tr>
                        {headings.map((label) => (
                            <th
                                key={label}
                                className="px-3 py-2 text-right first:text-left"
                            >
                                {label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={row.staff_id}
                            className="border-border border-b"
                        >
                            <td className="px-3 py-3 font-medium">
                                {row.name}
                            </td>
                            <td className="px-3 py-3 text-right">
                                {row.employment_type_label}
                            </td>
                            <td className="px-3 py-3 text-right tabular-nums">
                                {row.attendance_days}日
                            </td>
                            <td className="px-3 py-3 text-right tabular-nums">
                                {formatMinutes(row.working_minutes)}
                            </td>
                            <td className="px-3 py-3 text-right tabular-nums">
                                {row.employment_type === 'part_time'
                                    ? formatMinutes(row.late_night_minutes)
                                    : '対象外'}
                            </td>
                            <Money value={row.base_pay} />
                            <Money value={row.late_night_pay} />
                            <Money value={row.transportation_fee} />
                            <Money value={row.labor_cost} strong />
                        </tr>
                    ))}
                </tbody>
                <tfoot className="bg-muted/60 font-semibold">
                    <tr>
                        <td className="px-3 py-3" colSpan={2}>
                            合計
                        </td>
                        <td className="px-3 py-3 text-right">
                            {totals.attendance_days}件
                        </td>
                        <td className="px-3 py-3 text-right">
                            {formatMinutes(totals.working_minutes)}
                        </td>
                        <td className="px-3 py-3 text-right">
                            {formatMinutes(totals.late_night_minutes)}
                        </td>
                        <td className="px-3 py-3 text-right">
                            {yen(totals.base_pay)}
                        </td>
                        <td className="px-3 py-3 text-right">
                            {yen(totals.late_night_pay)}
                        </td>
                        <td className="px-3 py-3 text-right">
                            {yen(totals.transportation_fee)}
                        </td>
                        <td className="px-3 py-3 text-right">
                            {yen(totals.labor_cost)}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}

function Money({
    value,
    strong = false,
}: {
    value: number | null;
    strong?: boolean;
}) {
    return (
        <td
            className={`px-3 py-3 text-right tabular-nums ${strong ? 'font-semibold' : ''}`}
        >
            {value === null ? '対象外' : yen(value)}
        </td>
    );
}

function CrossStoreTable({
    rows,
    stores,
}: {
    rows: CrossStoreAggregationRow[];
    stores: StoreOption[];
}) {
    if (rows.length === 0) return <Empty />;
    return (
        <div className="max-w-full overflow-x-auto">
            <table className="w-full min-w-[900px] text-sm">
                <thead className="bg-muted text-muted-foreground">
                    <tr>
                        <th className="px-3 py-2 text-left">スタッフ</th>
                        <th className="px-3 py-2 text-left">区分</th>
                        {stores.map((store) => (
                            <th key={store.id} className="px-3 py-2 text-right">
                                {store.name}
                            </th>
                        ))}
                        <th className="px-3 py-2 text-right">総勤務時間</th>
                        <th className="px-3 py-2 text-right">正式総支給額</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr
                            key={row.staff_id}
                            className="border-border border-b"
                        >
                            <td className="px-3 py-3 font-medium">
                                {row.name}
                            </td>
                            <td className="px-3 py-3">
                                {row.employment_type_label}
                            </td>
                            {stores.map((store) => {
                                const minutes =
                                    row.store_minutes.find(
                                        (item) => item.store_id === store.id,
                                    )?.working_minutes ?? 0;
                                return (
                                    <td
                                        key={store.id}
                                        className="px-3 py-3 text-right tabular-nums"
                                    >
                                        {formatMinutes(minutes)}
                                    </td>
                                );
                            })}
                            <td className="px-3 py-3 text-right font-semibold tabular-nums">
                                {formatMinutes(row.working_minutes)}
                            </td>
                            <td className="px-3 py-3 text-right tabular-nums">
                                {row.employment_type === 'employee'
                                    ? '対象外'
                                    : row.payroll === null
                                      ? '未計算'
                                      : `${yen(row.payroll.gross_pay)}${row.payroll.needs_recalculation ? '（要再計算）' : ''}`}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function Empty() {
    return (
        <p className="text-muted-foreground py-8 text-center text-sm">
            対象月の勤怠実績はありません。
        </p>
    );
}

function dateLabel(date: string) {
    const parsed = new Date(`${date}T00:00:00`);
    return `${parsed.getMonth() + 1}月${parsed.getDate()}日（${['日', '月', '火', '水', '木', '金', '土'][parsed.getDay()]}）`;
}
