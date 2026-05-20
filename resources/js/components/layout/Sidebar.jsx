import { NavLink } from 'react-router-dom';
import {
    LayoutDashboard,
    Users,
    Wallet,
    ArrowLeftRight,
    Banknote,
    CalendarClock,
    Building2,
    HeartPulse,
} from 'lucide-react';
import { cn } from '../../lib/cn';

const NAV = [
    { to: '/', label: 'Dashboard', icon: LayoutDashboard, end: true },
    { section: 'Operations' },
    { to: '/employees', label: 'Employees', icon: Users },
    { to: '/wallets', label: 'Wallets', icon: Wallet },
    { to: '/transactions', label: 'Transactions', icon: ArrowLeftRight },
    { section: 'Money movement' },
    { to: '/withdrawals', label: 'Withdrawals', icon: Banknote },
    { to: '/payroll-events', label: 'Payroll events', icon: CalendarClock },
    { to: '/bank-payments', label: 'Bank payments', icon: Building2 },
    { section: 'Platform' },
    { to: '/health', label: 'System health', icon: HeartPulse },
];

export default function Sidebar({ onNavigate }) {
    return (
        <div className="flex h-full flex-col">
            <div className="flex h-14 items-center gap-2.5 border-b border-[var(--color-border)] px-4">
                <div className="flex size-7 items-center justify-center rounded-md bg-[var(--color-accent)] text-white">
                    <span className="text-xs font-bold">E</span>
                </div>
                <div className="min-w-0">
                    <div className="text-[13px] font-semibold leading-none text-[var(--color-text-primary)]">
                        Eddikhar
                    </div>
                    <div className="mt-0.5 text-[11px] leading-none text-[var(--color-text-tertiary)]">
                        Operations
                    </div>
                </div>
            </div>

            <nav className="flex-1 overflow-y-auto px-2 py-3">
                {NAV.map((item, idx) => {
                    if (item.section) {
                        return (
                            <div
                                key={`section-${idx}`}
                                className="mb-1.5 mt-4 px-2 text-[10px] font-semibold uppercase tracking-wider text-[var(--color-text-tertiary)] first:mt-0"
                            >
                                {item.section}
                            </div>
                        );
                    }
                    const Icon = item.icon;
                    return (
                        <NavLink
                            key={item.to}
                            to={item.to}
                            end={item.end}
                            onClick={onNavigate}
                            className={({ isActive }) =>
                                cn(
                                    'mb-0.5 flex items-center gap-2.5 rounded-md px-2.5 py-1.5 text-[13px] font-medium transition-colors',
                                    isActive
                                        ? 'bg-[var(--color-accent-soft)] text-[var(--color-accent)]'
                                        : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]',
                                )
                            }
                        >
                            <Icon className="size-4" />
                            <span className="truncate">{item.label}</span>
                        </NavLink>
                    );
                })}
            </nav>

            <div className="border-t border-[var(--color-border)] px-4 py-3">
                <div className="text-[11px] text-[var(--color-text-tertiary)]">
                    <div className="flex items-center gap-1.5">
                        <span className="size-1.5 rounded-full bg-[var(--color-success)]" />
                        <span>API connected</span>
                    </div>
                    <div className="mt-1 font-mono text-[10px]">v1.0</div>
                </div>
            </div>
        </div>
    );
}
