import { Head } from '@inertiajs/react';
import { CalendarDays, Clock3, Store, UsersRound } from 'lucide-react';
import { dashboard } from '@/routes';

const cards = [
    {
        title: '本日のシフト',
        value: '準備中',
        description: 'Phase 2で実装します',
        icon: UsersRound,
    },
    {
        title: '勤怠未入力',
        value: '準備中',
        description: 'Phase 3で実装します',
        icon: Clock3,
    },
    {
        title: '選択店舗',
        value: '未選択',
        description: 'Phase 1で店舗を登録します',
        icon: Store,
    },
];

export default function Dashboard() {
    return (
        <>
            <Head title="ダッシュボード" />
            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <p className="text-muted-foreground text-sm">
                        株式会社Groovy
                    </p>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        ダッシュボード
                    </h1>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {cards.map((card) => (
                        <section
                            key={card.title}
                            className="border-border bg-card rounded-xl border p-5 shadow-sm"
                        >
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <p className="text-muted-foreground text-sm font-medium">
                                        {card.title}
                                    </p>
                                    <p className="mt-2 text-2xl font-semibold">
                                        {card.value}
                                    </p>
                                </div>
                                <div className="bg-primary/10 text-primary rounded-lg p-2.5">
                                    <card.icon className="size-5" />
                                </div>
                            </div>
                            <p className="text-muted-foreground mt-4 text-sm">
                                {card.description}
                            </p>
                        </section>
                    ))}
                </div>

                <section className="border-border bg-card rounded-xl border p-5 shadow-sm">
                    <div className="flex items-center gap-3">
                        <CalendarDays className="text-primary size-5" />
                        <div>
                            <h2 className="font-semibold">システム基盤</h2>
                            <p className="text-muted-foreground text-sm">
                                Docker環境と認証基盤が利用できます。業務機能はロードマップ順に追加します。
                            </p>
                        </div>
                    </div>
                </section>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'ダッシュボード',
            href: dashboard(),
        },
    ],
};
