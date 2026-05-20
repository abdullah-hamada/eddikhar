import { cn } from '../../lib/cn';
import { Skeleton } from './Skeleton';

export function StatCard({ label, value, hint, icon: Icon, tone = 'neutral', loading }) {
    const toneClasses = {
        neutral: 'text-[var(--color-text-primary)]',
        success: 'text-[var(--color-success)]',
        warning: 'text-[var(--color-warning)]',
        danger: 'text-[var(--color-danger)]',
        accent: 'text-[var(--color-accent)]',
    };

    return (
        <div className="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5">
            <div className="flex items-center justify-between">
                <span className="text-[12px] font-medium uppercase tracking-wide text-[var(--color-text-tertiary)]">
                    {label}
                </span>
                {Icon ? (
                    <span className="text-[var(--color-text-tertiary)]">
                        <Icon className="size-4" />
                    </span>
                ) : null}
            </div>
            <div className="mt-3 flex items-baseline gap-2">
                {loading ? (
                    <Skeleton className="h-7 w-32" />
                ) : (
                    <span className={cn('tabular text-2xl font-semibold', toneClasses[tone])}>{value}</span>
                )}
            </div>
            {hint ? (
                <p className="mt-1.5 text-[12px] text-[var(--color-text-tertiary)]">{hint}</p>
            ) : null}
        </div>
    );
}
