/**
 * Cloudflare Worker - Collabora CORS Proxy for WordPress Playground
 *
 * This worker acts as a CORS proxy between WordPress Playground and a Collabora
 * Online server. It forwards conversion requests to Collabora and adds the
 * necessary CORS headers for browser-based requests.
 *
 * Configuration (set with `wrangler secret put` / `[vars]` in wrangler.toml):
 * - COLLABORA_BASE_URL (secret): the Collabora Online base URL to forward to.
 *     Example: https://collabora.example.com
 * - ALLOWED_ORIGINS (var): comma-separated list of browser origins allowed to
 *     use the proxy. Defaults to the WordPress Playground origin. Use "*" to
 *     allow any origin (open proxy — not recommended).
 * - MAX_REQUEST_BYTES (var, optional): maximum accepted request body size in
 *     bytes. Defaults to 100 MB (the Cloudflare free-tier request limit).
 *
 * Security:
 * - Only POST requests to /cool/convert-to/ paths are forwarded.
 * - CORS is restricted to an allowlist of origins (see ALLOWED_ORIGINS).
 * - Error responses never disclose the internal Collabora URL; details are
 *   logged server-side only.
 * - Oversized requests are rejected before reaching the backend.
 */

/** Default browser origin allowed to use the proxy when ALLOWED_ORIGINS is unset. */
const DEFAULT_ALLOWED_ORIGINS = ['https://playground.wordpress.net'];

/** Default maximum request body size: 100 MB (Cloudflare free-tier request limit). */
const DEFAULT_MAX_REQUEST_BYTES = 100 * 1024 * 1024;

export default {
	async fetch(request, env) {
		const allowlist = getAllowlist(env);
		const wildcard = allowlist.includes('*');
		const origin = request.headers.get('Origin');

		// Resolve the Access-Control-Allow-Origin value for this request.
		let allowOrigin = null;
		if (wildcard) {
			allowOrigin = '*';
		} else if (origin && allowlist.includes(origin)) {
			allowOrigin = origin;
		}
		const cors = corsHeaders(allowOrigin);

		// Handle CORS preflight requests.
		if (request.method === 'OPTIONS') {
			return new Response(null, { headers: cors });
		}

		// Block browser requests from origins that are not on the allowlist
		// before they ever reach the backend. Requests without an Origin header
		// (e.g. server-to-server clients) are not subject to CORS and pass through.
		if (origin && !wildcard && allowOrigin === null) {
			return jsonError('Origin not allowed.', 403, cors);
		}

		const collaboraBaseUrl = env.COLLABORA_BASE_URL;
		if (!collaboraBaseUrl) {
			console.error('COLLABORA_BASE_URL is not configured. Run: wrangler secret put COLLABORA_BASE_URL');
			return jsonError('The conversion service is not configured.', 500, cors);
		}

		// Only allow POST requests (Collabora conversion endpoint).
		if (request.method !== 'POST') {
			return jsonError('Method not allowed. Only POST is supported.', 405, cors);
		}

		// Validate the path - only allow conversion endpoints.
		const url = new URL(request.url);
		if (!url.pathname.startsWith('/cool/convert-to/')) {
			return jsonError('Invalid endpoint. Only /cool/convert-to/{format} paths are allowed.', 403, cors);
		}

		// Reject oversized requests up front (when the size is known).
		const maxBytes = getMaxBytes(env);
		const contentLength = Number(request.headers.get('Content-Length') || '0');
		if (contentLength > 0 && contentLength > maxBytes) {
			return jsonError('Request body too large.', 413, cors);
		}

		// Build the target URL preserving the path, from the private base URL.
		const baseUrl = collaboraBaseUrl.trim().replace(/\/$/, '');
		let targetUrl;
		try {
			targetUrl = new URL(baseUrl + url.pathname + url.search);
		} catch (urlError) {
			console.error('Invalid COLLABORA_BASE_URL configuration:', urlError && urlError.message);
			return jsonError('The conversion service is misconfigured.', 500, cors);
		}

		// Only http(s) targets are allowed.
		if (targetUrl.protocol !== 'https:' && targetUrl.protocol !== 'http:') {
			console.error('Invalid protocol in COLLABORA_BASE_URL:', targetUrl.protocol);
			return jsonError('The conversion service is misconfigured.', 500, cors);
		}

		try {
			// Forward the request to Collabora (streamed body, no buffering).
			const response = await fetch(targetUrl.toString(), {
				method: 'POST',
				headers: {
					'Content-Type': request.headers.get('Content-Type') || 'application/octet-stream',
					Accept: request.headers.get('Accept') || 'application/octet-stream',
				},
				body: request.body,
			});

			// Copy upstream headers and add CORS.
			const responseHeaders = new Headers(response.headers);
			for (const [key, value] of Object.entries(cors)) {
				responseHeaders.set(key, value);
			}

			return new Response(response.body, {
				status: response.status,
				statusText: response.statusText,
				headers: responseHeaders,
			});
		} catch (error) {
			// Log the detail server-side; never expose the internal target URL.
			console.error('Proxy error while contacting Collabora:', error && error.message);
			return jsonError('Failed to reach the conversion service.', 502, cors);
		}
	},
};

/**
 * Parse the configured origin allowlist. Falls back to the Playground origin.
 *
 * @param {Record<string, string>} env Worker environment.
 * @returns {string[]} Allowed origins (may contain "*").
 */
function getAllowlist(env) {
	const raw = (env && typeof env.ALLOWED_ORIGINS === 'string' ? env.ALLOWED_ORIGINS : '').trim();
	if (raw === '') {
		return DEFAULT_ALLOWED_ORIGINS;
	}
	return raw
		.split(',')
		.map((value) => value.trim())
		.filter((value) => value !== '');
}

/**
 * Resolve the maximum accepted request body size in bytes.
 *
 * @param {Record<string, string>} env Worker environment.
 * @returns {number} Maximum body size in bytes.
 */
function getMaxBytes(env) {
	const raw = Number(env && env.MAX_REQUEST_BYTES);
	return Number.isFinite(raw) && raw > 0 ? raw : DEFAULT_MAX_REQUEST_BYTES;
}

/**
 * Build CORS headers. The Access-Control-Allow-Origin header is only included
 * when an origin is allowed, so disallowed origins are blocked by the browser.
 *
 * @param {string|null} allowOrigin Value for Access-Control-Allow-Origin, or null.
 * @returns {Record<string, string>} CORS headers.
 */
function corsHeaders(allowOrigin) {
	const headers = {
		'Access-Control-Allow-Methods': 'POST, OPTIONS',
		'Access-Control-Allow-Headers': 'Content-Type, Accept',
		'Access-Control-Expose-Headers': 'Content-Disposition, Content-Type',
		'Access-Control-Max-Age': '86400',
		Vary: 'Origin',
	};
	if (allowOrigin) {
		headers['Access-Control-Allow-Origin'] = allowOrigin;
	}
	return headers;
}

/**
 * Build a JSON error response with CORS headers.
 *
 * @param {string} message Public error message (no internal details).
 * @param {number} status  HTTP status code.
 * @param {Record<string, string>} cors CORS headers to include.
 * @returns {Response}
 */
function jsonError(message, status, cors) {
	return new Response(JSON.stringify({ error: message }), {
		status,
		headers: {
			'Content-Type': 'application/json',
			...cors,
		},
	});
}
