import { useCallback, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeftRight } from 'lucide-react';
import { PageHeader } from '../components/ui/PageHeader';
import { DataTable } from '../components/ui/DataTable';
import { Badge } from '../components/ui/Badge';
import { Select } from '../components/ui/Input';
import { Pagination } from '../components/ui/Pagination';
import { TransactionAmount } from '../components/TransactionAmount';
import { endpoints } from '../lib/api';
import { useApi } from '../hooks/useApi';
import { formatDateTime, formatMoney, shortId, titleCase } from '../lib/format';

export default function TransactionsPage() {
    const [type, setType] = useState('');
    const [reference, setReference] = useState('');
    const [page, setPage] = useState(1);

    const fetcher = useCallback(
        () =>
            endpoints.transactions.list({
                type: type || undefined,
                reference_type: reference || undefined,
                page,
                per_page: 25,
            }),
        [type, reference, page],
    );

    const { data, loading, error, refetch } = useApi(fetcher, [type, reference, page]);

    const columns = [
        {
            key: 'created_at',
            header: 'Date',
            render: (row) => (
                <span className="text-[13px] text-[var(--color-text-secondary)]">
                    {formatDateTime(row.created_at)}
                </span>
            ),
        },
        {
            key: 'employee',
            header: 'Employee',
            render: (row) => (
                <div className="min-w-0">
                    <div className="truncate text-sm font-medium text-[var(--color-text-primary)]">
                        {row.employee_name}
                    </div>
                    {row.wallet_type ? (
                        <div className="mt-0.5">
                            <Badge tone="neutral">{titleCase(row.wallet_type)}</Badge>
                        </div>
                    ) : null}
                </div>
            ),
        },
        {
            key: 'description',
            header: 'Description',
            render: (row) => (
                <div className="min-w-0">
                    <div className="truncate text-sm text-[var(--color-text-primary)]">
                        {row.description || titleCase(row.reference_type) || '—'}
                    </div>
                    {row.reference_type ? (
                        <div className="text-[11px] text-[var(--color-text-tertiary)]">
                            ref: {row.reference_type}
                        </div>
                    ) : null}
                </div>
            ),
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            render: (row) => <TransactionAmount type={row.type} amount={row.amount} />,
        },
        {
            key: 'balance_after',
            header: 'Balance',
            align: 'right',
            render: (row) => (
                <span className="tabular text-[13px] text-[var(--color-text-secondary)]">
                    {formatMoney(row.balance_after)}
                </span>
            ),
        },
        {
            key: 'wallet',
            header: 'Wallet',
            render: (row) => (
                <Link
                    to={`/wallets/${row.wallet_id}`}
                    className="font-mono text-[12px] text-[var(--color-accent)] hover:underline"
                >
                    {shortId(row.wallet_id)}
                </Link>
            ),
        },
    ];

    return (
        <div>
            <PageHeader
                title="Transactions"
                description="Append-only ledger of every credit and debit, across all wallets."
            />

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <Select value={type} onChange={(e) => { setType(e.target.value); setPage(1); }}>
                    <option value="">All types</option>
                    <option value="credit">Credits only</option>
                    <option value="debit">Debits only</option>
                </Select>
                <Select value={reference} onChange={(e) => { setReference(e.target.value); setPage(1); }}>
                    <option value="">All sources</option>
                    <option value="payroll">Payroll</option>
                    <option value="manual">Manual</option>
                    <option value="transfer">Transfer</option>
                    <option value="withdrawal">Withdrawal</option>
                </Select>
            </div>

            <DataTable
                columns={columns}
                rows={data?.data}
                getRowKey={(row) => row.id}
                loading={loading}
                error={error}
                onRetry={refetch}
                emptyTitle="No transactions"
                emptyDescription="Adjust the filters or trigger a payroll event to see entries here."
                emptyIcon={ArrowLeftRight}
            />

            <Pagination meta={data?.meta} onPageChange={setPage} />
        </div>
    );
}
