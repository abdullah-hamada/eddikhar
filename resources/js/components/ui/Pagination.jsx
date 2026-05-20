import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from './Button';

export function Pagination({ meta, onPageChange }) {
    if (!meta) return null;
    const { current_page, last_page, total } = meta;
    if (!last_page || last_page <= 1) {
        return (
            <div className="flex items-center justify-between px-1 pt-3 text-[12px] text-[var(--color-text-tertiary)]">
                <span>{total ?? 0} total</span>
            </div>
        );
    }

    return (
        <div className="flex items-center justify-between px-1 pt-3 text-[12px] text-[var(--color-text-tertiary)]">
            <span>
                Page {current_page} of {last_page} · {total} total
            </span>
            <div className="flex items-center gap-1">
                <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => onPageChange(current_page - 1)}
                    disabled={current_page <= 1}
                    leftIcon={<ChevronLeft className="size-3.5" />}
                >
                    Prev
                </Button>
                <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => onPageChange(current_page + 1)}
                    disabled={current_page >= last_page}
                    rightIcon={<ChevronRight className="size-3.5" />}
                >
                    Next
                </Button>
            </div>
        </div>
    );
}
