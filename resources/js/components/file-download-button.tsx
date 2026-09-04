import { Download, LoaderCircle, RotateCw } from 'lucide-react';
import { type ComponentProps, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    downloadFilename,
    downloadResponseErrorMessage,
} from '@/lib/file-download';

type Props = Omit<
    ComponentProps<typeof Button>,
    'asChild' | 'children' | 'onClick'
> & {
    url: string;
    label: string;
    fallbackFilename: string;
    iconOnly?: boolean;
};

export default function FileDownloadButton({
    url,
    label,
    fallbackFilename,
    iconOnly = false,
    disabled,
    title,
    ...buttonProps
}: Props) {
    const [status, setStatus] = useState<'idle' | 'downloading' | 'error'>(
        'idle',
    );
    const [error, setError] = useState<string | null>(null);
    const downloading = status === 'downloading';
    const visibleLabel =
        status === 'error' ? '再試行' : downloading ? '生成中…' : label;

    const download = async () => {
        setStatus('downloading');
        setError(null);

        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                const message = downloadResponseErrorMessage(
                    response.status,
                    await response.json().catch(() => null),
                );
                throw new Error(message);
            }

            const file = await response.blob();
            if (file.size === 0) {
                throw new Error('生成されたファイルが空です。');
            }
            const objectUrl = URL.createObjectURL(file);
            const anchor = document.createElement('a');
            anchor.href = objectUrl;
            anchor.download = downloadFilename(
                response.headers.get('Content-Disposition'),
                fallbackFilename,
            );
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
            setStatus('idle');
        } catch (exception) {
            const message =
                exception instanceof Error
                    ? exception.message
                    : 'ファイルを生成できませんでした。';
            setError(message);
            setStatus('error');
            toast.error(`${message} 再試行してください。`);
        }
    };

    return (
        <Button
            type="button"
            {...buttonProps}
            disabled={disabled || downloading}
            aria-busy={downloading}
            aria-label={iconOnly ? visibleLabel : undefined}
            title={error ?? title}
            onClick={download}
        >
            {downloading ? (
                <LoaderCircle className="animate-spin" />
            ) : status === 'error' ? (
                <RotateCw />
            ) : (
                <Download />
            )}
            <span className={iconOnly ? 'sr-only' : undefined}>
                {visibleLabel}
            </span>
        </Button>
    );
}
