/**
 * Cloudflare Worker - Collabora CORS Proxy for WordPress Playground
 *
 * This worker acts as a CORS proxy between WordPress Playground and a Collabora
 * Online server. It forwards conversion requests to Collabora and adds the
 * necessary CORS headers for browser-based requests.
 *
 * Configuration:
 * - COLLABORA_BASE_URL: Set as a secret via `wrangler secret put COLLABORA_BASE_URL`
 *   Example: https://collabora.example.com
 *
 * Usage:
 * Configure your WordPress Documentate plugin to use this worker's URL as the
 * Collabora base URL. The worker will forward requests to the actual Collabora
 * server configured in COLLABORA_BASE_URL.
 *
 * Security:
 * - Only POST requests are allowed (for document conversion)
 * - Only paths starting with /cool/convert-to/ are forwarded
 * - The actual Collabora URL is stored as a secret
 */

export default {
	async fetch(request, env) {
		const COLLABORA_BASE_URL = env.COLLABORA_BASE_URL;

		// Validate configuration
		if (!COLLABORA_BASE_URL) {
			return new Response(
				JSON.stringify({
					error: 'COLLABORA_BASE_URL not configured',
					hint: 'Run: wrangler secret put COLLABORA_BASE_URL',
				}),
				{
					status: 500,
					headers: {
						'Content-Type': 'application/json',
						...corsHeaders(),
					},
				}
			);
		}

		// Handle CORS preflight requests
		if (request.method === 'OPTIONS') {
			return new Response(null, {
				headers: corsHeaders(),
			});
		}

		// Only allow POST requests (Collabora conversion endpoint)
		if (request.method !== 'POST') {
			return new Response(
				JSON.stringify({ error: 'Method not allowed. Only POST is supported.' }),
				{
					status: 405,
					headers: {
						'Content-Type': 'application/json',
						...corsHeaders(),
					},
				}
			);
		}

		// Validate the path - only allow conversion endpoints
		const url = new URL(request.url);
		if (!url.pathname.startsWith('/cool/convert-to/')) {
			return new Response(
				JSON.stringify({
					error: 'Invalid endpoint',
					hint: 'Only /cool/convert-to/{format} paths are allowed',
				}),
				{
					status: 403,
					headers: {
						'Content-Type': 'application/json',
						...corsHeaders(),
					},
				}
			);
		}

		// Build the target URL preserving the path
		const baseUrl = COLLABORA_BASE_URL.trim().replace(/\/$/, '');
		const targetUrl = baseUrl + url.pathname + url.search;

		// Validate the target URL
		let parsedTarget;
		try {
			parsedTarget = new URL(targetUrl);
		} catch (urlError) {
			return new Response(
				JSON.stringify({
					error: 'Invalid COLLABORA_BASE_URL configuration',
					details: 'The URL could not be parsed. Make sure it includes the protocol (https://)',
					configured: baseUrl ? `${baseUrl.substring(0, 20)}...` : '(empty)',
					targetUrl: targetUrl,
				}),
				{
					status: 500,
					headers: {
						'Content-Type': 'application/json',
						...corsHeaders(),
					},
				}
			);
		}

		// Ensure we're using HTTPS
		if (parsedTarget.protocol !== 'https:' && parsedTarget.protocol !== 'http:') {
			return new Response(
				JSON.stringify({
					error: 'Invalid protocol in COLLABORA_BASE_URL',
					details: 'URL must start with https:// or http://',
					protocol: parsedTarget.protocol,
				}),
				{
					status: 500,
					headers: {
						'Content-Type': 'application/json',
						...corsHeaders(),
					},
				}
			);
		}

		try {
			// Forward the request to Collabora
			const response = await fetch(targetUrl, {
				method: 'POST',
				headers: {
					'Content-Type': request.headers.get('Content-Type') || 'application/octet-stream',
					Accept: request.headers.get('Accept') || 'application/octet-stream',
				},
				body: request.body,
			});

			// Get original headers and add CORS
			const responseHeaders = new Headers(response.headers);
			Object.entries(corsHeaders()).forEach(([key, value]) => {
				responseHeaders.set(key, value);
			});

			// Return the response with CORS headers
			return new Response(response.body, {
				status: response.status,
				statusText: response.statusText,
				headers: responseHeaders,
			});
		} catch (error) {
			console.error('Proxy error:', error);
			return new Response(
				JSON.stringify({
					error: 'Failed to connect to Collabora server',
					details: error.message,
					targetUrl: targetUrl,
				}),
				{
					status: 502,
					headers: {
						'Content-Type': 'application/json',
						...corsHeaders(),
					},
				}
			);
		}
	},
};

/**
 * Returns CORS headers for cross-origin requests.
 * Allows requests from any origin for maximum flexibility with Playground.
 */
function corsHeaders() {
	return {
		'Access-Control-Allow-Origin': '*',
		'Access-Control-Allow-Methods': 'POST, OPTIONS',
		'Access-Control-Allow-Headers': 'Content-Type, Accept',
		'Access-Control-Expose-Headers': 'Content-Disposition, Content-Type',
		'Access-Control-Max-Age': '86400',
	};
}
