/**
 * Thin API client. Same-origin requests against the Laravel backend.
 */
const csrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

async function request(path, { method = 'GET', body, params, signal } = {}) {
    const url = new URL(path, window.location.origin);
    if (params) {
        for (const [key, value] of Object.entries(params)) {
            if (value === undefined || value === null || value === '') continue;
            url.searchParams.set(key, String(value));
        }
    }

    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (method !== 'GET') headers['X-CSRF-TOKEN'] = csrfToken();

    const response = await fetch(url.toString(), {
        method,
        headers,
        body: body !== undefined ? JSON.stringify(body) : undefined,
        signal,
        credentials: 'same-origin',
    });

    const text = await response.text();
    const data = text ? safeParse(text) : null;

    if (!response.ok) {
        const message = data?.message || data?.error || `Request failed (${response.status})`;
        const error = new Error(message);
        error.status = response.status;
        error.data = data;
        throw error;
    }
    return data;
}

function safeParse(text) {
    try {
        return JSON.parse(text);
    } catch {
        return null;
    }
}

export const api = {
    get: (path, options) => request(path, { ...options, method: 'GET' }),
    post: (path, body, options) => request(path, { ...options, method: 'POST', body }),
};

export const endpoints = {
    dashboard: {
        summary: () => api.get('/api/dashboard/summary'),
        recentActivity: () => api.get('/api/dashboard/recent-activity'),
    },
    employees: {
        list: (params) => api.get('/api/employees', { params }),
        get: (id) => api.get(`/api/employees/${id}`),
    },
    wallets: {
        list: (params) => api.get('/api/wallets', { params }),
        get: (id) => api.get(`/api/wallets/${id}`),
        transactions: (id, params) => api.get(`/api/wallets/${id}/transactions`, { params }),
        withdraw: (id, body) => api.post(`/api/wallets/${id}/withdraw`, body),
    },
    transactions: {
        list: (params) => api.get('/api/transactions', { params }),
    },
    bankPayments: {
        list: (params) => api.get('/api/bank-payments', { params }),
    },
    payrollEvents: {
        list: (params) => api.get('/api/payroll-events', { params }),
    },
    health: () => api.get('/api/health'),
};
