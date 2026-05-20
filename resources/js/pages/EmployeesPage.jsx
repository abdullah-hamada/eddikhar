import { useCallback, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Search, Users } from 'lucide-react';
import { PageHeader } from '../components/ui/PageHeader';
import { DataTable } from '../components/ui/DataTable';
import { StatusBadge } from '../components/ui/Badge';
import { Input, Select } from '../components/ui/Input';
import { Pagination } from '../components/ui/Pagination';
import { endpoints } from '../lib/api';
import { useApi } from '../hooks/useApi';
import { formatDateTime, shortId } from '../lib/format';

export default function EmployeesPage() {
    const navigate = useNavigate();
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('');
    const [page, setPage] = useState(1);

    const fetcher = useCallback(
        () => endpoints.employees.list({ search: search || undefined, status: status || undefined, page, per_page: 15 }),
        [search, status, page],
    );

    const { data, loading, error, refetch } = useApi(fetcher, [search, status, page]);

    const columns = [
        {
            key: 'name',
            header: 'Employee',
            render: (row) => (
                <div className="min-w-0">
                    <div className="font-medium text-[var(--color-text-primary)]">
                        {row.first_name} {row.last_name}
                    </div>
                    <div className="text-[12px] text-[var(--color-text-tertiary)]">{row.email}</div>
                </div>
            ),
        },
        {
            key: 'external_id',
            header: 'External ID',
            render: (row) => (
                <span className="font-mono text-[12px] text-[var(--color-text-secondary)]">
                    {row.external_id ?? '—'}
                </span>
            ),
        },
        {
            key: 'id',
            header: 'ID',
            render: (row) => (
                <span className="font-mono text-[12px] text-[var(--color-text-tertiary)]">{shortId(row.id)}</span>
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
            <PageHeader title="Employees" description="Search, filter, and inspect employee records." />

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <div className="sm:w-80">
                    <Input
                        placeholder="Search by name or email"
                        leftIcon={<Search className="size-4" />}
                        value={search}
                        onChange={(e) => {
                            setSearch(e.target.value);
                            setPage(1);
                        }}
                    />
                </div>
                <Select
                    value={status}
                    onChange={(e) => {
                        setStatus(e.target.value);
                        setPage(1);
                    }}
                >
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="terminated">Terminated</option>
                </Select>
            </div>

            <DataTable
                columns={columns}
                rows={data?.data}
                getRowKey={(row) => row.id}
                onRowClick={(row) => navigate(`/employees/${row.id}`)}
                loading={loading}
                error={error}
                onRetry={refetch}
                emptyTitle="No employees match"
                emptyDescription="Adjust the search or filters to see more results."
                emptyIcon={Users}
            />

            <Pagination meta={data?.meta} onPageChange={setPage} />
        </div>
    );
}
