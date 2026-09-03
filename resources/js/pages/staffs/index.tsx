import { Form, Head, Link } from '@inertiajs/react';
import { Plus, Search, Store as StoreIcon } from 'lucide-react';
import MasterPageHeader from '@/components/master-page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Staff = {
    id: number;
    name: string;
    employment_type: 'employee' | 'part_time';
    employment_type_label: string;
    is_employed: boolean;
    stores: string[];
    hourly_wage: number | null;
};

type Filters = {
    date: string;
    employment_type: string;
    status: string;
    search: string;
};

const yen = new Intl.NumberFormat('ja-JP');

export default function StaffIndex({
    staffs,
    filters,
}: {
    staffs: Staff[];
    filters: Filters;
}) {
    return (
        <>
            <Head title="スタッフ管理" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    title="スタッフ管理"
                    description="対象日時点の在籍、所属店舗、時給を確認できます。"
                    actions={
                        <Button asChild>
                            <Link href="/staffs/create">
                                <Plus />
                                スタッフを追加
                            </Link>
                        </Button>
                    }
                />

                <Form
                    action="/staffs"
                    method="get"
                    className="border-border bg-card grid gap-4 rounded-xl border p-4 shadow-sm md:grid-cols-5 md:items-end"
                >
                    <div className="grid gap-2 md:col-span-2">
                        <Label htmlFor="search">氏名検索</Label>
                        <Input
                            id="search"
                            name="search"
                            defaultValue={filters.search}
                            placeholder="氏名を入力"
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
                    <div className="grid gap-2">
                        <Label htmlFor="date">対象日</Label>
                        <div className="flex gap-2">
                            <Input
                                id="date"
                                name="date"
                                type="date"
                                defaultValue={filters.date}
                            />
                            <Button
                                type="submit"
                                size="icon"
                                aria-label="絞り込む"
                            >
                                <Search />
                            </Button>
                        </div>
                    </div>
                </Form>

                {staffs.length === 0 ? (
                    <div className="border-border bg-card text-muted-foreground rounded-xl border border-dashed p-10 text-center text-sm">
                        条件に一致するスタッフはいません。
                    </div>
                ) : (
                    <>
                        <div className="grid gap-3 md:hidden">
                            {staffs.map((staff) => (
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
                                        {staffs.map((staff) => (
                                            <tr key={staff.id}>
                                                <td className="px-4 py-3 font-medium">
                                                    {staff.name}
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
