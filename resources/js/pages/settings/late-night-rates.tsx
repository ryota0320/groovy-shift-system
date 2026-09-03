import { Form, Head } from '@inertiajs/react';
import { MoonStar, Plus } from 'lucide-react';
import EffectivePeriodFields from '@/components/effective-period-fields';
import InputError from '@/components/input-error';
import MasterPageHeader from '@/components/master-page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Rate = {
    id: number;
    amount_per_hour: number;
    effective_from: string;
    effective_to: string | null;
};

export default function LateNightRates({ rates }: { rates: Rate[] }) {
    return (
        <>
            <Head title="深夜加算設定" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <MasterPageHeader
                    title="深夜加算設定"
                    description="勤務日当日に有効な、1時間当たりの深夜加算額を管理します。"
                />

                <div className="grid gap-6 xl:grid-cols-[minmax(0,420px)_1fr]">
                    <section className="border-border bg-card rounded-xl border p-5 shadow-sm">
                        <h2 className="flex items-center gap-2 font-semibold">
                            <Plus className="text-primary size-5" />
                            新しい設定
                        </h2>
                        <Form
                            action="/settings/late-night-rates"
                            method="post"
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                            className="mt-5 space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <AmountField id="new-rate" />
                                    <InputError
                                        message={errors.amount_per_hour}
                                    />
                                    <EffectivePeriodFields
                                        idPrefix="new-rate"
                                        errors={errors}
                                    />
                                    <Button disabled={processing}>
                                        登録する
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>

                    <section className="border-border bg-card rounded-xl border p-5 shadow-sm">
                        <h2 className="flex items-center gap-2 font-semibold">
                            <MoonStar className="text-primary size-5" />
                            設定履歴
                        </h2>
                        <div className="mt-5 space-y-3">
                            {rates.length === 0 ? (
                                <p className="text-muted-foreground rounded-lg border border-dashed p-8 text-center text-sm">
                                    深夜加算額は未設定です。
                                </p>
                            ) : (
                                rates.map((rate) => (
                                    <details
                                        key={rate.id}
                                        className="group rounded-lg border"
                                    >
                                        <summary className="hover:bg-accent flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 rounded-lg px-4 py-3">
                                            <span className="font-medium">
                                                {rate.amount_per_hour.toLocaleString(
                                                    'ja-JP',
                                                )}
                                                円／時
                                            </span>
                                            <span className="text-muted-foreground text-sm">
                                                {rate.effective_from}〜
                                                {rate.effective_to ?? '無期限'}
                                            </span>
                                        </summary>
                                        <Form
                                            action={`/settings/late-night-rates/${rate.id}`}
                                            method="put"
                                            options={{ preserveScroll: true }}
                                            className="space-y-4 border-t p-4"
                                        >
                                            {({ processing, errors }) => (
                                                <>
                                                    <AmountField
                                                        id={`rate-${rate.id}`}
                                                        defaultValue={
                                                            rate.amount_per_hour
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            errors.amount_per_hour
                                                        }
                                                    />
                                                    <EffectivePeriodFields
                                                        idPrefix={`rate-${rate.id}`}
                                                        effectiveFrom={
                                                            rate.effective_from
                                                        }
                                                        effectiveTo={
                                                            rate.effective_to
                                                        }
                                                        errors={errors}
                                                    />
                                                    <Button
                                                        disabled={processing}
                                                    >
                                                        更新する
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    </details>
                                ))
                            )}
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}

function AmountField({
    id,
    defaultValue,
}: {
    id: string;
    defaultValue?: number;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>1時間当たり加算額</Label>
            <div className="relative">
                <Input
                    id={id}
                    name="amount_per_hour"
                    type="number"
                    min="0"
                    step="1"
                    defaultValue={defaultValue}
                    className="pr-10"
                    required
                />
                <span className="text-muted-foreground pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm">
                    円
                </span>
            </div>
        </div>
    );
}

LateNightRates.layout = {
    breadcrumbs: [
        { title: '深夜加算設定', href: '/settings/late-night-rates' },
    ],
};
