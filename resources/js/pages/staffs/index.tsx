import { Form, Head, Link } from '@inertiajs/react';
import { FileUp, Plus, Search, Store as StoreIcon } from 'lucide-react';
import MasterPageHeader from '@/components/master-page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Staff = {
    id: number;
    name: string;
    display_name: string | null;
    employment_type: 'employee' | 'part_time';
    employment_type_label: string;
    is_employed: boolean;
    stores: string[];
    hourly_wage: number | null;
};

type Filters = {
    employment_type: string;
    status: string;
    search: string;
};

type StaffPagination = {
    data: Staff[];
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const yen = new Intl.NumberFormat('ja-JP');

export default function StaffIndex({
    staffs,
    filters,
}: {
    staffs: StaffPagination;
    filters: Filters;
}) {
    return (
        <>
            <Head title="スタッフ管理" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    title="スタッフ管理"
                    description="本日時点の在籍、所属店舗、時給を確認できます。"
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <Link href="/staffs-import">
                                    <FileUp />
                                    初期移行
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link href="/staffs/create">
                                    <Plus />
                                    スタッフを追加
                                </Link>
                            </Button>
                        </div>
                    }
                />

                <Form
                    action="/staffs"
                    method="get"
                    className="border-border bg-card grid gap-4 rounded-xl border p-4 shadow-sm md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="search">氏名・表示名検索</Label>
                        <Input
                            id="search"
                            name="search"
                            defaultValue={filters.search}
                            placeholder="氏名または表示名を入力"
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="employment-type">雇用区分</Label>
                        <select
                            id="employment-type"
                            name="employment_type"
                            defaultValue={filters.employment_type}
                            className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                        >
                            <option value="">全員</option>
                            <option value="employee">社員</option>
                            <option value="part_time">アルバイト</option>
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="status">在籍状態</Label>
                        <select
                            id="status"
                            name="status"
                            defaultValue={filters.status}
                            className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                        >
                            <option value="">すべて</option>
                            <option value="employed">在籍</option>
                            <option value="retired">対象外・退職</option>
                        </select>
                    </div>
                    <Button type="submit">
                        <Search />
                        検索
                    </Button>
                </Form>

                {staffs.data.length === 0 ? (
                    <div className="border-border bg-card text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">
                        条件に一致するスタッフはいません。
                    </div>
                ) : (
                    <>
                        <div className="grid gap-3 md:hidden">
                            {staffs.data.map((staff) => (
                                <StaffCard key={staff.id} staff={staff} />
                            ))}
                        </div>
                        <div className="border-border bg-card hidden overflow-hidden rounded-xl border shadow-sm md:block">
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/70 text-muted-foreground">
                                        <tr>
                                            <th className="px-4 py-3 text-left font-medium">
                                                氏名
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                雇用区分
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                在籍
                                            </th>
                                            <th className="px-4 py-3 text-left font-medium">
                                                所属店舗
                                            </th>
                                            <th className="px-4 py-3 text-right font-medium">
                                                現在時給
                                            </th>
                                            <th className="px-4 py-3 text-right font-medium">
                                                操作
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {staffs.data.map((staff) => (
                                            <tr key={staff.id}>
                                                <td className="px-4 py-3 font-medium">
                                                    {staff.name}
                                                    {staff.display_name && (
                                                        <span className="text-muted-foreground mt-0.5 block text-xs font-normal">
                                                            表示名:{' '}
                                                            {staff.display_name}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {
                                                        staff.employment_type_label
                                                    }
                                                </td>
                                                <td className="px-4 py-3">
                                                    <StatusBadge
                                                        employed={
                                                            staff.is_employed
                                                        }
                                                    />
                                                </td>
                                                <td className="px-4 py-3">
                                                    {staff.stores.join('、') ||
                                                        '未設定'}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {staff.hourly_wage === null
                                                        ? '—'
                                                        : `${yen.format(staff.hourly_wage)}円`}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={`/staffs/${staff.id}/edit`}
                                                        >
                                                            編集
                                                        </Link>
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </>
                )}

                {staffs.total > 0 && (
                    <nav
                        aria-label="スタッフ一覧のページ"
                        className="flex flex-col items-center justify-between gap-3 sm:flex-row"
                    >
                        <p className="text-muted-foreground text-sm tabular-nums">
                            {staffs.from}〜{staffs.to}件／全{staffs.total}件
                        </p>
                        <div className="flex items-center gap-2">
                            {staffs.prev_page_url ? (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={staffs.prev_page_url}
                                        preserveScroll
                                    >
                                        前へ
                                    </Link>
                                </Button>
                            ) : (
                                <Button variant="outline" size="sm" disabled>
                                    前へ
                                </Button>
                            )}
                            <span className="text-sm tabular-nums">
                                {staffs.current_page}／{staffs.last_page}ページ
                            </span>
                            {staffs.next_page_url ? (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={staffs.next_page_url}
                                        preserveScroll
                                    >
                                        次へ
                                    </Link>
                                </Button>
                            ) : (
                                <Button variant="outline" size="sm" disabled>
                                    次へ
                                </Button>
                            )}
                        </div>
                    </nav>
                )}
            </div>
        </>
    );
}

function StatusBadge({ employed }: { employed: boolean }) {
    return (
        <Badge variant={employed ? 'default' : 'secondary'}>
            {employed ? '在籍' : '対象外・退職'}
        </Badge>
    );
}

function StaffCard({ staff }: { staff: Staff }) {
    return (
        <article className="border-border bg-card rounded-xl border p-4 shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="font-semibold">{staff.name}</h2>
                    {staff.display_name && (
                        <p className="text-muted-foreground mt-0.5 text-xs">
                            表示名: {staff.display_name}
                        </p>
                    )}
                    <p className="text-muted-foreground mt-1 text-sm">
                        {staff.employment_type_label}
                    </p>
                </div>
                <StatusBadge employed={staff.is_employed} />
            </div>
            <div className="text-muted-foreground mt-4 flex items-start gap-2 text-sm">
                <StoreIcon className="mt-0.5 size-4 shrink-0" />
                <span>{staff.stores.join('、') || '所属店舗未設定'}</span>
            </div>
            {staff.employment_type === 'part_time' && (
                <p className="mt-3 text-sm">
                    現在時給:{' '}
                    <strong>
                        {staff.hourly_wage === null
                            ? '未設定'
                            : `${yen.format(staff.hourly_wage)}円`}
                    </strong>
                </p>
            )}
            <Button variant="outline" className="mt-4 w-full" asChild>
                <Link href={`/staffs/${staff.id}/edit`}>編集する</Link>
            </Button>
        </article>
    );
}

StaffIndex.layout = {
    breadcrumbs: [{ title: 'スタッフ管理', href: '/staffs' }],
};
