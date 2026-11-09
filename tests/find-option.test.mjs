import test from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';
import os from 'node:os';
import child_process from 'node:child_process';
import { collectRecords, loadSources, search } from '../scripts/find-option.mjs';

test('1. loadSources({}) then collectRecords returns > 1000 records and includes all four sources', () => {
  const sources = loadSources({});
  const records = collectRecords(sources);
  assert.ok(records.length > 1000, `Expected > 1000 records, got ${records.length}`);
  const sourcesFound = new Set(records.map(r => r.source));
  for (const expected of ['theme', 'builder', 'post-meta', 'content-block']) {
    assert.ok(sourcesFound.has(expected), `Expected sources to include "${expected}"`);
  }
});

test('2. search with id "maxSiteWidth" and source "theme" returns exactly one record, type "ct-slider"', () => {
  const sources = loadSources({});
  const records = collectRecords(sources);
  const results = search(records, { id: 'maxSiteWidth', source: 'theme' });
  assert.equal(results.length, 1);
  assert.equal(results[0].option.id, 'maxSiteWidth');
  assert.equal(results[0].option.type, 'ct-slider');
});

test('3. search terms ["rootTypography"] -> first result option.id is "rootTypography"', () => {
  const sources = loadSources({});
  const records = collectRecords(sources);
  const results = search(records, { terms: ['rootTypography'] });
  assert.ok(results.length > 0);
  assert.equal(results[0].option.id, 'rootTypography');
});

test('4. search terms ["sticky"], source "builder" -> at least one result and every result has source "builder"', () => {
  const sources = loadSources({});
  const records = collectRecords(sources);
  const results = search(records, { terms: ['sticky'], source: 'builder' });
  assert.ok(results.length > 0);
  for (const r of results) {
    assert.equal(r.source, 'builder');
  }
});

test('5. two terms are ANDed: search terms ["hero", "xyzzy-not-there"] returns 0 results', () => {
  const sources = loadSources({});
  const records = collectRecords(sources);
  const results = search(records, { terms: ['hero', 'xyzzy-not-there'] });
  assert.equal(results.length, 0);
});

test('6. schema-shaped inline object loaded with loadSources({ schemaPath }) -> collectRecords gives exactly 1 record with source "theme"', () => {
  const tmpDir = os.tmpdir();
  const tmpPath = path.join(tmpDir, `schema-test-${Date.now()}-${Math.random().toString(36).slice(2)}.json`);
  const schemaPayload = {
    schema: {
      theme_options: [
        {
          id: 'a1',
          type: 'ct-switch',
          label: 'Alpha',
          default: 'no',
          responsive: false,
          path: 'x'
        }
      ]
    }
  };
  fs.writeFileSync(tmpPath, JSON.stringify(schemaPayload), 'utf8');

  try {
    const sources = loadSources({ schemaPath: tmpPath });
    const records = collectRecords(sources);
    assert.equal(records.length, 1);
    assert.equal(records[0].source, 'theme');
    assert.equal(records[0].option.id, 'a1');
  } finally {
    try {
      fs.unlinkSync(tmpPath);
    } catch {
      // ignore cleanup errors
    }
  }
});

test('7. CLI exit codes and outputs for success (status 0), no matches (status 1), and no args (status 2)', () => {
  const scriptPath = fileURLToPath(new URL('../scripts/find-option.mjs', import.meta.url));

  const run1 = child_process.spawnSync(process.execPath, [scriptPath, 'maxSiteWidth', '--in', 'theme'], { encoding: 'utf8' });
  assert.equal(run1.status, 0);
  assert.ok(run1.stdout.includes('maxSiteWidth'));

  const run2 = child_process.spawnSync(process.execPath, [scriptPath, 'zzzz-no-such-option-zzzz'], { encoding: 'utf8' });
  assert.equal(run2.status, 1);

  const run3 = child_process.spawnSync(process.execPath, [scriptPath], { encoding: 'utf8' });
  assert.equal(run3.status, 2);
});
