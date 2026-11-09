#!/usr/bin/env node

/**
 * find-option.mjs — Search Blocksy option catalogs or a live site schema.
 *
 * Usage:
 *   node scripts/find-option.mjs <term> [more terms...]
 *     [--id <exact-id>]
 *     [--in theme|builder|post-meta|content-block|all]
 *     [--schema <schema.json>]
 *     [--json]
 *     [--limit <n>]
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const VALID_SOURCES = ['theme', 'builder', 'post-meta', 'content-block', 'all'];

function isOption(opt) {
  return opt !== null && typeof opt === 'object' && typeof opt.id === 'string';
}

/**
 * Flattens catalog / schema sources into an array of { source, where, option }.
 */
export function collectRecords(sources = {}) {
  const records = [];

  if (sources && typeof sources === 'object') {
    // Theme options
    if (Array.isArray(sources.theme_options)) {
      for (const opt of sources.theme_options) {
        if (isOption(opt)) {
          records.push({ source: 'theme', where: 'theme_mods', option: opt });
        }
      }
    }

    // Builder options
    const builder = sources.builder;
    if (builder && typeof builder === 'object') {
      if (Array.isArray(builder.header)) {
        for (const item of builder.header) {
          if (item && Array.isArray(item.options)) {
            for (const opt of item.options) {
              if (isOption(opt)) {
                records.push({ source: 'builder', where: 'header item: ' + item.id, option: opt });
              }
            }
          }
        }
      }

      if (Array.isArray(builder.footer)) {
        for (const item of builder.footer) {
          if (item && Array.isArray(item.options)) {
            for (const opt of item.options) {
              if (isOption(opt)) {
                records.push({ source: 'builder', where: 'footer item: ' + item.id, option: opt });
              }
            }
          }
        }
      }

      if (Array.isArray(builder.header_settings)) {
        for (const opt of builder.header_settings) {
          if (isOption(opt)) {
            records.push({ source: 'builder', where: 'header section settings', option: opt });
          }
        }
      }

      if (Array.isArray(builder.footer_settings)) {
        for (const opt of builder.footer_settings) {
          if (isOption(opt)) {
            records.push({ source: 'builder', where: 'footer section settings', option: opt });
          }
        }
      }
    }

    // Post meta options
    const postMeta = sources.post_meta_options;
    if (postMeta && typeof postMeta === 'object') {
      for (const [type, opts] of Object.entries(postMeta)) {
        if (Array.isArray(opts)) {
          for (const opt of opts) {
            if (isOption(opt)) {
              records.push({ source: 'post-meta', where: 'post_meta (' + type + ')', option: opt });
            }
          }
        }
      }
    }

    // Content block options
    const contentBlocks = sources.content_block_options;
    if (contentBlocks && typeof contentBlocks === 'object') {
      for (const [type, opts] of Object.entries(contentBlocks)) {
        if (Array.isArray(opts)) {
          for (const opt of opts) {
            if (isOption(opt)) {
              records.push({ source: 'content-block', where: 'content block (' + type + ')', option: opt });
            }
          }
        }
      }
    }
  }

  return records;
}

/**
 * Loads options data either from a live schema file or from the data/ directory.
 */
export function loadSources({ schemaPath, dataDir } = {}) {
  if (schemaPath) {
    const raw = fs.readFileSync(schemaPath, 'utf8');
    let obj = JSON.parse(raw);
    if (obj && typeof obj === 'object' && obj.schema && typeof obj.schema === 'object' && !obj.theme_options) {
      obj = obj.schema;
    }
    return {
      theme_options: obj?.theme_options,
      builder: obj?.builder,
      post_meta_options: obj?.post_meta_options,
      content_block_options: obj?.content_block_options
    };
  }

  const defaultDataDir = fileURLToPath(new URL('../data', import.meta.url));
  const dir = dataDir || defaultDataDir;

  const readJson = (filename) => {
    const filePath = path.join(dir, filename);
    if (!fs.existsSync(filePath)) return undefined;
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  };

  return {
    theme_options: readJson('theme-options.json'),
    builder: readJson('builder-options.json'),
    post_meta_options: readJson('post-meta-options.json'),
    content_block_options: readJson('content-block-options.json')
  };
}

/**
 * Searches records matching source, id, and keyword terms.
 */
export function search(records, { terms = [], id = null, source = 'all' } = {}) {
  let res = records;

  if (source && source !== 'all') {
    res = res.filter(r => r.source === source);
  }

  if (id !== null && id !== undefined) {
    res = res.filter(r => r.option.id === id);
  }

  if (terms && terms.length > 0) {
    const lowerTerms = terms.map(t => String(t).toLowerCase());
    res = res.filter(r => {
      const opt = r.option;
      const haystack = [
        opt.id || '',
        opt.label || '',
        opt.path || '',
        r.where || '',
        opt.type || '',
        opt.choices ? JSON.stringify(opt.choices) : ''
      ].join(' ').toLowerCase();
      return lowerTerms.every(term => haystack.includes(term));
    });

    const firstTerm = lowerTerms[0];
    res.sort((a, b) => {
      const aId = (a.option.id || '').toLowerCase();
      const bId = (b.option.id || '').toLowerCase();
      const rank = (idStr) => {
        if (idStr === firstTerm) return 0;
        if (idStr.includes(firstTerm)) return 1;
        return 2;
      };
      return rank(aId) - rank(bId);
    });
  }

  return res;
}

function printUsage() {
  console.error(`Usage: node scripts/find-option.mjs <term> [more terms...] [--id <exact-id>] [--in theme|builder|post-meta|content-block|all] [--schema <schema.json>] [--json] [--limit <n>]`);
}

function formatDefault(val) {
  let s = JSON.stringify(val);
  if (s === undefined) s = 'null';
  if (s.length > 80) {
    return s.slice(0, 80) + '…';
  }
  return s;
}

function formatChoices(choices) {
  if (choices && typeof choices === 'object' && !Array.isArray(choices)) {
    let s = 'choices: ' + Object.keys(choices).join('|');
    if (s.length > 120) {
      return s.slice(0, 120) + '…';
    }
    return s;
  }
  return null;
}

function runCli() {
  const args = process.argv.slice(2);
  const terms = [];
  let id = null;
  let source = 'all';
  let schemaPath = null;
  let isJson = false;
  let limit = 30;

  for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    if (arg === '--id') {
      if (i + 1 >= args.length) {
        console.error('Error: --id requires a value');
        process.exit(2);
      }
      id = args[++i];
    } else if (arg.startsWith('--id=')) {
      id = arg.slice(5);
    } else if (arg === '--in') {
      if (i + 1 >= args.length) {
        console.error('Error: --in requires a value');
        process.exit(2);
      }
      source = args[++i];
    } else if (arg.startsWith('--in=')) {
      source = arg.slice(5);
    } else if (arg === '--schema') {
      if (i + 1 >= args.length) {
        console.error('Error: --schema requires a value');
        process.exit(2);
      }
      schemaPath = args[++i];
    } else if (arg.startsWith('--schema=')) {
      schemaPath = arg.slice(9);
    } else if (arg === '--limit') {
      if (i + 1 >= args.length) {
        console.error('Error: --limit requires a value');
        process.exit(2);
      }
      limit = parseInt(args[++i], 10);
    } else if (arg.startsWith('--limit=')) {
      limit = parseInt(arg.slice(8), 10);
    } else if (arg === '--json') {
      isJson = true;
    } else if (arg.startsWith('--')) {
      console.error(`Error: Unknown option "${arg}"`);
      process.exit(2);
    } else {
      terms.push(arg);
    }
  }

  if (terms.length === 0 && !id) {
    printUsage();
    process.exit(2);
  }

  if (!VALID_SOURCES.includes(source)) {
    console.error(`Error: Unknown --in value "${source}". Allowed: ${VALID_SOURCES.join(', ')}`);
    process.exit(2);
  }

  if (isNaN(limit) || limit < 0) {
    console.error('Error: --limit must be a non-negative integer');
    process.exit(2);
  }

  const sources = loadSources({ schemaPath });
  const records = collectRecords(sources);
  const results = search(records, { terms, id, source });

  const total = results.length;
  const shownResults = limit > 0 ? results.slice(0, limit) : results;
  const shown = shownResults.length;

  if (isJson) {
    const jsonArr = shownResults.map(r => ({
      source: r.source,
      where: r.where,
      ...r.option
    }));
    console.log(JSON.stringify(jsonArr, null, 2));
  } else {
    for (const r of shownResults) {
      const resp = r.option.responsive ? 'responsive' : '-';
      const label = (r.option.label !== null && r.option.label !== undefined && r.option.label !== '')
        ? r.option.label
        : '-';
      const fields = [
        r.source,
        r.where,
        r.option.id,
        r.option.type,
        resp,
        label,
        formatDefault(r.option.default)
      ];
      const choicesField = formatChoices(r.option.choices);
      if (choicesField) {
        fields.push(choicesField);
      }
      console.log(fields.join('\t'));
    }
  }

  console.error(`${total} match(es), showing ${shown}`);
  if (total === 0) {
    console.error('Try a shorter term, or search the live schema with --schema schema.json');
    process.exit(1);
  }

  process.exit(0);
}

if (process.argv[1] && fs.realpathSync(process.argv[1]) === fileURLToPath(import.meta.url)) {
  runCli();
}
