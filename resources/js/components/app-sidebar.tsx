import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    CalendarDays,
    Clock3,
    LayoutGrid,
    MoonStar,
    ReceiptText,
    UsersRound,
    WalletCards,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'ダッシュボード',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: '店舗',
        href: '/stores',
        icon: Building2,
    },
    {
        title: 'スタッフ',
        href: '/staffs',
        icon: UsersRound,
    },
    {
        title: 'シフト',
        href: '/shifts/monthly',
        icon: CalendarDays,
    },
    {
        title: '勤怠',
        href: '/attendance/daily',
        icon: Clock3,
    },
    {
        title: '給与',
        href: '/payrolls',
        icon: WalletCards,
    },
    {
        title: '深夜加算設定',
        href: '/settings/late-night-rates',
        icon: MoonStar,
    },
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const visibleNavItems = auth.can_view_income_tax_status
        ? [
              ...mainNavItems,
              {
                  title: '所得税額表状況',
                  href: '/settings/income-tax-status',
                  icon: ReceiptText,
              },
          ]
        : mainNavItems;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={visibleNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
