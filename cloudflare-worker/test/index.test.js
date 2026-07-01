import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import worker from '../src/index.js';

const ALLOWED = 'https://playground.wordpress.net';
const INTERNAL_URL = 'https://collabora-internal.example.net';

/**
 * Build a minimal Request-like object exercising only the fields the worker uses.
 */
function makeRequest({ method = 'POST', path = '/cool/convert-to/pdf', origin, headers = {}, body = null } = {}) {
	const h = new Headers(headers);
	if (origin) {
		h.set('Origin', origin);
	}
	return {
		method,
		url: 'https://proxy.example.com' + path,
		headers: h,
		body,
	};
}

function baseEnv(overrides = {}) {
	return { COLLABORA_BASE_URL: INTERNAL_URL, ALLOWED_ORIGINS: ALLOWED, ...overrides };
}

let fetchMock;

beforeEach(() => {
	fetchMock = vi.fn().mockResolvedValue(
		new Response('PDFDATA', { status: 200, statusText: 'OK', headers: { 'Content-Type': 'application/pdf' } })
	);
	vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
	vi.unstubAllGlobals();
});

describe('CORS allowlist', () => {
	it('answers preflight for an allowed origin with a matching ACAO', async () => {
		const res = await worker.fetch(makeRequest({ method: 'OPTIONS', origin: ALLOWED }), baseEnv());
		expect(res.headers.get('Access-Control-Allow-Origin')).toBe(ALLOWED);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('does not send ACAO to a disallowed origin on preflight', async () => {
		const res = await worker.fetch(makeRequest({ method: 'OPTIONS', origin: 'https://evil.example' }), baseEnv());
		expect(res.headers.get('Access-Control-Allow-Origin')).toBeNull();
	});

	it('blocks a POST from a disallowed origin without contacting the backend', async () => {
		const res = await worker.fetch(makeRequest({ origin: 'https://evil.example' }), baseEnv());
		expect(res.status).toBe(403);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('allows any origin when ALLOWED_ORIGINS is "*"', async () => {
		const res = await worker.fetch(
			makeRequest({ origin: 'https://anything.example' }),
			baseEnv({ ALLOWED_ORIGINS: '*' })
		);
		expect(res.headers.get('Access-Control-Allow-Origin')).toBe('*');
		expect(fetchMock).toHaveBeenCalledOnce();
	});
});

describe('forwarding', () => {
	it('forwards an allowed POST to the configured Collabora path', async () => {
		const res = await worker.fetch(makeRequest({ origin: ALLOWED, body: 'doc' }), baseEnv());
		expect(fetchMock).toHaveBeenCalledOnce();
		const [target, options] = fetchMock.mock.calls[0];
		expect(target).toBe(INTERNAL_URL + '/cool/convert-to/pdf');
		expect(options.method).toBe('POST');
		expect(res.status).toBe(200);
		expect(res.headers.get('Access-Control-Allow-Origin')).toBe(ALLOWED);
		expect(await res.text()).toBe('PDFDATA');
	});
});

describe('request validation', () => {
	it('rejects non-POST methods with 405', async () => {
		const res = await worker.fetch(makeRequest({ method: 'GET', origin: ALLOWED }), baseEnv());
		expect(res.status).toBe(405);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('rejects paths outside /cool/convert-to/ with 403', async () => {
		const res = await worker.fetch(makeRequest({ path: '/etc/passwd', origin: ALLOWED }), baseEnv());
		expect(res.status).toBe(403);
		expect(fetchMock).not.toHaveBeenCalled();
	});

	it('rejects oversized requests with 413 before contacting the backend', async () => {
		const res = await worker.fetch(
			makeRequest({ origin: ALLOWED, headers: { 'Content-Length': '999999999' } }),
			baseEnv({ MAX_REQUEST_BYTES: '10' })
		);
		expect(res.status).toBe(413);
		expect(fetchMock).not.toHaveBeenCalled();
	});
});

describe('information disclosure', () => {
	it('does not leak the internal Collabora URL when the backend fails', async () => {
		fetchMock.mockRejectedValueOnce(new Error('connect ECONNREFUSED ' + INTERNAL_URL));
		const res = await worker.fetch(makeRequest({ origin: ALLOWED, body: 'doc' }), baseEnv());
		expect(res.status).toBe(502);
		const text = await res.text();
		expect(text).not.toContain('collabora-internal');
		expect(text).not.toContain(INTERNAL_URL);
	});

	it('does not leak configuration details when COLLABORA_BASE_URL is missing', async () => {
		const env = baseEnv();
		delete env.COLLABORA_BASE_URL;
		const res = await worker.fetch(makeRequest({ origin: ALLOWED, body: 'doc' }), env);
		expect(res.status).toBe(500);
		const text = await res.text();
		expect(text).not.toContain('collabora-internal');
		expect(fetchMock).not.toHaveBeenCalled();
	});
});
