import { useCallback, useState } from 'react';
import { Building2 } from 'lucide-react';
import { PageHeader } from '../components/ui/PageHeader';
import { DataTable } from '../components/ui/DataTable';
import { StatusBadge } from '../components/ui/Badge';
import { Select } from '../components/ui/Input';
import { Pagination } from '../components/ui/Pagination';
import { endpoints } from '../lib/api';
import { useApi } from '../hooks/useApi';
import { formatDateTime, formatMoney, shortId } from '../lib/format';

export default function BankPaymentsPage() {
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);

    const fetcher = useCallback(
        () => endpoints.bankPayments.list({ status: status || undefined, page, per_page: 25 }),
        [status, page],
    );

    const { data, loading, error, refetch } = useApi(fetcher, [status, page]);

    const columns = [
        {
            key: 'created_at',
            header: 'Created',
            render: (row) => (
                <span className="text-[13px] text-[var(--color-text-secondary)]">
                    {formatDateTime(row.created_at)}
                </span>
            ),
        },
        {
            key: 'employee_name',
            header: 'Employee',
            render: (row) => (
                <span className="text-sm text-[var(--color-text-primary)]">
                    {row.employee_name ?? '—'}
                </span>
            ),
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            render: (row) => (
                <span className="tabular text-sm font-medium text-[var(--color-text-primary)]">
                    {formatMoney(row.amount, row.currency)}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge status={row.status} />,
        },
        {
            key: 'external_reference',
            header: 'Bank reference',
            render: (row) => (
                <span className="font-mono text-[12px] text-[var(--color-text-secondary)]">
                    {row.external_reference ?? '—'}
                </span>
            ),
        },
        {
            key: 'idempotency_key',
            header: 'Idempotency key',
            render: (row) => (
                <span className="font-mono text-[12px] text-[var(--color-text-tertiary)]" title={row.idempotency_key}>
                    {row.idempotency_key ? shortId(row.idempotency_key) : '—'}
                </span>
            ),
        },
        {
            key: 'confirmed_at',
            header: 'Confirmed',
            render: (row) => (
                <span className="text-[12px] text-[var(--color-text-secondary)]">
                    {row.confirmed_at ? formatDateTime(row.confirmed_at) : '—'}
                </span>
            ),
        },
    ];

    return (
        <div>
            <PageHeader
                title="Bank payments"
                description="Low-level audit trail for outbound bank transfers, including idempotency keys and external references."
            />

            <div className="mb-4">
                <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
                    <option value="">All statuses</option>
                    <option value="initiated">Initiated</option>
                    <option value="pending">Pending</option>
                    <option value="success">Succeeded</option>
                    <option value="failed">Failed</option>
                    <option value="reversed">Reversed</option>
                </Select>
            </div>

            <DataTable
                columns={columns}
                rows={data?.data}
                getRowKey={(row) => row.id}
                loading={loading}
                error={error}
                onRetry={refetch}
                emptyTitle="No bank payments"
                emptyDescription="Outbound bank transfers will appear here once initiated."
                emptyIcon={Building2}
            />

            <Pagination meta={data?.meta} onPageChange={setPage} />
        </div>
    );
}
