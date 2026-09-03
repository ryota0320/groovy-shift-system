import { Form, Head, Link } from '@inertiajs/react';
import { Building2, CalendarDays, Plus } from 'lucide-react';
import InputError from '@/components/input-error';
import MasterPageHeader from '@/components/master-page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Store = {
    id: number;
    name: string;
    is_active: boolean;
    holidays_count: number;
};

export default function StoreIndex({ stores }: { stores: Store[] }) {
    return (
        <>
            <Head title="店舗管理" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    title="店舗管理"
                    description="店舗の追加、名称変更、有効・無効、店休日を管理します。"
                />

                <section className="border-border bg-card rounded-xl border p-4 shadow-sm md:p-5">
                    <h2 className="flex items-center gap-2 font-semibold">
                        <Plus className="text-primary size-5" />
                        店舗を追加
                    </h2>
                    <Form
                        action="/stores"
                        method="post"
                        resetOnSuccess
                        className="mt-4 flex flex-col gap-4 sm:flex-row sm:items-end"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid flex-1 gap-2">
                                    <Label htmlFor="store-name">店舗名</Label>
                                    <Input
                                        id="store-name"
                                        name="name"
                                        placeholder="店舗名を入力"
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <input
                                    type="hidden"
                                    name="is_active"
                                    value="1"
                                />
                                <Button
                                    disabled={processing}
                                    className="sm:w-auto"
                                >
                                    登録する
                                </Button>
                            </>
                        )}
                    </Form>
                </section>

                <section className="space-y-3">
                    <h2 className="font-semibold">登録店舗</h2>
                    {stores.length === 0 ? (
                        <div className="border-border bg-card text-muted-foreground rounded-xl border border-dashed p-8 text-center text-sm">
                            店舗が登録されていません。
                        </div>
                    ) : (
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {stores.map((store) => (
                                <article
                                    key={store.id}
                                    className="border-border bg-card rounded-xl border p-5 shadow-sm"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex min-w-0 items-center gap-3">
                                            <div className="bg-primary/10 text-primary rounded-lg p-2.5">
                                                <Building2 className="size-5" />
                                            </div>
                                            <div className="min-w-0">
                                                <h3 className="truncate font-semibold">
                                                    {store.name}
                                                </h3>
                                                <p className="text-muted-foreground mt-1 flex items-center gap-1 text-sm">
                                                    <CalendarDays className="size-4" />
                                                    店休日{' '}
                                                    {store.holidays_count}件
                                                </p>
                                            </div>
                                        </div>
                                        <Badge
                                            variant={
                                                store.is_active
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {store.is_active ? '有効' : '無効'}
                                        </Badge>
                                    </div>
                                    <Button
                                        variant="outline"
                                        className="mt-5 w-full"
                                        asChild
                                    >
                                        <Link href={`/stores/${store.id}/edit`}>
                                            編集・店休日設定
                                        </Link>
                                    </Button>
                                </article>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

StoreIndex.layout = {
    breadcrumbs: [{ title: '店舗管理', href: '/stores' }],
};
