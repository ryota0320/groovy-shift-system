import type { ShiftType, ShiftValue, StoreOption } from '@/types';
import { cn } from '@/lib/utils';

const hourlyOptions = Array.from({ length: 24 }, (_, hour) => {
    const hourText = String(hour).padStart(2, '0');

    return {
        value: `time:${hourText}:00`,
        time: `${hourText}:00`,
        compactLabel: hourText,
    };
});

const isWorkShift = (type: ShiftType | null) =>
    type === 'time' || type === 'early';

export function selectableShiftStores(
    stores: StoreOption[],
    availableStoreIds: number[],
): StoreOption[] {
    return stores.filter(
        (store) =>
            store.is_active && availableStoreIds.includes(Number(store.id)),
    );
}

const encodeShiftKind = (value: ShiftValue): string => {
    if (value.shift_type === 'time' && value.start_time) {
        return `time:${value.start_time.slice(0, 5)}`;
    }

    return value.shift_type ?? '';
};

export function encodeShiftValue(value: ShiftValue): string {
    const kind = encodeShiftKind(value);

    return isWorkShift(value.shift_type)
        ? `${kind}@${value.store_id ?? ''}`
        : kind;
}

export function decodeShiftValue(value: string): ShiftValue {
    const [kind, storeIdText = ''] = value.split('@');
    const storeId = Number(storeIdText) || null;

    if (kind.startsWith('time:')) {
        return {
            shift_type: 'time',
            start_time: kind.slice(5),
            store_id: storeId,
        };
    }

    const shiftType = (kind || null) as ShiftType | null;

    return {
        shift_type: shiftType,
        start_time: null,
        store_id: shiftType === 'early' ? storeId : null,
    };
}

export default function ShiftSelect({
    value,
    stores,
    selectedStoreId,
    availableStoreIds,
    onChange,
    disabled = false,
    compact = false,
    ariaLabel,
}: {
    value: ShiftValue;
    stores: StoreOption[];
    selectedStoreId: number;
    availableStoreIds: number[];
    onChange: (value: ShiftValue) => void;
    disabled?: boolean;
    compact?: boolean;
    ariaLabel: string;
}) {
    const availableStores = selectableShiftStores(stores, availableStoreIds);
    const defaultStoreId =
        value.store_id && availableStoreIds.includes(value.store_id)
            ? value.store_id
            : availableStoreIds.includes(selectedStoreId)
              ? selectedStoreId
              : (availableStores[0]?.id ?? null);

    const changeKind = (kind: string) => {
        const next = decodeShiftValue(
            isWorkShiftKind(kind) ? `${kind}@${defaultStoreId ?? ''}` : kind,
        );
        onChange(next);
    };

    return (
        <div
            className={cn(
                'items-center gap-1.5',
                compact
                    ? 'flex min-w-20 flex-col'
                    : 'grid grid-cols-[minmax(8rem,1fr)_minmax(9rem,1fr)]',
            )}
        >
            <select
                aria-label={ariaLabel}
                value={encodeShiftKind(value)}
                disabled={disabled}
                onChange={(event) => changeKind(event.target.value)}
                className={cn(
                    'border-input bg-background focus-visible:border-ring focus-visible:ring-ring/50 disabled:bg-muted h-9 rounded-md border text-sm outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-70',
                    compact ? 'w-14 px-1 text-center' : 'w-full px-3',
                )}
            >
                <option value="">{compact ? '－' : '未設定'}</option>
                {hourlyOptions.map((option) => (
                    <option key={option.value} value={option.value}>
                        {compact ? option.compactLabel : option.time}
                    </option>
                ))}
                <option value="early">{compact ? '早' : '早番'}</option>
                <option value="off">{compact ? '休' : '休み'}</option>
                <option value="absence">{compact ? '急休' : '急な休み'}</option>
            </select>

            {isWorkShift(value.shift_type) && (
                <select
                    aria-label={`${ariaLabel}の勤務店舗`}
                    value={value.store_id ?? ''}
                    disabled={disabled || availableStores.length === 0}
                    onChange={(event) =>
                        onChange({
                            ...value,
                            store_id: Number(event.target.value),
                        })
                    }
                    className={cn(
                        'border-input bg-background focus-visible:border-ring focus-visible:ring-ring/50 disabled:bg-muted h-9 rounded-md border text-sm outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-70',
                        compact ? 'w-20 px-1 text-xs' : 'w-full px-2',
                    )}
                >
                    {availableStores.map((store) => (
                        <option key={store.id} value={store.id}>
                            {Number(store.id) === selectedStoreId
                                ? compact
                                    ? '自店'
                                    : `自店：${store.name}`
                                : compact
                                  ? store.name
                                  : `ヘルプ：${store.name}`}
                        </option>
                    ))}
                </select>
            )}
        </div>
    );
}

function isWorkShiftKind(kind: string) {
    return kind === 'early' || kind.startsWith('time:');
}
