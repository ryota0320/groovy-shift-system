import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function EffectivePeriodFields({
    idPrefix,
    effectiveFrom,
    effectiveTo,
    errors = {},
}: {
    idPrefix: string;
    effectiveFrom?: string;
    effectiveTo?: string | null;
    errors?: Record<string, string>;
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-effective-from`}>適用開始日</Label>
                <Input
                    id={`${idPrefix}-effective-from`}
                    name="effective_from"
                    type="date"
                    defaultValue={effectiveFrom}
                    required
                />
                <InputError message={errors.effective_from} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-effective-to`}>
                    適用終了日（空欄は無期限）
                </Label>
                <Input
                    id={`${idPrefix}-effective-to`}
                    name="effective_to"
                    type="date"
                    defaultValue={effectiveTo ?? ''}
                />
                <InputError message={errors.effective_to} />
            </div>
        </div>
    );
}
