import type { StoreOption } from '@/types';

export const selectableHistoryStores = (
    stores: StoreOption[],
    allowInactiveStoreId?: number,
) =>
    stores.filter(
        (store) => store.is_active || store.id === allowInactiveStoreId,
    );

export const storeSelectionPlaceholder = (
    stores: StoreOption[],
    selectedStore: StoreOption | null,
) => {
    if (stores.length === 0) return '店舗なし';
    if (!selectedStore) return '店舗を選択';
    return null;
};
