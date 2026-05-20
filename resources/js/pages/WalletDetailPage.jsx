import { useCallback, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, ArrowUpRight, Lock, Banknote } from 'lucide-react';
import { PageHeader } from '../components/ui/PageHeader';
import { Card, CardHeader } from '../components/ui/Card';
import { StatusBadge } from '../components/ui/Badge';
import { Skeleton } from '../components/ui/Skeleton';
import { ErrorState } from '../components/ui/ErrorState';
import { Button } from '../components/ui/Button';
import { Modal } from '../components/ui/Modal';
import { Input, Label } from '../components/ui/Input';
import { DataTable } from '../components/ui/DataTable';
import { TransactionAmount } from '../components/TransactionAmount';
import { endpoints } from '../lib/api';
import { useApi } from '../hooks/useApi';
import { formatDateTime, formatMoney, titleCase } from '../lib/format';

export default function WalletDetailPage() {
    const { id } = useParams();
    const wallet = useApi(useCallback(() => endpoints.wallets.get(id), [id]), [id]);
    const transactions = useApi(
        useCallback(() => endpoints.wallets.transactions(id, { per_page: 25 }), [id]),
        [id],
    );
    const [withdrawOpen, setWithdrawOpen] = useState(false);

    const w = wallet.data?.data;

    return (
        <div>
            <Link
                to="/wallets"
                className="mb-2 inline-flex items-center gap-1.5 text-[13px] font-medium text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)]"
            >
                <ArrowLeft className="size-3.5" /> Back to wallets
            </Link>

            {wallet.loading ? (
                <Skeleton className="h-10 w-72" />
            ) : wallet.error ? (
                <Card className="mt-4">
                    <ErrorState error={wallet.error} onRetry={wallet.refetch} />
                </Card>
            ) : w ? (
                <>
                    <PageHeader
                        title={`${titleCase(w.type)} wallet`}
                        description={
                            w.employee_name ? (
                                <Link to={`/employees/${w.employee_id}`} className="hover:underline">
                                    {w.employee_name}
                                </Link>
                            ) : 'Unassigned'
                        }
                        action={
                            <>
                                <StatusBadge status={w.status} />
                                <Button
                                    leftIcon={<ArrowUpRight className="size-3.5" />}
                                    onClick={() => setWithdrawOpen(true)}
                                    disabled={w.status !== 'active' || w.available_balance <= 0}
                                >
                                    Withdraw
                                </Button>
                            </>
                        }
                    />

                    <section className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <BalanceCard
                            label="Available"
                            amount={w.available_balance}
                            currency={w.currency}
                            tone="primary"
                        />
                        <BalanceCard
                            label="Held"
                            amount={w.held_balance}
                            currency={w.currency}
                            tone="warning"
                            icon={Lock}
                            hint="Reserved for in-flight withdrawals"
                        />
                        <BalanceCard
                            label="Total"
                            amount={w.balance}
                            currency={w.currency}
                            tone="muted"
                            hint="Cached running balance"
                        />
                    </section>

                    <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <Card className="lg:col-span-1">
                            <CardHeader title="Wallet info" />
                            <dl className="divide-y divide-[var(--color-border)]">
                                <Row label="ID" value={w.id} mono />
                                <Row label="Currency" value={w.currency} />
                                <Row label="Type" value={titleCase(w.type)} />
                                <Row label="Status" value={<StatusBadge status={w.status} />} />
                                <Row label="Created" value={formatDateTime(w.created_at)} />
                                <Row label="Updated" value={formatDateTime(w.updated_at)} />
                            </dl>
                        </Card>

                        <div className="lg:col-span-2">
                            <Card>
                                <CardHeader
                                    title="Transaction history"
                                    description="Immutable double-entry ledger entries for this wallet."
                                />
                                <TransactionList state={transactions} currency={w.currency} />
                            </Card>
                        </div>
                    </div>

                    <WithdrawModal
                        open={withdrawOpen}
                        onClose={() => setWithdrawOpen(false)}
                        wallet={w}
                        onSuccess={() => {
                            wallet.refetch();
                            transactions.refetch();
                        }}
                    />
                </>
            ) : null}
        </div>
    );
}

function BalanceCard({ label, amount, currency, tone, hint, icon: Icon }) {
    const toneClasses = {
        primary: 'text-[var(--color-text-primary)]',
        warning: 'text-[var(--color-warning)]',
        muted: 'text-[var(--color-text-secondary)]',
    }[tone];

    return (
        <div className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5">
            <div className="flex items-center justify-between">
                <span className="text-[12px] font-medium uppercase tracking-wide text-[var(--color-text-tertiary)]">
                    {label}
                </span>
                {Icon ? <Icon className="size-4 text-[var(--color-text-tertiary)]" /> : null}
            </div>
            <div className={`tabular mt-3 text-2xl font-semibold ${toneClasses}`}>
                {formatMoney(amount, currency)}
            </div>
            {hint ? <p className="mt-1 text-[12px] text-[var(--color-text-tertiary)]">{hint}</p> : null}
        </div>
    );
}

function Row({ label, value, mono }) {
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

function TransactionList({ state, currency }) {
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
            key: 'description',
            header: 'Description',
            render: (row) => (
                <div className="min-w-0">
                    <div className="truncate text-sm text-[var(--color-text-primary)]">
                        {row.description || titleCase(row.reference_type) || '—'}
                    </div>
                    {row.reference_type ? (
                        <div className="text-[11px] text-[var(--color-text-tertiary)]">
                            {row.reference_type}
                        </div>
                    ) : null}
                </div>
            ),
        },
        {
            key: 'amount',
            header: 'Amount',
            align: 'right',
            render: (row) => <TransactionAmount type={row.type} amount={row.amount} currency={currency} />,
        },
        {
            key: 'balance_after',
            header: 'Balance',
            align: 'right',
            render: (row) => (
                <span className="tabular text-[13px] text-[var(--color-text-secondary)]">
                    {formatMoney(row.balance_after, currency)}
                </span>
            ),
        },
    ];

    return (
        <DataTable
            columns={columns}
            rows={state.data?.data}
            getRowKey={(row) => row.id}
            loading={state.loading}
            error={state.error}
            onRetry={state.refetch}
            emptyTitle="No transactions yet"
            emptyDescription="Credits and debits for this wallet will appear here."
            className="!rounded-none !border-0 !border-t border-[var(--color-border)]"
            skeletonRows={4}
        />
    );
}

function WithdrawModal({ open, onClose, wallet, onSuccess }) {
    const [amount, setAmount] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState(null);

    const submit = async () => {
        const cents = Math.round(Number(amount) * 100);
        if (!Number.isFinite(cents) || cents <= 0) {
            setError(new Error('Enter a positive amount.'));
            return;
        }
        setSubmitting(true);
        setError(null);
        try {
            await endpoints.wallets.withdraw(wallet.id, {
                amount: cents,
                idempotency_key: `ui-${wallet.id}-${Date.now()}`,
            });
            setAmount('');
            onSuccess?.();
            onClose();
        } catch (e) {
            setError(e);
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Initiate withdrawal"
            description="Funds will be reserved (held) and dispatched to the bank simulator."
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={submitting}>
                        Cancel
                    </Button>
                    <Button onClick={submit} loading={submitting} leftIcon={<Banknote className="size-3.5" />}>
                        Withdraw
                    </Button>
                </>
            }
        >
            <div className="space-y-3">
                <div>
                    <Label htmlFor="amount">Amount ({wallet?.currency ?? 'USD'})</Label>
                    <Input
                        id="amount"
                        type="number"
                        step="0.01"
                        min="0"
                        placeholder="0.00"
                        value={amount}
                        onChange={(e) => setAmount(e.target.value)}
                    />
                </div>
                <div className="rounded-md bg-[var(--color-bg-subtle)] px-3 py-2 text-[12px] text-[var(--color-text-secondary)]">
                    Available: {formatMoney(wallet?.available_balance, wallet?.currency)}
                </div>
                {error ? (
                    <div className="rounded-md border border-[#fecaca] bg-[var(--color-danger-soft)] px-3 py-2 text-[12px] text-[var(--color-danger)]">
                        {error.message}
                    </div>
                ) : null}
            </div>
        </Modal>
    );
}
