import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CalendarPlus, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import MasterPageHeader from '@/components/master-page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Store = {
    id: number;
    name: string;
    is_active: boolean;
    holidays: Array<{ id: number; holiday_date: string }>;
};

export default function StoreEdit({ store }: { store: Store }) {
    const removeHoliday = (holidayId: number) => {
        if (window.confirm('この店休日を削除しますか？')) {
            router.delete(`/stores/${store.id}/holidays/${holidayId}`, {
                preserveScroll: true,
            });
        }
    };

    return (
        <>
            <Head title={`${store.name} - 店舗編集`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    title={store.name}
                    description="店舗情報と店休日を管理します。無効化しても過去データは保持されます。"
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
                            店休日を追加
                        </h2>
                        <Form
                            action={`/stores/${store.id}/holidays`}
                            method="post"
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                            className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-start"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid flex-1 gap-2">
                                        <Label htmlFor="holiday-date">
                                            日付
                                        </Label>
                                        <Input
                                            id="holiday-date"
                                            name="holiday_date"
                                            type="date"
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
                                    店休日は登録されていません。
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
