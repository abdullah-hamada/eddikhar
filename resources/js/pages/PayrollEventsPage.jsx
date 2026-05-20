import { useCallback, useState } from 'react';
import { CalendarClock, AlertCircle } from 'lucide-react';
import { PageHeader } from '../components/ui/PageHeader';
import { DataTable } from '../components/ui/DataTable';
import { StatusBadge, Badge } from '../components/ui/Badge';
import { Select } from '../components/ui/Input';
import { Pagination } from '../components/ui/Pagination';
import { endpoints } from '../lib/api';
import { useApi } from '../hooks/useApi';
import { formatDateTime, formatRelative, shortId, titleCase } from '../lib/format';

export default function PayrollEventsPage() {
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);

    const fetcher = useCallback(
        () => endpoints.payrollEvents.list({ status: status || undefined, page, per_page: 25 }),
        [status, page],
    );

    const { data, loading, error, refetch } = useApi(fetcher, [status, page]);

    const columns = [
        {
            key: 'received',
            header: 'Received',
            render: (row) => (
                <div>
                    <div className="text-[13px] text-[var(--color-text-primary)]">
                        {formatRelative(row.created_at)}
                    </div>
                    <div className="text-[11px] text-[var(--color-text-tertiary)]">
                        {formatDateTime(row.created_at)}
                    </div>
                </div>
            ),
        },
        {
            key: 'event_type',
            header: 'Event',
            render: (row) => (
                <div className="min-w-0">
                    <div className="text-sm font-medium text-[var(--color-text-primary)]">
                        {titleCase(row.event_type)}
                    </div>
                    <div className="font-mono text-[11px] text-[var(--color-text-tertiary)]">
                        {row.external_event_id ?? shortId(row.id)}
                    </div>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            render: (row) => <StatusBadge status={row.status} />,
        },
        {
            key: 'attempts',
            header: 'Attempts',
            align: 'center',
            render: (row) => {
                const tone = row.attempts > 1 ? 'warning' : 'neutral';
                return <Badge tone={tone}>{row.attempts}</Badge>;
            },
        },
        {
            key: 'processed_at',
            header: 'Processed',
            render: (row) => (
                <span className="text-[13px] text-[var(--color-text-secondary)]">
                    {row.processed_at ? formatRelative(row.processed_at) : '—'}
                </span>
            ),
        },
        {
            key: 'error',
            header: 'Error',
            render: (row) =>
                row.error_message ? (
                    <span className="inline-flex max-w-[280px] items-center gap-1.5 truncate text-[12px] text-[var(--color-danger)]">
                        <AlertCircle className="size-3.5 shrink-0" />
                        <span className="truncate">{row.error_message}</span>
                    </span>
                ) : (
                    <span className="text-[12px] text-[var(--color-text-tertiary)]">—</span>
                ),
        },
    ];

    return (
        <div>
            <PageHeader
                title="Payroll events"
                description="Webhook-driven payroll deposits with idempotency and retry visibility."
            />

            <div className="mb-4">
                <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
                    <option value="">All statuses</option>
                    <option value="received">Received</option>
                    <option value="processing">Processing</option>
                    <option value="processed">Processed</option>
                    <option value="failed">Failed</option>
                </Select>
            </div>

            <DataTable
                columns={columns}
                rows={data?.data}
                getRowKey={(row) => row.id}
                loading={loading}
                error={error}
                onRetry={refetch}
                emptyTitle="No payroll events"
                emptyDescription="Send a payload to POST /api/payroll/webhook to see events here."
                emptyIcon={CalendarClock}
            />

            <Pagination meta={data?.meta} onPageChange={setPage} />
        </div>
    );
}
