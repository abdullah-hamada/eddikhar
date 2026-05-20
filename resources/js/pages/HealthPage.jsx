import { useCallback } from 'react';
import { ShieldCheck, ShieldAlert, Database, Activity, RefreshCw } from 'lucide-react';
import { PageHeader } from '../components/ui/PageHeader';
import { Card, CardHeader, CardBody } from '../components/ui/Card';
import { Badge } from '../components/ui/Badge';
import { Skeleton } from '../components/ui/Skeleton';
import { ErrorState } from '../components/ui/ErrorState';
import { Button } from '../components/ui/Button';
import { endpoints } from '../lib/api';
import { useApi } from '../hooks/useApi';
import { formatMoney, formatNumber } from '../lib/format';

export default function HealthPage() {
    const fetcher = useCallback(async () => {
        try {
            return await endpoints.health();
        } catch (e) {
            // /api/health intentionally returns 503 when unhealthy.
            // The body is still useful, so unwrap it from the error.
            if (e.status === 503 && e.data) return e.data;
            throw e;
        }
    }, []);

    const { data, loading, error, refetch } = useApi(fetcher, []);

    const checks = data?.checks ?? {};
    const details = data?.details ?? {};
    const overallOk = checks.database === 'OK' && checks.ledger_integrity === 'OK';

    return (
        <div>
            <PageHeader
                title="System health"
                description="Live operational checks: database connectivity and ledger integrity."
                action={
                    <Button
                        variant="secondary"
                        size="sm"
                        leftIcon={<RefreshCw className="size-3.5" />}
                        onClick={refetch}
                        loading={loading}
                    >
                        Refresh
                    </Button>
                }
            />

            {error ? (
                <Card>
                    <ErrorState error={error} onRetry={refetch} />
                </Card>
            ) : (
                <div className="space-y-6">
                    <Card>
                        <CardBody>
                            {loading ? (
                                <Skeleton className="h-12 w-full" />
                            ) : (
                                <div className="flex items-center gap-4">
                                    <div
                                        className={`flex size-10 items-center justify-center rounded-full ${
                                            overallOk
                                                ? 'bg-[var(--color-success-soft)] text-[var(--color-success)]'
                                                : 'bg-[var(--color-danger-soft)] text-[var(--color-danger)]'
                                        }`}
                                    >
                                        {overallOk ? <ShieldCheck className="size-5" /> : <ShieldAlert className="size-5" />}
                                    </div>
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <h2 className="text-base font-semibold text-[var(--color-text-primary)]">
                                                {overallOk ? 'All systems operational' : 'Issues detected'}
                                            </h2>
                                            <Badge tone={overallOk ? 'success' : 'danger'}>
                                                {overallOk ? 'Healthy' : 'Unhealthy'}
                                            </Badge>
                                        </div>
                                        <p className="mt-0.5 text-[13px] text-[var(--color-text-tertiary)]">
                                            Snapshot taken at {data?.timestamp ?? '—'}.
                                        </p>
                                    </div>
                                </div>
                            )}
                        </CardBody>
                    </Card>

                    <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <CheckCard
                            icon={Database}
                            title="Database"
                            status={checks.database}
                            loading={loading}
                            description="PDO connection liveness probe."
                            error={details.db_error}
                        />
                        <CheckCard
                            icon={Activity}
                            title="Ledger integrity"
                            status={checks.ledger_integrity}
                            loading={loading}
                            description="Cached wallet balances vs immutable ledger sums."
                            error={details.integrity_error}
                        />
                    </div>

                    {details.ledger ? (
                        <Card>
                            <CardHeader title="Ledger snapshot" />
                            <div className="grid grid-cols-2 gap-px bg-[var(--color-border)] md:grid-cols-4">
                                <Stat label="Total credits" value={formatMoney(details.ledger.total_credits)} />
                                <Stat label="Total debits" value={formatMoney(details.ledger.total_debits)} />
                                <Stat
                                    label="Expected balance"
                                    value={formatMoney(details.ledger.expected_balance)}
                                />
                                <Stat
                                    label="Actual balance"
                                    value={formatMoney(details.ledger.actual_balance)}
                                />
                                <Stat
                                    label="Wallets checked"
                                    value={formatNumber(details.ledger.total_wallets_checked)}
                                />
                                <Stat
                                    label="Mismatched"
                                    value={formatNumber(details.ledger.mismatched_wallets_count)}
                                    tone={details.ledger.mismatched_wallets_count > 0 ? 'danger' : 'success'}
                                />
                            </div>
                        </Card>
                    ) : null}

                    {details.mismatches?.length ? (
                        <Card>
                            <CardHeader
                                title="Mismatched wallets"
                                description="Wallets where the cached balance does not equal the ledger-derived balance."
                            />
                            <div className="overflow-x-auto">
                                <table className="w-full">
                                    <thead>
                                        <tr className="border-b border-[var(--color-border)] bg-[var(--color-bg-subtle)]">
                                            <Th>Wallet</Th>
                                            <Th align="right">Cached</Th>
                                            <Th align="right">Ledger derived</Th>
                                            <Th align="right">Drift</Th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {details.mismatches.map((m) => (
                                            <tr key={m.wallet_id} className="border-b border-[var(--color-border)] last:border-b-0">
                                                <td className="px-4 py-3 font-mono text-[12px] text-[var(--color-text-secondary)]">
                                                    {m.wallet_id}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular text-[var(--color-text-primary)]">
                                                    {formatMoney(m.cached_balance)}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular text-[var(--color-text-primary)]">
                                                    {formatMoney(m.ledger_derived)}
                                                </td>
                                                <td className="px-4 py-3 text-right tabular font-semibold text-[var(--color-danger)]">
                                                    {formatMoney(m.cached_balance - m.ledger_derived)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </Card>
                    ) : null}
                </div>
            )}
        </div>
    );
}

function CheckCard({ icon: Icon, title, status, loading, description, error }) {
    const ok = status === 'OK';
    return (
        <Card>
            <CardBody className="flex items-start gap-4">
                <div
                    className={`flex size-9 shrink-0 items-center justify-center rounded-md ${
                        ok
                            ? 'bg-[var(--color-success-soft)] text-[var(--color-success)]'
                            : 'bg-[var(--color-danger-soft)] text-[var(--color-danger)]'
                    }`}
                >
                    <Icon className="size-4" />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex items-center justify-between gap-3">
                        <h3 className="text-sm font-semibold text-[var(--color-text-primary)]">{title}</h3>
                        {loading ? (
                            <Skeleton className="h-5 w-12" />
                        ) : (
                            <Badge tone={ok ? 'success' : 'danger'}>{status ?? 'Unknown'}</Badge>
                        )}
                    </div>
                    <p className="mt-1 text-[13px] text-[var(--color-text-tertiary)]">{description}</p>
                    {error ? (
                        <p className="mt-2 rounded-md border border-[#fecaca] bg-[var(--color-danger-soft)] px-3 py-2 text-[12px] text-[var(--color-danger)]">
                            {error}
                        </p>
                    ) : null}
                </div>
            </CardBody>
        </Card>
    );
}

function Stat({ label, value, tone = 'neutral' }) {
    const toneClass = {
        neutral: 'text-[var(--color-text-primary)]',
        success: 'text-[var(--color-success)]',
        danger: 'text-[var(--color-danger)]',
    }[tone];
    return (
        <div className="bg-[var(--color-surface)] px-5 py-4">
            <div className="text-[11px] font-medium uppercase tracking-wide text-[var(--color-text-tertiary)]">
                {label}
            </div>
            <div className={`tabular mt-1 text-base font-semibold ${toneClass}`}>{value}</div>
        </div>
    );
}

function Th({ children, align = 'left' }) {
    return (
        <th
            className={`px-4 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-[var(--color-text-tertiary)]`}
            style={{ textAlign: align }}
        >
            {children}
        </th>
    );
}
