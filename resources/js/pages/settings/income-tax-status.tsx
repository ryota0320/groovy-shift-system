import { Head } from '@inertiajs/react';
import {
    CalendarClock,
    CheckCircle2,
    CircleHelp,
    Database,
    ExternalLink,
    FileClock,
    ReceiptText,
    TriangleAlert,
} from 'lucide-react';
import MasterPageHeader from '@/components/master-page-header';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

type TaxTable = {
    tax_year: number;
    name: string;
    source_url: string;
    source_hash: string;
    imported_at: string | null;
    rules_count: number;
};

type RetrievalStatus =
    | 'applied'
    | 'review_required'
    | 'not_published'
    | 'not_checked'
    | 'error';

type Props = {
    current_tax_year: number;
    current_table: TaxTable | null;
    retrieval: {
        target_year: number;
        status: RetrievalStatus;
        raw_status: string;
        checked_at: string | null;
        source_page_url: string | null;
        source_url: string | null;
        source_hash: string | null;
        error_message: string | null;
    };
    table_versions: TaxTable[];
    schedule: {
        period: string;
        time: string;
        timezone: string;
    };
};

const statusDetails: Record<
    RetrievalStatus,
    {
        label: string;
        description: string;
        icon: typeof CheckCircle2;
        className: string;
    }
> = {
    applied: {
        label: '反映済み',
        description:
            '取得した公式ファイルと、給与計算へ投入済みの税額表が一致しています。',
        icon: CheckCircle2,
        className: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-500',
    },
    review_required: {
        label: '開発管理者の確認待ち',
        description:
            '新しい公式ファイルを取得済みです。確認とテストが完了するまで給与計算には反映されません。',
        icon: FileClock,
        className: 'border-amber-500/30 bg-amber-500/10 text-amber-500',
    },
    not_published: {
        label: '未公開',
        description: '対象年度の月額表は、国税庁でまだ公開されていません。',
        icon: CircleHelp,
        className: 'border-sky-500/30 bg-sky-500/10 text-sky-500',
    },
    not_checked: {
        label: '未確認',
        description: '対象年度の自動取得結果はまだ記録されていません。',
        icon: CircleHelp,
        className: 'border-muted-foreground/30 bg-muted text-muted-foreground',
    },
    error: {
        label: '取得エラー',
        description:
            '公式ページまたはExcelを正常に検証できませんでした。給与計算への反映は行われていません。',
        icon: TriangleAlert,
        className: 'border-destructive/30 bg-destructive/10 text-destructive',
    },
};

export default function IncomeTaxStatus({
    current_tax_year,
    current_table,
    retrieval,
    table_versions,
    schedule,
}: Props) {
    const status = statusDetails[retrieval.status];
    const StatusIcon = status.icon;

    return (
        <>
            <Head title="所得税額表状況" />
            <div className="flex h-full min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    title="所得税額表状況"
                    description="給与計算へ適用中の税額表と、国税庁からの翌年分取得状況を確認します。"
                />

                <div className="grid gap-4 xl:grid-cols-3">
                    <Card data-testid="current-income-tax-table">
                        <CardHeader>
                            <CardDescription className="flex items-center gap-2">
                                <ReceiptText className="text-primary size-4" />
                                現在の適用年度
                            </CardDescription>
                            <CardTitle className="text-3xl">
                                {current_tax_year}年分
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {current_table ? (
                                <>
                                    <Badge className="border-emerald-500/30 bg-emerald-500/10 text-emerald-500">
                                        <CheckCircle2 />
                                        利用可能
                                    </Badge>
                                    <p className="text-sm font-medium">
                                        {current_table.name}
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        給与支給日の年を基準に、この年度の税額表を使用します。
                                    </p>
                                </>
                            ) : (
                                <div className="text-destructive space-y-2 text-sm">
                                    <Badge variant="destructive">
                                        <TriangleAlert />
                                        未投入
                                    </Badge>
                                    <p>
                                        現在年の税額表がないため、対象給与は計算できません。
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card data-testid="latest-income-tax-status">
                        <CardHeader>
                            <CardDescription className="flex items-center gap-2">
                                <Database className="text-primary size-4" />
                                {retrieval.target_year}年分の取得状況
                            </CardDescription>
                            <CardTitle>
                                <Badge
                                    variant="outline"
                                    className={status.className}
                                >
                                    <StatusIcon />
                                    {status.label}
                                </Badge>
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p>{status.description}</p>
                            <InfoRow
                                label="最終確認"
                                value={retrieval.checked_at ?? '記録なし'}
                            />
                            {retrieval.source_page_url && (
                                <a
                                    href={retrieval.source_page_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="text-primary inline-flex items-center gap-1 hover:underline"
                                >
                                    国税庁の対象年度ページ
                                    <ExternalLink className="size-3.5" />
                                </a>
                            )}
                            {retrieval.error_message && (
                                <p className="border-destructive/30 bg-destructive/10 text-destructive rounded-lg border p-3 text-xs">
                                    {retrieval.error_message}
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardDescription className="flex items-center gap-2">
                                <CalendarClock className="text-primary size-4" />
                                自動確認スケジュール
                            </CardDescription>
                            <CardTitle>{schedule.period}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <InfoRow label="実行時刻" value={schedule.time} />
                            <InfoRow
                                label="タイムゾーン"
                                value={schedule.timezone}
                            />
                            <p className="text-muted-foreground text-xs">
                                取得結果は確認待ちとして保存され、自動では給与計算へ反映されません。
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>給与計算へ投入済みの税額表</CardTitle>
                        <CardDescription>
                            年度ごとの公式ファイルと税額ルール件数を確認できます。
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {table_versions.length === 0 ? (
                            <p className="text-muted-foreground rounded-lg border border-dashed p-8 text-center text-sm">
                                投入済みの所得税額表はありません。
                            </p>
                        ) : (
                            <div className="space-y-3">
                                {table_versions.map((table) => (
                                    <article
                                        key={table.tax_year}
                                        className="grid gap-4 rounded-lg border p-4 lg:grid-cols-[140px_minmax(0,1fr)_180px] lg:items-center"
                                    >
                                        <div>
                                            <p className="text-xl font-semibold">
                                                {table.tax_year}年分
                                            </p>
                                            {table.tax_year ===
                                                current_tax_year && (
                                                <Badge className="mt-2">
                                                    現在年度
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="min-w-0 space-y-2">
                                            <p className="font-medium">
                                                {table.name}
                                            </p>
                                            <p className="text-muted-foreground font-mono text-xs break-all">
                                                SHA-256: {table.source_hash}
                                            </p>
                                            <a
                                                href={table.source_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="text-primary inline-flex items-center gap-1 text-sm hover:underline"
                                            >
                                                公式Excel
                                                <ExternalLink className="size-3.5" />
                                            </a>
                                        </div>
                                        <dl className="grid grid-cols-2 gap-3 text-sm lg:grid-cols-1">
                                            <InfoRow
                                                label="ルール件数"
                                                value={`${table.rules_count.toLocaleString('ja-JP')}件`}
                                            />
                                            <InfoRow
                                                label="投入日時"
                                                value={
                                                    table.imported_at ??
                                                    '記録なし'
                                                }
                                            />
                                        </dl>
                                    </article>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

function InfoRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-baseline justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right font-medium">{value}</span>
        </div>
    );
}
