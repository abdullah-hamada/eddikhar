import { cn } from '../../lib/cn';

const variants = {
    primary:
        'bg-[var(--color-accent)] text-white hover:bg-[var(--color-accent-hover)] disabled:bg-[var(--color-accent)]/50',
    secondary:
        'bg-white text-[var(--color-text-primary)] border border-[var(--color-border)] hover:bg-[var(--color-surface-hover)]',
    ghost: 'bg-transparent text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)]',
    danger:
        'bg-[var(--color-danger)] text-white hover:bg-[var(--color-danger)]/90',
};

const sizes = {
    sm: 'h-8 px-3 text-[13px]',
    md: 'h-9 px-4 text-sm',
    lg: 'h-10 px-5 text-sm',
};

export function Button({
    variant = 'primary',
    size = 'md',
    className,
    type = 'button',
    children,
    leftIcon,
    rightIcon,
    loading = false,
    disabled,
    ...rest
}) {
    return (
        <button
            type={type}
            disabled={disabled || loading}
            className={cn(
                'inline-flex items-center justify-center gap-1.5 rounded-md font-medium transition-colors',
                'disabled:cursor-not-allowed disabled:opacity-60',
                variants[variant],
                sizes[size],
                className,
            )}
            {...rest}
        >
            {loading ? (
                <span className="inline-block size-3 animate-spin rounded-full border-[1.5px] border-current border-t-transparent" />
            ) : (
                leftIcon
            )}
            {children}
            {!loading && rightIcon}
        </button>
    );
}
