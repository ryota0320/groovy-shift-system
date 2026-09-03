import { Form } from '@inertiajs/react';
import { Banknote, Building2, ReceiptText, TrainFront } from 'lucide-react';
import type { ReactNode } from 'react';
import EffectivePeriodFields from '@/components/effective-period-fields';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    SelectOption,
    StaffAssignment,
    StaffIncomeTaxSetting,
    StaffMaster,
    StaffTransportationFee,
    StaffWageRate,
    StoreOption,
} from '@/types';
import { selectableHistoryStores } from '@/lib/master-options';

export default function StaffHistorySections({
    staff,
    stores,
    transportationTaxTypes,
    incomeTaxCategories,
}: {
    staff: StaffMaster;
    stores: StoreOption[];
    transportationTaxTypes: SelectOption[];
    incomeTaxCategories: SelectOption[];
}) {
    return (
        <div className="grid gap-6 xl:grid-cols-2">
            <HistorySection
                icon={<Building2 />}
                title="店舗所属期間"
                description="同じ店舗の所属期間は重複できません。"
            >
                <AssignmentCreateForm staffId={staff.id} stores={stores} />
                <HistoryList empty={staff.assignments.length === 0}>
                    {staff.assignments.map((assignment) => (
                        <AssignmentEditForm
                            key={assignment.id}
                            staffId={staff.id}
                            assignment={assignment}
                            stores={stores}
                        />
                    ))}
                </HistoryList>
            </HistorySection>

            <HistorySection
                icon={<TrainFront />}
                title="店舗別交通費履歴"
                description="実勤務日ごとの金額と課税区分を設定します。"
            >
                <TransportationCreateForm
                    staffId={staff.id}
                    stores={stores}
                    taxTypes={transportationTaxTypes}
                />
                <HistoryList empty={staff.transportation_fees.length === 0}>
                    {staff.transportation_fees.map((fee) => (
                        <TransportationEditForm
                            key={fee.id}
                            staffId={staff.id}
                            fee={fee}
                            stores={stores}
                            taxTypes={transportationTaxTypes}
                        />
                    ))}
                </HistoryList>
            </HistorySection>

            {staff.employment_type === 'part_time' && (
                <>
                    <HistorySection
                        icon={<Banknote />}
                        title="時給履歴"
                        description="勤務日当日に有効な時給が給与計算に使われます。"
                    >
                        <WageCreateForm staffId={staff.id} />
                        <HistoryList empty={staff.wage_rates.length === 0}>
                            {staff.wage_rates.map((rate) => (
                                <WageEditForm
                                    key={rate.id}
                                    staffId={staff.id}
                                    rate={rate}
                                />
                            ))}
                        </HistoryList>
                    </HistorySection>

                    <HistorySection
                        icon={<ReceiptText />}
                        title="所得税設定履歴"
                        description="給与支給日に有効な税区分と扶養人数を使います。"
                    >
                        <TaxCreateForm
                            staffId={staff.id}
                            categories={incomeTaxCategories}
                        />
                        <HistoryList
                            empty={staff.income_tax_settings.length === 0}
                        >
                            {staff.income_tax_settings.map((setting) => (
                                <TaxEditForm
                                    key={setting.id}
                                    staffId={staff.id}
                                    setting={setting}
                                    categories={incomeTaxCategories}
                                />
                            ))}
                        </HistoryList>
                    </HistorySection>
                </>
            )}
        </div>
    );
}

function HistorySection({
    icon,
    title,
    description,
    children,
}: {
    icon: ReactNode;
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section className="border-border bg-card rounded-xl border p-5 shadow-sm md:p-6">
            <h2 className="[&_svg]:text-primary flex items-center gap-2 font-semibold [&_svg]:size-5">
                {icon}
                {title}
            </h2>
            <p className="text-muted-foreground mt-2 text-sm">{description}</p>
            <div className="mt-5 space-y-4">{children}</div>
        </section>
    );
}

function HistoryList({
    empty,
    children,
}: {
    empty: boolean;
    children: ReactNode;
}) {
    if (empty) {
        return (
            <p className="text-muted-foreground rounded-lg border border-dashed p-5 text-center text-sm">
                履歴はまだありません。
            </p>
        );
    }

    return <div className="space-y-3">{children}</div>;
}

function HistoryDetails({
    summary,
    children,
}: {
    summary: string;
    children: ReactNode;
}) {
    return (
        <details className="group rounded-lg border">
            <summary className="hover:bg-accent flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 rounded-lg px-4 py-3 text-sm font-medium">
                <span>{summary}</span>
                <span className="text-primary group-open:rotate-45">＋</span>
            </summary>
            <div className="border-t p-4">{children}</div>
        </details>
    );
}

function StoreSelect({
    id,
    stores,
    defaultValue,
    allowInactiveStoreId,
}: {
    id: string;
    stores: StoreOption[];
    defaultValue?: number;
    allowInactiveStoreId?: number;
}) {
    const selectableStores = selectableHistoryStores(
        stores,
        allowInactiveStoreId,
    );

    return (
        <select
            id={id}
            name="store_id"
            defaultValue={defaultValue ?? ''}
            className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
            required
        >
            <option value="" disabled>
                店舗を選択
            </option>
            {selectableStores.map((store) => (
                <option key={store.id} value={store.id}>
                    {store.name}
                    {store.is_active ? '' : '（無効）'}
                </option>
            ))}
        </select>
    );
}

function AssignmentCreateForm({
    staffId,
    stores,
}: {
    staffId: number;
    stores: StoreOption[];
}) {
    return (
        <HistoryDetails summary="新しい所属期間を追加">
            <Form
                action={`/staffs/${staffId}/assignments`}
                method="post"
                resetOnSuccess
                options={{ preserveScroll: true }}
                className="space-y-4"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="new-assignment-store">店舗</Label>
                            <StoreSelect
                                id="new-assignment-store"
                                stores={stores}
                            />
                            <InputError message={errors.store_id} />
                        </div>
                        <EffectivePeriodFields
                            idPrefix="new-assignment"
                            errors={errors}
                        />
                        <Button disabled={processing}>追加する</Button>
                    </>
                )}
            </Form>
        </HistoryDetails>
    );
}

function AssignmentEditForm({
    staffId,
    assignment,
    stores,
}: {
    staffId: number;
    assignment: StaffAssignment;
    stores: StoreOption[];
}) {
    return (
        <HistoryDetails
            summary={`${assignment.store_name}｜${periodLabel(assignment.effective_from, assignment.effective_to)}`}
        >
            <Form
                action={`/staffs/${staffId}/assignments/${assignment.id}`}
                method="put"
                options={{ preserveScroll: true }}
                className="space-y-4"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label
                                htmlFor={`assignment-store-${assignment.id}`}
                            >
                                店舗
                            </Label>
                            <StoreSelect
                                id={`assignment-store-${assignment.id}`}
                                stores={stores}
                                defaultValue={assignment.store_id}
                                allowInactiveStoreId={assignment.store_id}
                            />
                            <InputError message={errors.store_id} />
                        </div>
                        <EffectivePeriodFields
                            idPrefix={`assignment-${assignment.id}`}
                            effectiveFrom={assignment.effective_from}
                            effectiveTo={assignment.effective_to}
                            errors={errors}
                        />
                        <Button disabled={processing}>更新する</Button>
                    </>
                )}
            </Form>
        </HistoryDetails>
    );
}

function WageCreateForm({ staffId }: { staffId: number }) {
    return (
        <HistoryDetails summary="新しい時給を追加">
            <Form
                action={`/staffs/${staffId}/wage-rates`}
                method="post"
                resetOnSuccess
                options={{ preserveScroll: true }}
                className="space-y-4"
            >
                {({ processing, errors }) => (
                    <>
                        <MoneyField
                            id="new-hourly-wage"
                            name="hourly_wage"
                            label="時給"
                        />
                        <InputError
                            message={errors.hourly_wage ?? errors.staff}
                        />
                        <EffectivePeriodFields
                            idPrefix="new-wage"
                            errors={errors}
                        />
                        <Button disabled={processing}>追加する</Button>
                    </>
                )}
            </Form>
        </HistoryDetails>
    );
}

function WageEditForm({
    staffId,
    rate,
}: {
    staffId: number;
    rate: StaffWageRate;
}) {
    return (
        <HistoryDetails
            summary={`${rate.hourly_wage.toLocaleString('ja-JP')}円｜${periodLabel(rate.effective_from, rate.effective_to)}`}
        >
            <Form
                action={`/staffs/${staffId}/wage-rates/${rate.id}`}
                method="put"
                options={{ preserveScroll: true }}
                className="space-y-4"
            >
                {({ processing, errors }) => (
                    <>
                        <MoneyField
                            id={`hourly-wage-${rate.id}`}
                            name="hourly_wage"
                            label="時給"
                            defaultValue={rate.hourly_wage}
                        />
                        <InputError
                            message={errors.hourly_wage ?? errors.staff}
                        />
                        <EffectivePeriodFields
                            idPrefix={`wage-${rate.id}`}
                            effectiveFrom={rate.effective_from}
                            effectiveTo={rate.effective_to}
                            errors={errors}
                        />
                        <Button disabled={processing}>更新する</Button>
                    </>
                )}
            </Form>
        </HistoryDetails>
    );
}

function TransportationCreateForm({
    staffId,
    stores,
    taxTypes,
}: {
    staffId: number;
    stores: StoreOption[];
    taxTypes: SelectOption[];
}) {
    return (
        <HistoryDetails summary="新しい交通費を追加">
            <Form
                action={`/staffs/${staffId}/transportation-fees`}
                method="post"
                resetOnSuccess
                options={{ preserveScroll: true }}
                className="space-y-4"
            >
                {({ processing, errors }) => (
                    <>
                        <TransportationFields
                            idPrefix="new-transport"
                            stores={stores}
                            taxTypes={taxTypes}
                            errors={errors}
                        />
                        <EffectivePeriodFields
                            idPrefix="new-transport"
                            errors={errors}
                        />
                        <Button disabled={processing}>追加する</Button>
                    </>
                )}
            </Form>
        </HistoryDetails>
    );
}

function TransportationEditForm({
    staffId,
    fee,
    stores,
    taxTypes,
}: {
    staffId: number;
    fee: StaffTransportationFee;
    stores: StoreOption[];
    taxTypes: SelectOption[];
}) {
    return (
        <HistoryDetails
            summary={`${fee.store_name} ${fee.amount_per_day.toLocaleString('ja-JP')}円（${fee.tax_type_label}）`}
        >
            <Form
                action={`/staffs/${staffId}/transportation-fees/${fee.id}`}
                method="put"
                options={{ preserveScroll: true }}
                className="space-y-4"
            >
                {({ processing, errors }) => (
                    <>
                        <TransportationFields
                            idPrefix={`transport-${fee.id}`}
                            stores={stores}
                            taxTypes={taxTypes}
                            fee={fee}
                            errors={errors}
                        />
                        <EffectivePeriodFields
                            idPrefix={`transport-${fee.id}`}
                            effectiveFrom={fee.effective_from}
                            effectiveTo={fee.effective_to}
                            errors={errors}
                        />
                        <Button disabled={processing}>更新する</Button>
                    </>
                )}
            </Form>
        </HistoryDetails>
    );
}

function TransportationFields({
    idPrefix,
    stores,
    taxTypes,
    fee,
    errors,
}: {
    idPrefix: string;
    stores: StoreOption[];
    taxTypes: SelectOption[];
    fee?: StaffTransportationFee;
    errors: Record<string, string>;
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-3">
            <div className="grid gap-2 sm:col-span-3">
                <Label htmlFor={`${idPrefix}-store`}>店舗</Label>
                <StoreSelect
                    id={`${idPrefix}-store`}
                    stores={stores}
                    defaultValue={fee?.store_id}
                    allowInactiveStoreId={fee?.store_id}
                />
                <InputError message={errors.store_id} />
            </div>
            <div className="grid gap-2 sm:col-span-2">
                <MoneyField
                    id={`${idPrefix}-amount`}
                    name="amount_per_day"
                    label="1日当たり交通費"
                    defaultValue={fee?.amount_per_day}
                />
                <InputError message={errors.amount_per_day} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-tax-type`}>課税区分</Label>
                <select
                    id={`${idPrefix}-tax-type`}
                    name="tax_type"
                    defaultValue={fee?.tax_type ?? 'non_taxable'}
                    className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                >
                    {taxTypes.map((type) => (
                        <option key={type.value} value={type.value}>
                            {type.label}
                        </option>
                    ))}
                </select>
                <InputError message={errors.tax_type} />
            </div>
        </div>
    );
}

function TaxCreateForm({
    staffId,
    categories,
}: {
    staffId: number;
    categories: SelectOption[];
}) {
    return (
        <HistoryDetails summary="新しい所得税設定を追加">
            <Form
                action={`/staffs/${staffId}/income-tax-settings`}
                method="post"
                resetOnSuccess
                options={{ preserveScroll: true }}
                className="space-y-4"
            >
                {({ processing, errors }) => (
                    <>
                        <TaxFields
                            idPrefix="new-tax"
                            categories={categories}
                            errors={errors}
                        />
                        <EffectivePeriodFields
                            idPrefix="new-tax"
                            errors={errors}
                        />
                        <InputError message={errors.staff} />
                        <Button disabled={processing}>追加する</Button>
                    </>
                )}
            </Form>
        </HistoryDetails>
    );
}

function TaxEditForm({
    staffId,
    setting,
    categories,
}: {
    staffId: number;
    setting: StaffIncomeTaxSetting;
    categories: SelectOption[];
}) {
    return (
        <HistoryDetails
            summary={`${setting.tax_category_label}・扶養${setting.dependent_count}人｜${periodLabel(setting.effective_from, setting.effective_to)}`}
        >
            <Form
                action={`/staffs/${staffId}/income-tax-settings/${setting.id}`}
                method="put"
                options={{ preserveScroll: true }}
                className="space-y-4"
            >
                {({ processing, errors }) => (
                    <>
                        <TaxFields
                            idPrefix={`tax-${setting.id}`}
                            categories={categories}
                            setting={setting}
                            errors={errors}
                        />
                        <EffectivePeriodFields
                            idPrefix={`tax-${setting.id}`}
                            effectiveFrom={setting.effective_from}
                            effectiveTo={setting.effective_to}
                            errors={errors}
                        />
                        <InputError message={errors.staff} />
                        <Button disabled={processing}>更新する</Button>
                    </>
                )}
            </Form>
        </HistoryDetails>
    );
}

function TaxFields({
    idPrefix,
    categories,
    setting,
    errors,
}: {
    idPrefix: string;
    categories: SelectOption[];
    setting?: StaffIncomeTaxSetting;
    errors: Record<string, string>;
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-category`}>税区分</Label>
                <select
                    id={`${idPrefix}-category`}
                    name="tax_category"
                    defaultValue={setting?.tax_category ?? 'ko'}
                    className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                >
                    {categories.map((category) => (
                        <option key={category.value} value={category.value}>
                            {category.label}
                        </option>
                    ))}
                </select>
                <InputError message={errors.tax_category} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-dependents`}>
                    扶養親族等の人数
                </Label>
                <Input
                    id={`${idPrefix}-dependents`}
                    name="dependent_count"
                    type="number"
                    min="0"
                    step="1"
                    defaultValue={setting?.dependent_count ?? 0}
                    required
                />
                <InputError message={errors.dependent_count} />
            </div>
        </div>
    );
}

function MoneyField({
    id,
    name,
    label,
    defaultValue,
}: {
    id: string;
    name: string;
    label: string;
    defaultValue?: number;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <div className="relative">
                <Input
                    id={id}
                    name={name}
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

function periodLabel(from: string, to: string | null): string {
    return `${from}〜${to ?? '無期限'}`;
}
