import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Lightweight data-fetching hook with cancellation, manual refetch, and
 * dependency-driven reload. Keeps each page free from boilerplate.
 */
export function useApi(fetcher, deps = []) {
    const [state, setState] = useState({ data: null, error: null, loading: true });
    const fetcherRef = useRef(fetcher);
    fetcherRef.current = fetcher;

    const load = useCallback(async () => {
        setState((prev) => ({ ...prev, loading: true, error: null }));
        const controller = new AbortController();
        try {
            const data = await fetcherRef.current(controller.signal);
            setState({ data, error: null, loading: false });
        } catch (error) {
            if (error.name === 'AbortError') return;
            setState({ data: null, error, loading: false });
        }
        return () => controller.abort();
    }, []);

    useEffect(() => {
        let cancelled = false;
        const controller = new AbortController();
        setState((prev) => ({ ...prev, loading: true, error: null }));
        Promise.resolve(fetcherRef.current(controller.signal))
            .then((data) => {
                if (cancelled) return;
                setState({ data, error: null, loading: false });
            })
            .catch((error) => {
                if (cancelled || error.name === 'AbortError') return;
                setState({ data: null, error, loading: false });
            });
        return () => {
            cancelled = true;
            controller.abort();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, deps);

    return { ...state, refetch: load };
}
