import { useCallback } from 'react';
import { Link } from 'react-router-dom';
import {
    Users,
    Wallet,
    ArrowLeftRight,
    ShieldCheck,
    ShieldAlert,
    Banknote,
    CalendarClock,
} from 'lucide-react';
import { PageHeader } from '../components/ui/PageHeader';
import { StatCard } from '../components/ui/StatCard';
import { Card, CardHeader } from '../components/ui/Card';
import { Skeleton } from '../components/ui/Skeleton';
import { ErrorState } from '../components/ui/ErrorState';
import { EmptyState } from '../components/ui/EmptyState';
import { Badge } from '../components/ui/Badge';
import { TransactionAmount } from '../components/TransactionAmount';
import { endpoints } from '../lib/api';
import { useApi } from '../hooks/useApi';
import { formatMoney, formatNumber, formatRelative, titleCase } from '../lib/format';

export default function DashboardPage() {
    const summary = useApi(useCallback(() => endpoints.dashboard.summary(), []));
    const activity = useApi(useCallback(() => endpoints.dashboard.recentActivity(), []));

    const data = summary.data;

    return (
        <div>
            <PageHeader
                title="Dashboard"
                description="Real-time view of wallets, ledger health, and money movement."
            />

            {summary.error ? (
                <Card className="mb-6">
                    <ErrorState error={summary.error} onRetry={summary.refetch} />
                </Card>
            ) : null}

            <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Available balance"
                    value={data ? formatMoney(data.ledger.available_balance) : '—'}
                    hint="Total liquid funds across wallets"
                    icon={Wallet}
                    loading={summary.loading}
                />
                <StatCard
                    label="Held balance"
                    value={data ? formatMoney(data.ledger.held_balance) : '—'}
                    hint="Funds reserved for in-flight withdrawals"
                    tone="warning"
                    loading={summary.loading}
                />
                <StatCard
                    label="Employees"
                    value={
                        data
                            ? `${formatNumber(data.totals.active_employees)} / ${formatNumber(data.totals.employees)}`
                            : '—'
                    }
                    hint="Active out of total"
                    icon={Users}
                    loading={summary.loading}
                />
                <StatCard
                    label="Transactions"
                    value={data ? formatNumber(data.totals.transactions) : '—'}
                    hint="Immutable ledger entries written"
                    icon={ArrowLeftRight}
                    loading={summary.loading}
                />
            </section>

            <section className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <Card>
                        <CardHeader
                            title="Recent activity"
                            description="Latest credits and debits across all wallets."
                            action={
                                <Link
                                    to="/transactions"
                                    className="text-[13px] font-medium text-[var(--color-accent)] hover:underline"
                                >
                                    View all
                                </Link>
                            }
                        />
                        <ActivityList state={activity} />
                    </Card>
                </div>

                <div className="space-y-6">
                    <IntegrityCard data={data} loading={summary.loading} />
                    <PipelineCard
                        title="Bank payments"
                        link="/bank-payments"
                        icon={Banknote}
                        stats={[
                            { label: 'Pending', value: data?.bank_payments.pending, tone: 'warning' },
                            { label: 'Succeeded', value: data?.bank_payments.succeeded, tone: 'success' },
                            { label: 'Failed', value: data?.bank_payments.failed, tone: 'danger' },
                        ]}
                        loading={summary.loading}
                    />
                    <PipelineCard
                        title="Payroll events"
                        link="/payroll-events"
                        icon={CalendarClock}
                        stats={[
                            { label: 'Received', value: data?.payroll_events.received, tone: 'info' },
                            { label: 'Processed', value: data?.payroll_events.processed, tone: 'success' },
                            { label: 'Failed', value: data?.payroll_events.failed, tone: 'danger' },
                        ]}
                        loading={summary.loading}
                    />
                </div>
            </section>
        </div>
    );
}

function ActivityList({ state }) {
    if (state.loading) {
        return (
            <div className="divide-y divide-[var(--color-border)]">
                {Array.from({ length: 6 }).map((_, idx) => (
                    <div key={idx} className="flex items-center justify-between gap-4 px-5 py-3.5">
                        <div className="min-w-0 flex-1 space-y-1.5">
                            <Skeleton className="h-3.5 w-1/3" />
                            <Skeleton className="h-3 w-2/3" />
                        </div>
                        <Skeleton className="h-3.5 w-20" />
                    </div>
                ))}
            </div>
        );
    }

    if (state.error) {
        return <ErrorState error={state.error} onRetry={state.refetch} />;
    }

    const rows = state.data?.data ?? [];
    if (rows.length === 0) {
        return <EmptyState title="No recent activity" description="Transactions will appear here as they are created." />;
    }

    return (
        <ul className="divide-y divide-[var(--color-border)]">
            {rows.map((entry) => (
                <li key={entry.id} className="flex items-center justify-between gap-4 px-5 py-3.5">
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                            <span className="truncate text-sm font-medium text-[var(--color-text-primary)]">
                                {entry.employee_name}
                            </span>
                            {entry.wallet_type ? (
                                <Badge tone="neutral">{titleCase(entry.wallet_type)}</Badge>
                            ) : null}
                        </div>
                        <div className="mt-0.5 truncate text-[12px] text-[var(--color-text-tertiary)]">
                            {entry.description || titleCase(entry.reference_type) || '—'} ·{' '}
                            {formatRelative(entry.created_at)}
                        </div>
                    </div>
                    <TransactionAmount type={entry.type} amount={entry.amount} />
                </li>
            ))}
        </ul>
    );
}

function IntegrityCard({ data, loading }) {
    const ok = data?.ledger.integrity_ok;
    const Icon = ok ? ShieldCheck : ShieldAlert;
    const tone = ok ? 'success' : 'danger';

    return (
        <Card>
            <CardHeader title="Ledger integrity" />
            <div className="px-5 py-4">
                {loading ? (
                    <Skeleton className="h-16 w-full" />
                ) : (
                    <div className="flex items-start gap-3">
                        <div
                            className={`flex size-8 shrink-0 items-center justify-center rounded-full ${
                                ok
                                    ? 'bg-[var(--color-success-soft)] text-[var(--color-success)]'
                                    : 'bg-[var(--color-danger-soft)] text-[var(--color-danger)]'
                            }`}
                        >
                            <Icon className="size-4" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2">
                                <span className="text-sm font-semibold text-[var(--color-text-primary)]">
                                    {ok ? 'Healthy' : 'Mismatch detected'}
                                </span>
                                <Badge tone={tone}>{ok ? 'OK' : 'FAIL'}</Badge>
                            </div>
                            <p className="mt-1 text-[12px] text-[var(--color-text-tertiary)]">
                                Cached vs ledger-derived balance: {formatMoney(data?.ledger.cached_balance ?? 0)} ={' '}
                                {formatMoney(
                                    (data?.ledger.total_credits ?? 0) - (data?.ledger.total_debits ?? 0),
                                )}
                            </p>
                            <Link
                                to="/health"
                                className="mt-2 inline-flex text-[12px] font-medium text-[var(--color-accent)] hover:underline"
                            >
                                Open system health →
                            </Link>
                        </div>
                    </div>
                )}
            </div>
        </Card>
    );
}

function PipelineCard({ title, link, icon: Icon, stats, loading }) {
    return (
        <Card>
            <CardHeader
                title={title}
                action={
                    <Link
                        to={link}
                        className="text-[13px] font-medium text-[var(--color-accent)] hover:underline"
                    >
                        View
                    </Link>
                }
            />
            <div className="grid grid-cols-3 divide-x divide-[var(--color-border)]">
                {stats.map((stat) => (
                    <div key={stat.label} className="px-4 py-3.5 text-center">
                        <div className="text-[11px] font-medium uppercase tracking-wide text-[var(--color-text-tertiary)]">
                            {stat.label}
                        </div>
                        {loading ? (
                            <Skeleton className="mx-auto mt-1.5 h-5 w-10" />
                        ) : (
                            <div
                                className={`tabular mt-1 text-lg font-semibold ${toneText(stat.tone)}`}
                            >
                                {formatNumber(stat.value ?? 0)}
                            </div>
                        )}
                    </div>
                ))}
            </div>
        </Card>
    );
}

function toneText(tone) {
    return {
        success: 'text-[var(--color-success)]',
        warning: 'text-[var(--color-warning)]',
        danger: 'text-[var(--color-danger)]',
        info: 'text-[var(--color-info)]',
    }[tone] ?? 'text-[var(--color-text-primary)]';
}
