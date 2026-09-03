import type { ShiftType, ShiftValue, StoreOption } from '@/types';
import { cn } from '@/lib/utils';

const hourlyOptions = Array.from({ length: 24 }, (_, hour) => {
    const hourText = String(hour).padStart(2, '0');
    const displayHour = hour === 0 ? '24' : hourText;

    return {
        value: `time:${hourText}:00`,
        time: `${hourText}:00`,
        label: `${displayHour}:00`,
        compactLabel: displayHour,
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

    return ['time', 'early', 'help'].includes(value.shift_type ?? '')
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
        store_id:
            shiftType === 'early' || shiftType === 'help' ? storeId : null,
    };
}

type ShiftSelectProps = {
    value: ShiftValue;
    stores: StoreOption[];
    selectedStoreId: number;
    availableStoreIds: number[];
    onChange: (value: ShiftValue) => void;
    disabled?: boolean;
    compact?: boolean;
    holidayHelpOnly?: boolean;
    combined?: boolean;
    ariaLabel: string;
};

export function shiftWorkOptions(
    stores: StoreOption[],
    availableStoreIds: number[],
    selectedStoreId: number,
    compact = false,
) {
    const availableStores = selectableShiftStores(stores, availableStoreIds);
    const selectedStore = availableStores.find(
        (store) => Number(store.id) === selectedStoreId,
    );
    const ownStoreOptions = selectedStore
        ? [
              ...shiftTimeOptions(
                  selectedStore.opening_time,
                  selectedStore.closing_time,
              ).map((option) => ({
                  value: `${option.value}@${selectedStore.id}`,
                  label: compact ? option.compactLabel : option.label,
              })),
              {
                  value: `early@${selectedStore.id}`,
                  label: compact ? '早' : '早番',
              },
          ]
        : [];
    const helpStoreOptions = availableStores
        .filter((store) => Number(store.id) !== selectedStoreId)
        .map((store) => ({
            value: `help@${store.id}`,
            label: store.name,
        }));

    return [...ownStoreOptions, ...helpStoreOptions];
}

export default function ShiftSelect(props: ShiftSelectProps) {
    return props.combined ? (
        <CombinedShiftSelect {...props} />
    ) : (
        <SeparateShiftSelect {...props} />
    );
}

function CombinedShiftSelect({
    value,
    stores,
    selectedStoreId,
    availableStoreIds,
    onChange,
    disabled = false,
    compact = false,
    holidayHelpOnly = false,
    ariaLabel,
}: ShiftSelectProps) {
    const workOptions = shiftWorkOptions(
        stores,
        availableStoreIds,
        selectedStoreId,
        compact,
    );
    const pendingHelpAtSelectedStore =
        value.shift_type === 'help' &&
        Number(value.store_id) === selectedStoreId;

    return (
        <div
            className={cn(
                'items-center',
                compact ? 'flex min-w-20 flex-col' : 'w-full',
            )}
        >
            <select
                aria-label={ariaLabel}
                value={encodeShiftValue(value)}
                disabled={disabled}
                onChange={(event) =>
                    onChange(decodeShiftValue(event.target.value))
                }
                className={cn(
                    'border-input bg-background focus-visible:border-ring focus-visible:ring-ring/50 disabled:bg-muted h-9 rounded-md border text-sm outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-70',
                    compact ? 'w-20 px-1 text-center text-xs' : 'w-full px-3',
                    holidayHelpOnly &&
                        'border-orange-500 bg-orange-100 text-orange-950 dark:border-orange-400 dark:bg-orange-950 dark:text-orange-50',
                )}
            >
                <option
                    value={
                        pendingHelpAtSelectedStore
                            ? encodeShiftValue(value)
                            : ''
                    }
                >
                    {compact || pendingHelpAtSelectedStore ? '－' : '未設定'}
                </option>
                {workOptions.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
                {!holidayHelpOnly && (
                    <>
                        <option value="off">{compact ? '休' : '休み'}</option>
                        <option value="absence">
                            {compact ? '急休' : '急な休み'}
                        </option>
                    </>
                )}
            </select>
        </div>
    );
}

function SeparateShiftSelect({
    value,
    stores,
    selectedStoreId,
    availableStoreIds,
    onChange,
    disabled = false,
    compact = false,
    holidayHelpOnly = false,
    ariaLabel,
}: ShiftSelectProps) {
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
        const workKind =
            kind === 'early' || kind === 'help' || kind.startsWith('time:');
        const nextStoreId =
            kind === 'help'
                ? (availableStores.find(
                      (store) => Number(store.id) !== selectedStoreId,
                  )?.id ?? null)
                : defaultStoreId;
        onChange(
            decodeShiftValue(workKind ? `${kind}@${nextStoreId ?? ''}` : kind),
        );
    };
    const isWorkShift =
        value.shift_type === 'time' ||
        value.shift_type === 'early' ||
        value.shift_type === 'help';
    const workStores =
        value.shift_type === 'help'
            ? availableStores.filter(
                  (store) => Number(store.id) !== selectedStoreId,
              )
            : availableStores;

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
                        {compact ? option.compactLabel : option.label}
                    </option>
                ))}
                <option value="early">{compact ? '早' : '早番'}</option>
                {availableStores.some(
                    (store) => Number(store.id) !== selectedStoreId,
                ) && <option value="help">他店ヘルプ</option>}
                {!holidayHelpOnly && (
                    <>
                        <option value="off">{compact ? '休' : '休み'}</option>
                        <option value="absence">
                            {compact ? '急休' : '急な休み'}
                        </option>
                    </>
                )}
            </select>

            {isWorkShift && (
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
                    {workStores.map((store) => (
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
