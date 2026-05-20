import { useCallback, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Wallet } from 'lucide-react';
import { PageHeader } from '../components/ui/PageHeader';
import { DataTable } from '../components/ui/DataTable';
import { StatusBadge, Badge } from '../components/ui/Badge';
import { Select } from '../components/ui/Input';
import { Pagination } from '../components/ui/Pagination';
import { endpoints } from '../lib/api';
import { useApi } from '../hooks/useApi';
import { formatDateTime, formatMoney, shortId, titleCase } from '../lib/format';

export default function WalletsPage() {
    const navigate = useNavigate();
    const [type, setType] = useState('');
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);

    const fetcher = useCallback(
        () => endpoints.wallets.list({ type: type || undefined, status: status || undefined, page, per_page: 20 }),
        [type, status, page],
    );

    const { data, loading, error, refetch } = useApi(fetcher, [type, status, page]);

    const columns = [
        {
            key: 'employee_name',
            header: 'Owner',
            render: (row) => (
                <div className="min-w-0">
                    <div className="font-medium text-[var(--color-text-primary)]">
                        {row.employee_name ?? '—'}
                    </div>
                    <div className="font-mono text-[11px] text-[var(--color-text-tertiary)]">
                        {shortId(row.id)}
                    </div>
                </div>
            ),
        },
        {
            key: 'type',
            header: 'Type',
            render: (row) => <Badge tone="neutral">{titleCase(row.type)}</Badge>,
        },
        {
            key: 'available_balance',
            header: 'Available',
            align: 'right',
            render: (row) => (
                <span className="tabular font-medium text-[var(--color-text-primary)]">
                    {formatMoney(row.available_balance, row.currency)}
                </span>
            ),
        },
        {
            key: 'held_balance',
            header: 'Held',
            align: 'right',
            render: (row) => (
                <span className="tabular text-[var(--color-text-secondary)]">
                    {formatMoney(row.held_balance, row.currency)}
                </span>
            ),
        },
        {
            key: 'balance',
            header: 'Total',
            align: 'right',
            render: (row) => (
                <span className="tabular text-[var(--color-text-secondary)]">
                    {formatMoney(row.balance, row.currency)}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge status={row.status} />,
        },
        {
            key: 'created_at',
            header: 'Created',
            render: (row) => (
                <span className="text-[13px] text-[var(--color-text-secondary)]">
                    {formatDateTime(row.created_at)}
                </span>
            ),
        },
    ];

    return (
        <div>
            <PageHeader title="Wallets" description="Every employee wallet with live balances and reservations." />

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <Select value={type} onChange={(e) => { setType(e.target.value); setPage(1); }}>
                    <option value="">All types</option>
                    <option value="salary">Salary</option>
                    <option value="savings">Savings</option>
                    <option value="bonus">Bonus</option>
                </Select>
                <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="closed">Closed</option>
                    <option value="frozen">Frozen</option>
                </Select>
            </div>

            <DataTable
                columns={columns}
                rows={data?.data}
                getRowKey={(row) => row.id}
                onRowClick={(row) => navigate(`/wallets/${row.id}`)}
                loading={loading}
                error={error}
                onRetry={refetch}
                emptyTitle="No wallets"
                emptyDescription="Adjust filters or create a wallet for an employee."
                emptyIcon={Wallet}
            />

            <Pagination meta={data?.meta} onPageChange={setPage} />
        </div>
    );
}
