import type { ReactNode } from 'react';

export default function MasterPageHeader({
    title,
    description,
    actions,
}: {
    title: string;
    description: string;
    actions?: ReactNode;
}) {
    return (
        <header className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 className="text-2xl font-semibold tracking-tight">
                    {title}
                </h1>
                <p className="text-muted-foreground mt-1 text-sm">
                    {description}
                </p>
            </div>
            {actions && <div className="shrink-0">{actions}</div>}
        </header>
    );
}
