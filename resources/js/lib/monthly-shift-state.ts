export function monthlyShiftOverrideKey(
    storeId: number,
    staffId: number,
    date: string,
): string {
    return `${storeId}:${staffId}:${date}`;
}

export function withoutMonthlyShiftOverride(
    overrides: Record<string, string>,
    key: string,
): Record<string, string> {
    if (!(key in overrides)) {
        return overrides;
    }

    const next = { ...overrides };
    delete next[key];

    return next;
}
