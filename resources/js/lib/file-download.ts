export const downloadFilename = (
    contentDisposition: string | null,
    fallback: string,
) => {
    if (!contentDisposition) return fallback;

    const encoded = contentDisposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
    if (encoded) {
        try {
            return decodeURIComponent(encoded.replace(/^"|"$/g, ''));
        } catch {
            return fallback;
        }
    }

    return (
        contentDisposition.match(/filename="([^"]+)"/i)?.[1] ??
        contentDisposition.match(/filename=([^;]+)/i)?.[1]?.trim() ??
        fallback
    );
};

export const downloadErrorMessage = (
    payload: unknown,
    fallback = 'ファイルを生成できませんでした。',
) => {
    if (!payload || typeof payload !== 'object') return fallback;

    const response = payload as {
        message?: unknown;
        errors?: Record<string, unknown>;
    };
    const firstError = response.errors
        ? Object.values(response.errors)[0]
        : undefined;

    if (Array.isArray(firstError) && typeof firstError[0] === 'string') {
        return firstError[0];
    }
    if (typeof firstError === 'string') return firstError;
    if (typeof response.message === 'string' && response.message.trim()) {
        return response.message;
    }

    return fallback;
};

export const downloadResponseErrorMessage = (
    status: number,
    payload: unknown,
) =>
    status >= 500
        ? 'ファイル生成中にエラーが発生しました。'
        : downloadErrorMessage(payload);
