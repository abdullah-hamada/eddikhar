import { cn } from '../../lib/cn';

export function Input({ className, leftIcon, ...rest }) {
    return (
        <div className="relative">
            {leftIcon ? (
                <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5 text-[var(--color-text-tertiary)]">
                    {leftIcon}
                </div>
            ) : null}
            <input
                className={cn(
                    'h-9 w-full rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] px-3 text-sm text-[var(--color-text-primary)] placeholder:text-[var(--color-text-tertiary)] transition-colors',
                    'focus:border-[var(--color-accent)] focus:ring-2 focus:ring-[var(--color-accent-soft)] focus:outline-none',
                    leftIcon ? 'pl-9' : '',
                    className,
                )}
                {...rest}
            />
        </div>
    );
}

export function Select({ className, children, ...rest }) {
    return (
        <select
            className={cn(
                'h-9 rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] px-3 text-sm text-[var(--color-text-primary)] transition-colors',
                'focus:border-[var(--color-accent)] focus:ring-2 focus:ring-[var(--color-accent-soft)] focus:outline-none',
                className,
            )}
            {...rest}
        >
            {children}
        </select>
    );
}

export function Label({ children, htmlFor, className }) {
    return (
        <label
            htmlFor={htmlFor}
            className={cn('mb-1.5 block text-[13px] font-medium text-[var(--color-text-secondary)]', className)}
        >
            {children}
        </label>
    );
}
