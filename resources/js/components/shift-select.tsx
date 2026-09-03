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

const timeToMinutes = (time: string) => {
    const [hour = 0, minute = 0] = time.split(':').map(Number);

    return hour * 60 + minute;
};

export function shiftTimeOptions(openingTime: string, closingTime: string) {
    const openingMinutes = timeToMinutes(openingTime);
    let closingMinutes = timeToMinutes(closingTime);

    if (closingMinutes <= openingMinutes) {
        closingMinutes += 24 * 60;
    }

    return hourlyOptions
        .filter((option) => {
            let optionMinutes = timeToMinutes(option.time);

            if (optionMinutes < openingMinutes) {
                optionMinutes += 24 * 60;
            }

            return (
                optionMinutes >= openingMinutes &&
                optionMinutes <= closingMinutes
            );
        })
        .sort((left, right) => {
            const businessMinutes = (time: string) => {
                const minutes = timeToMinutes(time);

                return minutes < openingMinutes ? minutes + 24 * 60 : minutes;
            };

            return businessMinutes(left.time) - businessMinutes(right.time);
        });
}

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
    holidayHelpOnly = false,
    ariaLabel,
}: {
    value: ShiftValue;
    stores: StoreOption[];
    selectedStoreId: number;
    availableStoreIds: number[];
    onChange: (value: ShiftValue) => void;
    disabled?: boolean;
    compact?: boolean;
    holidayHelpOnly?: boolean;
    ariaLabel: string;
}) {
    const availableStores = selectableShiftStores(stores, availableStoreIds);
    const defaultStoreId =
        value.store_id && availableStoreIds.includes(value.store_id)
            ? value.store_id
            : availableStoreIds.includes(selectedStoreId)
              ? selectedStoreId
              : (availableStores[0]?.id ?? null);
    const workStore = availableStores.find(
        (store) =>
            Number(store.id) === Number(value.store_id ?? defaultStoreId),
    );
    const timeOptions = shiftTimeOptions(
        workStore?.opening_time ?? '17:00',
        workStore?.closing_time ?? '10:00',
    );

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
                    holidayHelpOnly &&
                        'border-orange-500 bg-orange-100 text-orange-950 dark:border-orange-400 dark:bg-orange-950 dark:text-orange-50',
                )}
            >
                <option value="">{compact ? '－' : '未設定'}</option>
                {timeOptions.map((option) => (
                    <option key={option.value} value={option.value}>
                        {compact ? option.compactLabel : option.time}
                    </option>
                ))}
                <option value="early">{compact ? '早' : '早番'}</option>
                {!holidayHelpOnly && (
                    <>
                        <option value="off">{compact ? '休' : '休み'}</option>
                        <option value="absence">
                            {compact ? '急休' : '急な休み'}
                        </option>
                    </>
                )}
            </select>

            {isWorkShift(value.shift_type) && (
                <select
                    aria-label={`${ariaLabel}の勤務店舗`}
                    value={value.store_id ?? ''}
                    disabled={disabled || availableStores.length === 0}
                    onChange={(event) => {
                        const storeId = Number(event.target.value);
                        const nextStore = availableStores.find(
                            (store) => Number(store.id) === storeId,
                        );
                        const nextTimeOptions = shiftTimeOptions(
                            nextStore?.opening_time ?? '17:00',
                            nextStore?.closing_time ?? '10:00',
                        );
                        const currentStartTime = value.start_time?.slice(0, 5);
                        const startTimeIsAvailable = nextTimeOptions.some(
                            (option) => option.time === currentStartTime,
                        );

                        onChange({
                            ...value,
                            store_id: storeId,
                            start_time:
                                value.shift_type === 'time' &&
                                !startTimeIsAvailable
                                    ? (nextTimeOptions[0]?.time ?? null)
                                    : value.start_time,
                        });
                    }}
                    className={cn(
                        'border-input bg-background focus-visible:border-ring focus-visible:ring-ring/50 disabled:bg-muted h-9 rounded-md border text-sm outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-70',
                        compact ? 'w-20 px-1 text-xs' : 'w-full px-2',
                        holidayHelpOnly &&
                            'border-orange-500 bg-orange-100 text-orange-950 dark:border-orange-400 dark:bg-orange-950 dark:text-orange-50',
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
