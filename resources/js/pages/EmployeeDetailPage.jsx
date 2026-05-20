import { useCallback } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Wallet } from 'lucide-react';
import { PageHeader } from '../components/ui/PageHeader';
import { Card, CardHeader } from '../components/ui/Card';
import { StatusBadge } from '../components/ui/Badge';
import { Skeleton } from '../components/ui/Skeleton';
import { ErrorState } from '../components/ui/ErrorState';
import { EmptyState } from '../components/ui/EmptyState';
import { endpoints } from '../lib/api';
import { useApi } from '../hooks/useApi';
import { formatDateTime, formatMoney, titleCase } from '../lib/format';

export default function EmployeeDetailPage() {
    const { id } = useParams();
    const { data, loading, error, refetch } = useApi(
        useCallback(() => endpoints.employees.get(id), [id]),
        [id],
    );

    const employee = data?.data;

    return (
        <div>
            <Link
                to="/employees"
                className="mb-2 inline-flex items-center gap-1.5 text-[13px] font-medium text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]"
            >
                <ArrowLeft className="size-3.5" /> Back to employees
            </Link>

            {loading ? (
                <div className="space-y-4">
                    <Skeleton className="h-8 w-64" />
                    <Skeleton className="h-32 w-full" />
                </div>
            ) : error ? (
                <Card>
                    <ErrorState error={error} onRetry={refetch} />
                </Card>
            ) : !employee ? (
                <Card>
                    <EmptyState title="Employee not found" />
                </Card>
            ) : (
                <>
                    <PageHeader
                        title={`${employee.first_name} ${employee.last_name}`}
                        description={employee.email}
                        action={<StatusBadge status={employee.status} />}
                    />

                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <Card className="lg:col-span-1">
                            <CardHeader title="Profile" />
                            <dl className="divide-y divide-[var(--color-border)]">
                                <DetailRow label="External ID" value={employee.external_id} mono />
                                <DetailRow label="ID" value={employee.id} mono />
                                <DetailRow label="Status" value={<StatusBadge status={employee.status} />} />
                                <DetailRow label="Created" value={formatDateTime(employee.created_at)} />
                                {employee.metadata?.department ? (
                                    <DetailRow label="Department" value={employee.metadata.department} />
                                ) : null}
                            </dl>
                        </Card>

                        <Card className="lg:col-span-2">
                            <CardHeader
                                title="Wallets"
                                description={`${employee.wallets?.length ?? 0} wallet${
                                    (employee.wallets?.length ?? 0) === 1 ? '' : 's'
                                }`}
                            />
                            {(employee.wallets?.length ?? 0) === 0 ? (
                                <EmptyState
                                    title="No wallets yet"
                                    description="Wallets created for this employee will appear here."
                                    icon={Wallet}
                                />
                            ) : (
                                <ul className="divide-y divide-[var(--color-border)]">
                                    {employee.wallets.map((wallet) => (
                                        <li key={wallet.id}>
                                            <Link
                                                to={`/wallets/${wallet.id}`}
                                                className="flex items-center justify-between gap-4 px-5 py-4 transition-colors hover:bg-[var(--color-surface-hover)]"
                                            >
                                                <div className="min-w-0">
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-sm font-semibold text-[var(--color-text-primary)]">
                                                            {titleCase(wallet.type)}
                                                        </span>
                                                        <StatusBadge status={wallet.status} />
                                                    </div>
                                                    <div className="mt-0.5 font-mono text-[12px] text-[var(--color-text-tertiary)]">
                                                        {wallet.id}
                                                    </div>
                                                </div>
                                                <div className="text-right">
                                                    <div className="tabular text-sm font-semibold text-[var(--color-text-primary)]">
                                                        {formatMoney(wallet.available_balance, wallet.currency)}
                                                    </div>
                                                    <div className="mt-0.5 text-[11px] text-[var(--color-text-tertiary)]">
                                                        Held: {formatMoney(wallet.held_balance, wallet.currency)}
                                                    </div>
                                                </div>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Card>
                    </div>
                </>
            )}
        </div>
    );
}

function DetailRow({ label, value, mono }) {
    return (
        <div className="flex items-center justify-between gap-4 px-5 py-3 text-[13px]">
            <dt className="text-[var(--color-text-tertiary)]">{label}</dt>
            <dd
                className={
                    mono
                        ? 'truncate font-mono text-[12px] text-[var(--color-text-secondary)]'
                        : 'text-right text-[var(--color-text-primary)]'
                }
            >
                {value || '—'}
            </dd>
        </div>
    );
}
