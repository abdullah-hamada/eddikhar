import { useCallback, useState } from 'react';
import { Banknote } from 'lucide-react';
import { PageHeader } from '../components/ui/PageHeader';
import { DataTable } from '../components/ui/DataTable';
import { StatusBadge } from '../components/ui/Badge';
import { Select } from '../components/ui/Input';
import { Pagination } from '../components/ui/Pagination';
import { endpoints } from '../lib/api';
import { useApi } from '../hooks/useApi';
import { formatDateTime, formatMoney, formatRelative, shortId } from '../lib/format';

const STATUS_OPTIONS = [
    { value: '', label: 'All statuses' },
    { value: 'initiated', label: 'Initiated' },
    { value: 'pending', label: 'Pending' },
    { value: 'success', label: 'Succeeded' },
    { value: 'failed', label: 'Failed' },
    { value: 'reversed', label: 'Reversed' },
];

export default function WithdrawalsPage() {
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);

    const fetcher = useCallback(
        () =>
            endpoints.bankPayments.list({
                status: status || undefined,
                page,
                per_page: 25,
            }),
        [status, page],
    );

    const { data, loading, error, refetch } = useApi(fetcher, [status, page]);

    const columns = [
        {
            key: 'created_at',
            header: 'Initiated',
            render: (row) => (
                <div>
                    <div className="text-[13px] text-[var(--color-text-primary)]">
                        {formatRelative(row.initiated_at ?? row.created_at)}
                    </div>
                    <div className="text-[11px] text-[var(--color-text-tertiary)]">
                        {formatDateTime(row.initiated_at ?? row.created_at)}
                    </div>
                </div>
            ),
        },
        {
            key: 'employee_name',
            header: 'Employee',
            render: (row) => (
                <div className="min-w-0">
                    <div className="font-medium text-[var(--color-text-primary)]">
                        {row.employee_name ?? 'System'}
                    </div>
                    <div className="font-mono text-[11px] text-[var(--color-text-tertiary)]">
                        wallet {shortId(row.wallet_id)}
                    </div>
                </div>
            ),
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            render: (row) => (
                <span className="tabular font-medium text-[var(--color-text-primary)]">
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
            key: 'confirmed_at',
            header: 'Confirmed',
            render: (row) => (
                <span className="text-[13px] text-[var(--color-text-secondary)]">
                    {row.confirmed_at ? formatRelative(row.confirmed_at) : '—'}
                </span>
            ),
        },
        {
            key: 'external_reference',
            header: 'Bank ref',
            render: (row) => (
                <span className="font-mono text-[12px] text-[var(--color-text-tertiary)]">
                    {row.external_reference ?? '—'}
                </span>
            ),
        },
    ];

    return (
        <div>
            <PageHeader
                title="Withdrawals"
                description="End-to-end view of employee withdrawal flows and their bank settlement state."
            />

            <div className="mb-4">
                <Select
                    value={status}
                    onChange={(e) => { setStatus(e.target.value); setPage(1); }}
                >
                    {STATUS_OPTIONS.map((opt) => (
                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                    ))}
                </Select>
            </div>

            <DataTable
                columns={columns}
                rows={data?.data}
                getRowKey={(row) => row.id}
                loading={loading}
                error={error}
                onRetry={refetch}
                emptyTitle="No withdrawals"
                emptyDescription="Withdrawals you initiate from a wallet will appear here."
                emptyIcon={Banknote}
            />

            <Pagination meta={data?.meta} onPageChange={setPage} />
        </div>
    );
}
