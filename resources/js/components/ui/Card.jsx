import { cn } from '../../lib/cn';

export function Card({ className, children, ...rest }) {
    return (
        <div
            className={cn(
                'rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)]',
                className,
            )}
            {...rest}
        >
            {children}
        </div>
    );
}

export function CardHeader({ className, title, description, action }) {
    return (
        <div
            className={cn(
                'flex items-start justify-between gap-4 border-b border-[var(--color-border)] px-5 py-4',
                className,
            )}
        >
            <div className="min-w-0">
                <h2 className="text-sm font-semibold text-[var(--color-text-primary)]">
                    {title}
                </h2>
                {description ? (
                    <p className="mt-0.5 text-[13px] text-[var(--color-text-tertiary)]">
                        {description}
                    </p>
                ) : null}
            </div>
            {action ? <div className="shrink-0">{action}</div> : null}
        </div>
    );
}

export function CardBody({ className, children }) {
    return <div className={cn('p-5', className)}>{children}</div>;
}
