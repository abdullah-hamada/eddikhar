import { AlertTriangle } from 'lucide-react';
import { Button } from './Button';
import { cn } from '../../lib/cn';

export function ErrorState({ error, onRetry, title, className }) {
    const message = error?.message || 'Something went wrong while loading this data.';
    return (
        <div className={cn('flex flex-col items-center justify-center px-6 py-16 text-center', className)}>
            <div className="flex size-10 items-center justify-center rounded-full bg-[var(--color-danger-soft)] text-[var(--color-danger)]">
                <AlertTriangle className="size-5" />
            </div>
            <h3 className="mt-3 text-sm font-semibold text-[var(--color-text-primary)]">
                {title ?? 'Could not load data'}
            </h3>
            <p className="mt-1 max-w-md text-[13px] text-[var(--color-text-tertiary)]">{message}</p>
            {onRetry ? (
                <Button variant="secondary" size="sm" className="mt-4" onClick={onRetry}>
                    Try again
                </Button>
            ) : null}
        </div>
    );
}
