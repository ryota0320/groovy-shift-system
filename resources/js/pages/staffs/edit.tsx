import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, KeyRound, UserRound } from 'lucide-react';
import InputError from '@/components/input-error';
import MasterPageHeader from '@/components/master-page-header';
import StaffHistorySections from '@/components/staff-history-sections';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SelectOption, StaffMaster, StoreOption } from '@/types';

export default function StaffEdit({
    staff,
    stores,
    options,
}: {
    staff: StaffMaster;
    stores: StoreOption[];
    options: {
        transportation_tax_types: SelectOption[];
        income_tax_categories: SelectOption[];
    };
}) {
    const removeAccount = () => {
        if (window.confirm('このログインアカウントを削除しますか？')) {
            router.delete(`/staffs/${staff.id}/account`, {
                preserveScroll: true,
            });
        }
    };

    return (
        <>
            <Head title={`${staff.name} - スタッフ編集`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    title={staff.name}
                    description={`${staff.employment_type_label}の基本情報と履歴設定を管理します。`}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/staffs">
                                <ArrowLeft />
                                スタッフ一覧へ
                            </Link>
                        </Button>
                    }
                />

                <div className="grid gap-6 xl:grid-cols-2">
                    <section className="border-border bg-card rounded-xl border p-5 shadow-sm md:p-6">
                        <h2 className="flex items-center gap-2 font-semibold">
                            <UserRound className="text-primary size-5" />
                            基本情報
                        </h2>
                        <Form
                            action={`/staffs/${staff.id}`}
                            method="put"
                            options={{ preserveScroll: true }}
                            className="mt-5 space-y-5"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">氏名</Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            defaultValue={staff.name}
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
                                            defaultValue={staff.employment_type}
                                            className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                                        >
                                            <option value="part_time">
                                                アルバイト
                                            </option>
                                            <option value="employee">
                                                社員
                                            </option>
                                        </select>
                                        <InputError
                                            message={errors.employment_type}
                                        />
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="hired-at">
                                                入社日
                                            </Label>
                                            <Input
                                                id="hired-at"
                                                name="hired_at"
                                                type="date"
                                                defaultValue={
                                                    staff.hired_at ?? ''
                                                }
                                            />
                                            <InputError
                                                message={errors.hired_at}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="retired-at">
                                                退職日
                                            </Label>
                                            <Input
                                                id="retired-at"
                                                name="retired_at"
                                                type="date"
                                                defaultValue={
                                                    staff.retired_at ?? ''
                                                }
                                            />
                                            <InputError
                                                message={errors.retired_at}
                                            />
                                        </div>
                                    </div>
                                    <Button disabled={processing}>
                                        更新する
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>

                    {staff.employment_type === 'employee' && (
                        <section className="border-border bg-card rounded-xl border p-5 shadow-sm md:p-6">
                            <h2 className="flex items-center gap-2 font-semibold">
                                <KeyRound className="text-primary size-5" />
                                ログインアカウント
                            </h2>
                            <p className="text-muted-foreground mt-2 text-sm">
                                必要な社員にだけ個別アカウントを発行します。
                            </p>
                            <Form
                                action={`/staffs/${staff.id}/account`}
                                method="post"
                                options={{ preserveScroll: true }}
                                resetOnSuccess={[
                                    'password',
                                    'password_confirmation',
                                ]}
                                className="mt-5 space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="account-name">
                                                表示名
                                            </Label>
                                            <Input
                                                id="account-name"
                                                name="name"
                                                defaultValue={
                                                    staff.user?.name ??
                                                    staff.name
                                                }
                                                required
                                            />
                                            <InputError message={errors.name} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="account-email">
                                                メールアドレス
                                            </Label>
                                            <Input
                                                id="account-email"
                                                name="email"
                                                type="email"
                                                defaultValue={
                                                    staff.user?.email ?? ''
                                                }
                                                required
                                            />
                                            <InputError
                                                message={errors.email}
                                            />
                                        </div>
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div className="grid gap-2">
                                                <Label htmlFor="account-password">
                                                    {staff.user
                                                        ? '新しいパスワード'
                                                        : 'パスワード'}
                                                </Label>
                                                <Input
                                                    id="account-password"
                                                    name="password"
                                                    type="password"
                                                    required={!staff.user}
                                                />
                                                <InputError
                                                    message={errors.password}
                                                />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="account-password-confirmation">
                                                    確認
                                                </Label>
                                                <Input
                                                    id="account-password-confirmation"
                                                    name="password_confirmation"
                                                    type="password"
                                                    required={!staff.user}
                                                />
                                            </div>
                                        </div>
                                        <div className="flex flex-wrap gap-3">
                                            <Button disabled={processing}>
                                                {staff.user
                                                    ? 'アカウントを更新'
                                                    : 'アカウントを作成'}
                                            </Button>
                                            {staff.user && (
                                                <Button
                                                    type="button"
                                                    variant="destructive"
                                                    onClick={removeAccount}
                                                >
                                                    アカウントを削除
                                                </Button>
                                            )}
                                        </div>
                                    </>
                                )}
                            </Form>
                        </section>
                    )}
                </div>

                <StaffHistorySections
                    staff={staff}
                    stores={stores}
                    transportationTaxTypes={options.transportation_tax_types}
                    incomeTaxCategories={options.income_tax_categories}
                />
            </div>
        </>
    );
}

StaffEdit.layout = {
    breadcrumbs: [
        { title: 'スタッフ管理', href: '/staffs' },
        { title: 'スタッフ編集', href: '#' },
    ],
};
