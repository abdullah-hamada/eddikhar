/**
 * All money values from the API are stored in minor units (cents).
 * Convert to a clean human format here, never in components.
 */
export function formatMoney(cents, currency = 'USD') {
    if (cents === null || cents === undefined) return '—';
    const amount = Number(cents) / 100;
    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency,
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(amount);
    } catch {
        return `$${amount.toFixed(2)}`;
    }
}

export function formatNumber(value) {
    if (value === null || value === undefined) return '—';
    return new Intl.NumberFormat('en-US').format(value);
}

export function formatDateTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function formatRelative(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    const diff = Date.now() - d.getTime();
    const seconds = Math.round(diff / 1000);
    if (seconds < 60) return `${seconds}s ago`;
    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.round(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.round(hours / 24);
    if (days < 30) return `${days}d ago`;
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

export function shortId(id) {
    if (!id) return '—';
    return String(id).slice(0, 8);
}

export function titleCase(value) {
    if (!value) return '';
    return String(value)
        .split(/[_\s-]+/)
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}
