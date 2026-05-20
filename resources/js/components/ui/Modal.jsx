import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import { cn } from '../../lib/cn';

export function Modal({ open, onClose, title, description, children, footer, size = 'md' }) {
    useEffect(() => {
        if (!open) return;
        const onKey = (e) => e.key === 'Escape' && onClose?.();
        document.addEventListener('keydown', onKey);
        const prev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = prev;
        };
    }, [open, onClose]);

    if (!open) return null;

    const sizeClasses = {
        sm: 'max-w-sm',
        md: 'max-w-md',
        lg: 'max-w-lg',
        xl: 'max-w-xl',
    };

    return createPortal(
        <div
            className="fixed inset-0 z-50 flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
        >
            <div
                className="absolute inset-0 bg-black/30 backdrop-blur-[2px]"
                onClick={onClose}
            />
            <div
                className={cn(
                    'relative w-full overflow-hidden rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] shadow-xl',
                    sizeClasses[size],
                )}
            >
                <div className="flex items-start justify-between gap-4 border-b border-[var(--color-border)] px-5 py-4">
                    <div>
                        <h3 className="text-sm font-semibold text-[var(--color-text-primary)]">{title}</h3>
                        {description ? (
                            <p className="mt-0.5 text-[13px] text-[var(--color-text-tertiary)]">{description}</p>
                        ) : null}
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md p-1 text-[var(--color-text-tertiary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text-primary)]"
                        aria-label="Close"
                    >
                        <X className="size-4" />
                    </button>
                </div>
                <div className="px-5 py-4">{children}</div>
                {footer ? (
                    <div className="flex justify-end gap-2 border-t border-[var(--color-border)] bg-[var(--color-bg-subtle)] px-5 py-3">
                        {footer}
                    </div>
                ) : null}
            </div>
        </div>,
        document.body,
    );
}
