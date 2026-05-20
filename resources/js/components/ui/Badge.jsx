import { cn } from '../../lib/cn';

const tones = {
    neutral:
        'bg-[var(--color-surface-hover)] text-[var(--color-text-secondary)] border-[var(--color-border)]',
    success: 'bg-[var(--color-success-soft)] text-[var(--color-success)] border-[#bbf7d0]',
    warning: 'bg-[var(--color-warning-soft)] text-[var(--color-warning)] border-[#fde68a]',
    danger: 'bg-[var(--color-danger-soft)] text-[var(--color-danger)] border-[#fecaca]',
    info: 'bg-[var(--color-info-soft)] text-[var(--color-info)] border-[#bae6fd]',
    accent: 'bg-[var(--color-accent-soft)] text-[var(--color-accent)] border-[#c7d2fe]',
};

export function Badge({ tone = 'neutral', className, children, dot = false }) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[11px] font-medium uppercase tracking-wide',
                tones[tone],
                className,
            )}
        >
            {dot ? <span className={cn('size-1.5 rounded-full bg-current')} /> : null}
            {children}
        </span>
    );
}

const STATUS_TONES = {
    active: 'success',
    inactive: 'neutral',
    terminated: 'danger',
    closed: 'neutral',
    pending: 'warning',
    initiated: 'info',
    processing: 'info',
    confirmed: 'success',
    processed: 'success',
    succeeded: 'success',
    success: 'success',
    failed: 'danger',
    error: 'danger',
    received: 'info',
    reversed: 'warning',
    credit: 'success',
    debit: 'warning',
};

export function StatusBadge({ status, className }) {
    if (!status) return null;
    const key = String(status).toLowerCase();
    const tone = STATUS_TONES[key] ?? 'neutral';
    return (
        <Badge tone={tone} dot className={className}>
            {String(status).replace(/_/g, ' ')}
        </Badge>
    );
}
