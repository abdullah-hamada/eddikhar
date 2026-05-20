import { ArrowDownLeft, ArrowUpRight } from 'lucide-react';
import { cn } from '../lib/cn';
import { formatMoney } from '../lib/format';

export function TransactionAmount({ type, amount, currency = 'USD' }) {
    const isCredit = type === 'credit';
    const Icon = isCredit ? ArrowDownLeft : ArrowUpRight;
    return (
        <span
            className={cn(
                'tabular inline-flex items-center gap-1 font-medium',
                isCredit ? 'text-[var(--color-success)]' : 'text-[var(--color-warning)]',
            )}
        >
            <Icon className="size-3.5" />
            {isCredit ? '+' : '−'}
            {formatMoney(amount, currency)}
        </span>
    );
}
