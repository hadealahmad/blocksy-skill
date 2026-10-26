#!/usr/bin/env node
/**
 * Remote Blocksy Bridge client.
 *
 * Requires the Blocksy Bridge plugin to be installed and active on the target
 * site, plus a WordPress Application Password for an administrator.
 *
 * Environment:
 *   BLOCKSY_URL           Site URL, e.g. https://example.com
 *   BLOCKSY_USER          WordPress username
 *   BLOCKSY_APP_PASSWORD  WordPress Application Password
 *   BLOCKSY_TOKEN         Bridge token fallback (Settings → Blocksy Bridge)
 *
 * Usage:
 *   node scripts/remote.mjs health
 *   node scripts/remote.mjs info
 *   node scripts/remote.mjs schema > schema.json
 *   node scripts/remote.mjs export > backup.json
 *   node scripts/remote.mjs export --portable --post-meta > portable.json
 *   node scripts/remote.mjs apply examples/example-plan.json --dry-run
 *   node scripts/remote.mjs apply examples/example-plan.json
 *   node scripts/remote.mjs cache
 */

import fs from 'node:fs';
import process from 'node:process';

const [, , command, ...rest] = process.argv;

const base = (process.env.BLOCKSY_URL || '').replace(/\/+$/, '');
const user = process.env.BLOCKSY_USER || '';
const password = process.env.BLOCKSY_APP_PASSWORD || '';
const bridgeToken = process.env.BLOCKSY_TOKEN || '';
const bridgeTokenQuery = process.env.BLOCKSY_TOKEN_QUERY === '1';

function usage() {
	return `Remote Blocksy Bridge client

Commands:
  health                              Check that the bridge is installed (no auth)
  info                                Site, theme, Blocksy, licence and CSS info
  schema                              Full live option/builder/content-block schema
  export [--portable] [--post-meta]   Export current settings and content
  apply <plan.json> [--dry-run]       Validate and apply a plan
  cache                                Regenerate Blocksy CSS and purge caches

Environment:
  BLOCKSY_URL           Site URL (required)
  BLOCKSY_USER          WordPress username (required with app password)
  BLOCKSY_APP_PASSWORD  WordPress Application Password
  BLOCKSY_TOKEN         Bridge token fallback (Settings → Blocksy Bridge)
  BLOCKSY_TOKEN_QUERY   Set to 1 to send the token as a query parameter instead
                        of the X-Blocksy-Bridge-Token header

Examples:
  BLOCKSY_URL=https://example.com BLOCKSY_USER=admin BLOCKSY_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx' \\
    node scripts/remote.mjs info

  BLOCKSY_URL=https://example.com BLOCKSY_TOKEN='...' \\
    node scripts/remote.mjs info
`;
}

function fail(message) {
	console.error(message);
	process.exit(1);
}

function authHeader() {
	if (!user || !password) {
		fail('BLOCKSY_USER and BLOCKSY_APP_PASSWORD are required for this command.');
	}

	return 'Basic ' + Buffer.from(`${user}:${password}`, 'utf8').toString('base64');
}

async function request(path, options = {}) {
	if (!base) {
		fail('BLOCKSY_URL is required.');
	}

	const headers = {
		Accept: 'application/json',
		...options.headers,
	};

	if (options.auth !== false && !bridgeToken) {
		headers.Authorization = authHeader();
	}

	if (bridgeToken && !bridgeTokenQuery) {
		headers['X-Blocksy-Bridge-Token'] = bridgeToken;
	}

	let url = `${base}/wp-json/blocksy-bridge/v1${path}`;

	if (bridgeToken && bridgeTokenQuery) {
		const separator = url.includes('?') ? '&' : '?';
		url += `${separator}blocksy_bridge_token=${encodeURIComponent(bridgeToken)}`;
	}

	const response = await fetch(url, {
		...options,
		headers,
	});

	const text = await response.text();
	let body = null;

	try {
		body = text ? JSON.parse(text) : null;
	} catch {
		body = text;
	}

	if (!response.ok) {
		const message = body && body.message ? body.message : text;
		fail(`HTTP ${response.status}: ${message}`);
	}

	return body;
}

function print(value) {
	process.stdout.write(JSON.stringify(value, null, 2) + '\n');
}

async function main() {
	if (!command || command === 'help' || command === '--help' || command === '-h') {
		process.stdout.write(usage());
		return;
	}

	if (command === 'health') {
		print(await request('/health', { auth: false }));
		return;
	}

	if (command === 'info') {
		print(await request('/info'));
		return;
	}

	if (command === 'schema') {
		print(await request('/schema'));
		return;
	}

	if (command === 'export') {
		const params = new URLSearchParams();
		if (rest.includes('--portable')) params.set('portable', '1');
		if (rest.includes('--post-meta')) params.set('post_meta', '1');
		const query = params.toString() ? `?${params.toString()}` : '';
		print(await request(`/export${query}`));
		return;
	}

	if (command === 'apply') {
		const planPath = rest.find((item) => !item.startsWith('--'));
		if (!planPath) fail('Usage: node scripts/remote.mjs apply <plan.json> [--dry-run]');
		if (!fs.existsSync(planPath)) fail(`Plan not found: ${planPath}`);

		const plan = JSON.parse(fs.readFileSync(planPath, 'utf8'));
		const dryRun = rest.includes('--dry-run');

		print(
			await request(`/apply${dryRun ? '?dry_run=1' : ''}`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(plan),
			})
		);
		return;
	}

	if (command === 'cache') {
		print(await request('/cache', { method: 'POST' }));
		return;
	}

	fail(`Unknown command: ${command}\n\n${usage()}`);
}

main().catch((error) => {
	fail(error && error.stack ? error.stack : String(error));
});
