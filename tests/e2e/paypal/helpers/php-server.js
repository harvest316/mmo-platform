/**
 * PHP built-in server launcher for api.php tests.
 *
 * Spawns `php -S 127.0.0.1:<port> -t <siteDir>` with env vars the webhook
 * handler needs. Callers supply their own siteDir (typically a temp copy of
 * the auditandfix-website site so SQLite files land in an isolated place).
 *
 * Key env var: PAYPAL_API_BASE — points at the mock PayPal server URL so
 * retrieve-verify / getPayPalAccessToken hit the local mock instead of
 * api-m.sandbox.paypal.com. This override is honoured by api.php (DR-220).
 */

import { spawn } from 'node:child_process';
import { createConnection } from 'node:net';

/**
 * Find an available TCP port in the ephemeral range.
 * @returns {Promise<number>}
 */
async function pickPort() {
  const { createServer } = await import('node:net');
  return new Promise((resolve, reject) => {
    const srv = createServer();
    srv.once('error', reject);
    srv.listen(0, '127.0.0.1', () => {
      const { port } = srv.address();
      srv.close(() => resolve(port));
    });
  });
}

/**
 * Poll until the PHP server answers TCP connections on the given port, or
 * give up after `timeoutMs`.
 *
 * We don't issue an HTTP GET because api.php has no health endpoint and
 * hitting an unknown action returns 200 with JSON which is fine but noisy.
 * A plain TCP connection is sufficient to know the listener is up.
 */
async function waitForPort(host, port, timeoutMs = 8000) {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    const ok = await new Promise((resolve) => {
      const sock = createConnection({ host, port });
      sock.once('connect', () => {
        sock.destroy();
        resolve(true);
      });
      sock.once('error', () => resolve(false));
    });
    if (ok) return true;
    await new Promise((r) => setTimeout(r, 50));
  }
  throw new Error(`PHP server at ${host}:${port} did not respond within ${timeoutMs}ms`);
}

/**
 * Start a PHP built-in server rooted at the given site directory.
 *
 * @param {object} opts
 * @param {string} opts.siteDir       Absolute path to the site root (docroot).
 * @param {number} [opts.port]        If omitted a free port is picked.
 * @param {object} [opts.env]         Extra environment variables. PAYPAL_API_BASE,
 *                                    PAYPAL_CLIENT_ID/SECRET, PAYPAL_SANDBOX_CLIENT_ID/SECRET,
 *                                    PAYPAL_MODE, E2E_SANDBOX_KEY, SITE_PATH are typical.
 * @param {boolean} [opts.silent=true] When false, PHP stderr is piped to the parent.
 *
 * @returns {Promise<{ url: string, port: number, pid: number, close: () => Promise<void> }>}
 */
export async function startPhpServer({ siteDir, port, env = {}, silent = true }) {
  if (!siteDir) throw new Error('siteDir is required');
  const resolvedPort = port ?? (await pickPort());

  const child = spawn(
    'php',
    ['-S', `127.0.0.1:${resolvedPort}`, '-t', siteDir],
    {
      cwd: siteDir,
      env: {
        ...process.env,
        ...env,
      },
      stdio: silent ? ['ignore', 'ignore', 'pipe'] : ['ignore', 'inherit', 'inherit'],
      detached: false,
    },
  );

  // Capture stderr so we can surface PHP parse errors when the caller turns
  // silent off or when the server exits unexpectedly.
  const stderrChunks = [];
  if (silent && child.stderr) {
    child.stderr.on('data', (c) => stderrChunks.push(c));
  }

  const exited = new Promise((resolve) => child.once('exit', resolve));

  try {
    await Promise.race([
      waitForPort('127.0.0.1', resolvedPort),
      exited.then(() => {
        const err = Buffer.concat(stderrChunks).toString('utf8');
        throw new Error(`php -S exited before ready:\n${err}`);
      }),
    ]);
  } catch (err) {
    try {
      child.kill('SIGKILL');
    } catch {}
    throw err;
  }

  return {
    url: `http://127.0.0.1:${resolvedPort}`,
    port: resolvedPort,
    pid: child.pid,
    close: async () => {
      if (child.exitCode != null) return;
      child.kill('SIGTERM');
      await new Promise((resolve) => {
        const timer = setTimeout(() => {
          try { child.kill('SIGKILL'); } catch {}
          resolve();
        }, 2000);
        child.once('exit', () => {
          clearTimeout(timer);
          resolve();
        });
      });
    },
  };
}
