import type { ShiftType, ShiftValue } from '@/types';
import { cn } from '@/lib/utils';

const hourlyOptions = Array.from({ length: 24 }, (_, hour) => {
    const hourText = String(hour).padStart(2, '0');

    return {
        value: `time:${hourText}:00`,
        time: `${hourText}:00`,
        compactLabel: hourText,
    };
});

export function encodeShiftValue(value: ShiftValue): string {
    if (value.shift_type === 'time' && value.start_time) {
        return `time:${value.start_time.slice(0, 5)}`;
    }

    return value.shift_type ?? '';
}

export function decodeShiftValue(value: string): ShiftValue {
    if (value.startsWith('time:')) {
        return {
            shift_type: 'time',
            start_time: value.slice(5),
        };
    }

    return {
        shift_type: (value || null) as ShiftType | null,
        start_time: null,
    };
}

export default function ShiftSelect({
    value,
    onChange,
    disabled = false,
    compact = false,
    ariaLabel,
}: {
    value: ShiftValue;
    onChange: (value: ShiftValue) => void;
    disabled?: boolean;
    compact?: boolean;
    ariaLabel: string;
}) {
    return (
        <select
            aria-label={ariaLabel}
            value={encodeShiftValue(value)}
            disabled={disabled}
            onChange={(event) => onChange(decodeShiftValue(event.target.value))}
            className={cn(
                'border-input bg-background focus-visible:border-ring focus-visible:ring-ring/50 disabled:bg-muted h-9 rounded-md border text-sm outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-70',
                compact ? 'w-14 px-1 text-center' : 'w-full min-w-32 px-3',
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
        </select>
    );
}
