# Documentate Collabora Proxy

Cloudflare Worker that acts as a CORS proxy between WordPress Playground and Collabora Online, enabling document conversion in browser-based WordPress environments.

## Why is this needed?

[WordPress Playground](https://playground.wordpress.net) runs entirely in the browser using WebAssembly. While it can make HTTP requests, browsers enforce CORS (Cross-Origin Resource Sharing) policies that prevent direct requests to Collabora servers that don't have CORS headers configured.

This worker sits between Playground and Collabora:
- Receives requests from Playground (with CORS headers)
- Forwards them to your Collabora server (server-to-server, no CORS needed)
- Returns the response with appropriate CORS headers

## Prerequisites

- [Cloudflare account](https://dash.cloudflare.com/sign-up) (free tier is sufficient)
- [Node.js](https://nodejs.org/) (v18 or later)
- Access to a Collabora Online server

## Installation

1. **Install dependencies:**

   ```bash
   cd cloudflare-worker
   npm install
   ```

2. **Login to Cloudflare:**

   ```bash
   npx wrangler login
   ```

3. **Deploy the worker:**

   ```bash
   npm run deploy
   ```

4. **Configure your Collabora URL as a secret:**

   ```bash
   npx wrangler secret put COLLABORA_BASE_URL
   ```

   When prompted, enter your Collabora server URL (e.g., `https://collabora.example.com`).

5. **Note your worker URL:**

   After deployment, you'll see a URL like:
   ```
   https://documentate-collabora-proxy.<your-subdomain>.workers.dev
   ```

## Usage in WordPress Playground

In your Documentate plugin settings, use the worker URL as the Collabora base URL:

1. Go to **Settings > Documentate**
2. Set **Collabora URL** to your worker URL:
   ```
   https://documentate-collabora-proxy.<your-subdomain>.workers.dev
   ```
3. Save settings

The plugin will now route conversion requests through the worker, which forwards them to your actual Collabora server.

## Local Development

To test the worker locally:

```bash
npm run dev
```

This starts a local development server. You'll need to set the `COLLABORA_BASE_URL` environment variable locally (create a `.dev.vars` file):

```ini
COLLABORA_BASE_URL=https://your-collabora-server.com
```

## Security Considerations

- **URL Stored as Secret**: The actual Collabora URL is stored as a Cloudflare secret, not in the code
- **Path Validation**: Only `/cool/convert-to/*` paths are allowed, preventing misuse
- **Method Restriction**: Only POST requests are forwarded
- **No Data Storage**: The worker doesn't store any document data

### Optional: Restrict Origins

To limit which domains can use your proxy, modify the `corsHeaders()` function in `src/index.js`:

```javascript
function corsHeaders() {
  return {
    // Instead of '*', specify allowed origins:
    'Access-Control-Allow-Origin': 'https://playground.wordpress.net',
    // ... rest of headers
  };
}
```

## Cloudflare Free Tier Limits

The free tier includes:
- **100,000 requests/day** - More than enough for demos
- **10ms CPU time per request** - Document conversion proxy typically uses <1ms
- **100MB request size** - Sufficient for most documents

## Troubleshooting

### "COLLABORA_BASE_URL not configured"

Run:
```bash
npx wrangler secret put COLLABORA_BASE_URL
```

### CORS errors in browser

1. Check that your worker is deployed: `npm run deploy`
2. Verify the worker URL in Documentate settings
3. Check browser console for specific error messages

### Connection refused / timeout

1. Verify your Collabora server is accessible
2. Check that the URL includes the protocol (`https://`)
3. Test the Collabora server directly: `curl -X POST https://your-server/cool/convert-to/pdf`

### View logs

```bash
npm run tail
```

This streams real-time logs from your deployed worker.

## License

GPL-2.0-or-later (same as Documentate plugin)
