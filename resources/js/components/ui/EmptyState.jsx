import { Inbox } from 'lucide-react';
import { cn } from '../../lib/cn';

export function EmptyState({ title, description, icon, action, className }) {
    const Icon = icon || Inbox;
    return (
        <div className={cn('flex flex-col items-center justify-center px-6 py-16 text-center', className)}>
            <div className="flex size-10 items-center justify-center rounded-full bg-[var(--color-surface-hover)] text-[var(--color-text-tertiary)]">
                <Icon className="size-5" />
            </div>
            <h3 className="mt-3 text-sm font-semibold text-[var(--color-text-primary)]">{title}</h3>
            {description ? (
                <p className="mt-1 max-w-sm text-[13px] text-[var(--color-text-tertiary)]">{description}</p>
            ) : null}
            {action ? <div className="mt-4">{action}</div> : null}
        </div>
    );
}
