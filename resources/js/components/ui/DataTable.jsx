import { cn } from '../../lib/cn';
import { Skeleton } from './Skeleton';
import { EmptyState } from './EmptyState';
import { ErrorState } from './ErrorState';

/**
 * Generic table.
 * `columns` is an array of { key, header, render?, className?, align? }
 */
export function DataTable({
    columns,
    rows,
    getRowKey,
    onRowClick,
    loading,
    error,
    onRetry,
    emptyTitle = 'No results',
    emptyDescription,
    emptyIcon,
    skeletonRows = 6,
    className,
}) {
    if (loading) {
        return (
            <div className={cn('overflow-hidden rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)]', className)}>
                <table className="w-full">
                    <thead>
                        <tr className="border-b border-[var(--color-border)] bg-[var(--color-bg-subtle)]">
                            {columns.map((col) => (
                                <th
                                    key={col.key}
                                    className="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide text-[var(--color-text-tertiary)]"
                                >
                                    {col.header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {Array.from({ length: skeletonRows }).map((_, idx) => (
                            <tr key={idx} className="border-b border-[var(--color-border)] last:border-b-0">
                                {columns.map((col) => (
                                    <td key={col.key} className="px-4 py-3.5">
                                        <Skeleton className="h-3.5 w-3/4" />
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        );
    }

    if (error) {
        return (
            <div className={cn('rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)]', className)}>
                <ErrorState error={error} onRetry={onRetry} />
            </div>
        );
    }

    if (!rows || rows.length === 0) {
        return (
            <div className={cn('rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)]', className)}>
                <EmptyState title={emptyTitle} description={emptyDescription} icon={emptyIcon} />
            </div>
        );
    }

    return (
        <div className={cn('overflow-hidden rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)]', className)}>
            <div className="overflow-x-auto">
                <table className="w-full">
                    <thead>
                        <tr className="border-b border-[var(--color-border)] bg-[var(--color-bg-subtle)]">
                            {columns.map((col) => (
                                <th
                                    key={col.key}
                                    className={cn(
                                        'px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide text-[var(--color-text-tertiary)]',
                                        col.align === 'right' && 'text-right',
                                        col.align === 'center' && 'text-center',
                                        col.headerClassName,
                                    )}
                                >
                                    {col.header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, rowIdx) => (
                            <tr
                                key={getRowKey ? getRowKey(row, rowIdx) : rowIdx}
                                onClick={onRowClick ? () => onRowClick(row) : undefined}
                                className={cn(
                                    'border-b border-[var(--color-border)] last:border-b-0',
                                    onRowClick && 'cursor-pointer transition-colors hover:bg-[var(--color-surface-hover)]',
                                )}
                            >
                                {columns.map((col) => (
                                    <td
                                        key={col.key}
                                        className={cn(
                                            'px-4 py-3 text-sm text-[var(--color-text-primary)]',
                                            col.align === 'right' && 'text-right',
                                            col.align === 'center' && 'text-center',
                                            col.className,
                                        )}
                                    >
                                        {col.render ? col.render(row) : row[col.key]}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
