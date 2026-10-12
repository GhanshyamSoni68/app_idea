// Fetch wrapper for the /v1 API: session cookie plus the XSRF token, the standard error envelope,
// and per-action idempotency keys (docs/02 §5.1, §5.4).

export class ApiError extends Error {
    constructor(
        public code: string,
        message: string,
        public status: number,
        public retryable = false,
        public details: Record<string, unknown> | null = null,
        public fields: { field: string; code: string; message: string }[] | null = null,
    ) {
        super(message);
    }
}

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
}

export function uuid(): string {
    return crypto.randomUUID();
}

interface Options {
    body?: unknown;
    idempotencyKey?: string;
    signal?: AbortSignal;
}

async function request<T>(method: string, path: string, opts: Options = {}): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (opts.body !== undefined) headers['Content-Type'] = 'application/json';
    if (method !== 'GET') headers['X-XSRF-TOKEN'] = xsrf();
    if (opts.idempotencyKey) headers['Idempotency-Key'] = opts.idempotencyKey;

    let res: Response;
    try {
        res = await fetch(`/v1${path}`, { method, headers, credentials: 'same-origin', body: opts.body === undefined ? undefined : JSON.stringify(opts.body), signal: opts.signal });
    } catch {
        throw new ApiError('offline', 'You appear to be offline. Your work is kept on this device.', 0, true);
    }
    if (res.status === 204) return undefined as T;
    const data = await res.json().catch(() => null);
    if (!res.ok) {
        const e = data?.error;
        throw new ApiError(e?.code ?? `http_${res.status}`, e?.message ?? 'Something went wrong.', res.status, !!e?.retryable, e?.details ?? null, e?.fields ?? null);
    }

    return data as T;
}

export const api = {
    get: <T>(path: string, signal?: AbortSignal) => request<T>('GET', path, { signal }),
    post: <T>(path: string, body?: unknown, idempotencyKey?: string) => request<T>('POST', path, { body: body ?? {}, idempotencyKey }),
    put: <T>(path: string, body: unknown) => request<T>('PUT', path, { body }),
};

/** Fire-and-forget analytics (docs/10 §3.4): IDs, enums and bounded numbers only, never free text. */
export function track(name: string, properties: Record<string, unknown> = {}, subjectId?: string): void {
    const body: Record<string, unknown> = {
        device_class: window.innerWidth < 640 ? 'phone' : window.innerWidth < 1024 ? 'tablet' : 'desktop',
        events: [{ client_event_id: uuid(), name, properties }],
    };
    if (subjectId) body.subject_id = subjectId;
    void request('POST', '/events', { body }).catch(() => undefined);
}
