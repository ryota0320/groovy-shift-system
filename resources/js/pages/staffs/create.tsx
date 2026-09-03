import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import InputError from '@/components/input-error';
import MasterPageHeader from '@/components/master-page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { StoreOption } from '@/types';

export default function StaffCreate({
    stores,
    today,
}: {
    stores: StoreOption[];
    today: string;
}) {
    return (
        <>
            <Head title="スタッフ登録" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    title="スタッフ登録"
                    description="氏名、雇用区分、在籍期間と初期所属店舗を登録します。"
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/staffs">
                                <ArrowLeft />
                                スタッフ一覧へ
                            </Link>
                        </Button>
                    }
                />

                <section className="border-border bg-card max-w-3xl rounded-xl border p-5 shadow-sm md:p-6">
                    <Form action="/staffs" method="post" className="space-y-5">
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">氏名</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        autoComplete="name"
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="employment-type">
                                        雇用区分
                                    </Label>
                                    <select
                                        id="employment-type"
                                        name="employment_type"
                                        defaultValue="part_time"
                                        className="border-input bg-background focus-visible:border-ring focus-visible:ring-ring/50 h-10 w-full rounded-md border px-3 text-sm outline-none focus-visible:ring-[3px]"
                                    >
                                        <option value="part_time">
                                            アルバイト
                                        </option>
                                        <option value="employee">社員</option>
                                    </select>
                                    <InputError
                                        message={errors.employment_type}
                                    />
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="hired-at">入社日</Label>
                                        <Input
                                            id="hired-at"
                                            name="hired_at"
                                            type="date"
                                        />
                                        <InputError message={errors.hired_at} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="retired-at">
                                            退職日
                                        </Label>
                                        <Input
                                            id="retired-at"
                                            name="retired_at"
                                            type="date"
                                        />
                                        <InputError
                                            message={errors.retired_at}
                                        />
                                    </div>
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="store-id">
                                            所属店舗
                                        </Label>
                                        <select
                                            id="store-id"
                                            name="store_id"
                                            required
                                            defaultValue=""
                                            className="border-input bg-background focus-visible:border-ring focus-visible:ring-ring/50 h-10 w-full rounded-md border px-3 text-sm outline-none focus-visible:ring-[3px]"
                                        >
                                            <option value="" disabled>
                                                店舗を選択
                                            </option>
                                            {stores.map((store) => (
                                                <option
                                                    key={store.id}
                                                    value={store.id}
                                                >
                                                    {store.name}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={errors.store_id} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="assignment-effective-from">
                                            所属開始日
                                        </Label>
                                        <Input
                                            id="assignment-effective-from"
                                            name="assignment_effective_from"
                                            type="date"
                                            defaultValue={today}
                                            required
                                        />
                                        <InputError
                                            message={
                                                errors.assignment_effective_from
                                            }
                                        />
                                    </div>
                                </div>

                                <Button disabled={processing}>登録する</Button>
                            </>
                        )}
                    </Form>
                </section>
            </div>
        </>
    );
}

StaffCreate.layout = {
    breadcrumbs: [
        { title: 'スタッフ管理', href: '/staffs' },
        { title: 'スタッフ登録', href: '/staffs/create' },
    ],
};
