import { cn } from '../../lib/cn';

export function PageHeader({ title, description, action, breadcrumb, className }) {
    return (
        <div className={cn('mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between', className)}>
            <div className="min-w-0">
                {breadcrumb ? <div className="mb-2">{breadcrumb}</div> : null}
                <h1 className="text-xl font-semibold tracking-tight text-[var(--color-text-primary)]">
                    {title}
                </h1>
                {description ? (
                    <p className="mt-1 text-[13px] text-[var(--color-text-secondary)]">{description}</p>
                ) : null}
            </div>
            {action ? <div className="flex shrink-0 items-center gap-2">{action}</div> : null}
        </div>
    );
}
