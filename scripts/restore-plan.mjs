#!/usr/bin/env node
/**
 * Restore plan generator for Blocksy Bridge.
 *
 * Compares a backup export with the current site export and generates a plan
 * to return the site to the backup state.
 *
 * Usage:
 *   node scripts/restore-plan.mjs <backup.json> <current.json> [--menus] [--content-blocks]
 */

import fs from 'node:fs';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

function canonical(value) {
	if (value === null || typeof value !== 'object') {
		return value;
	}
	if (Array.isArray(value)) {
		return value.map(canonical);
	}
	const sortedKeys = Object.keys(value).sort();
	const res = {};
	for (const key of sortedKeys) {
		res[key] = canonical(value[key]);
	}
	return res;
}

function same(a, b) {
	return JSON.stringify(canonical(a)) === JSON.stringify(canonical(b));
}

// WordPress leaves { location: 0 } behind when an assigned menu is deleted.
// That is the same as "nothing assigned", so drop empty assignments before comparing.
function assignedLocations(value) {
	const out = {};
	if (value && typeof value === 'object' && !Array.isArray(value)) {
		for (const [location, menuId] of Object.entries(value)) {
			if (menuId) out[location] = menuId;
		}
	}
	return out;
}

function sameMod(key, a, b) {
	if (key === 'nav_menu_locations') {
		return same(assignedLocations(a), assignedLocations(b));
	}
	return same(a, b);
}

function normalizeMeta(meta) {
	if (meta && typeof meta === 'object' && !Array.isArray(meta)) {
		return { ...meta };
	}
	return {};
}

function cleanMenuForCompare(menu) {
	if (!menu) return null;
	const copy = { ...menu };
	delete copy.slug;
	if (Array.isArray(copy.items)) {
		copy.items = copy.items.map((item) => {
			const itemCopy = { ...item };
			delete itemCopy.id;
			return itemCopy;
		});
	}
	return copy;
}

function cleanBlockForCompare(block) {
	if (!block) return null;
	return {
		...block,
		meta: normalizeMeta(block.meta),
	};
}

function toPlanItem(item) {
	const out = {
		key: item.key,
		title: item.title,
		type: item.type,
	};

	if (!item.type || item.type === 'custom') {
		out.url = item.url;
	} else if (item.type === 'post_type') {
		out.object_id = item.object_id;
		out.object = item.object;
	} else if (item.type === 'taxonomy') {
		out.taxonomy = item.object;
		out.term_id = item.object_id;
	}

	if (typeof item.parent === 'string' && item.parent.length > 0) {
		out.parent = item.parent;
	}

	if (Array.isArray(item.classes)) {
		const filtered = item.classes.filter((c) => typeof c === 'string' && c.length > 0);
		if (filtered.length > 0) {
			out.classes = filtered;
		}
	}

	if (typeof item.target === 'string' && item.target.length > 0) {
		out.target = item.target;
	}

	if (typeof item.description === 'string' && item.description.length > 0) {
		out.description = item.description;
	}

	if (item.meta && typeof item.meta === 'object' && !Array.isArray(item.meta) && Object.keys(item.meta).length > 0) {
		out.meta = item.meta;
	}

	return out;
}

export function unwrapExport(obj) {
	let result = obj;
	if (
		obj &&
		typeof obj === 'object' &&
		!Array.isArray(obj) &&
		obj.export &&
		typeof obj.export === 'object' &&
		!Array.isArray(obj.export) &&
		!('theme_mods' in obj)
	) {
		result = obj.export;
	}

	if (!result || typeof result !== 'object' || Array.isArray(result) || !('theme_mods' in result)) {
		throw new Error('Not a Blocksy export: missing theme_mods');
	}

	return result;
}

export function buildRestorePlan(backupInput, currentInput, opts = {}) {
	const backup = unwrapExport(backupInput);
	const current = unwrapExport(currentInput);

	const plan = {
		version: 1,
		description: 'Restore to backup exported at ' + (backup.exported_at || 'unknown time'),
	};

	// 3. extensions
	const bExt = Array.isArray(backup.options?.blocksy_active_extensions) ? backup.options.blocksy_active_extensions : [];
	const cExt = Array.isArray(current.options?.blocksy_active_extensions) ? current.options.blocksy_active_extensions : [];
	const activate = bExt.filter((id) => !cExt.includes(id));
	const deactivate = cExt.filter((id) => !bExt.includes(id));

	if (activate.length > 0 || deactivate.length > 0) {
		plan.extensions = { activate, deactivate };
	}

	// 4. theme_mods
	const backupThemeMods =
		backup.theme_mods && typeof backup.theme_mods === 'object' && !Array.isArray(backup.theme_mods)
			? backup.theme_mods
			: {};
	const currentThemeMods =
		current.theme_mods && typeof current.theme_mods === 'object' && !Array.isArray(current.theme_mods)
			? current.theme_mods
			: {};

	const planThemeMods = {};
	for (const [key, val] of Object.entries(backupThemeMods)) {
		if (key === 'custom_css_post_id' || key === 'sidebars_widgets') continue;
		if (opts.menus && key === 'nav_menu_locations') continue;
		if (!sameMod(key, val, currentThemeMods[key])) {
			planThemeMods[key] = val;
		}
	}
	if (Object.keys(planThemeMods).length > 0) {
		plan.theme_mods = planThemeMods;
	}

	// 5. remove_theme_mods
	const removeThemeMods = [];
	for (const key of Object.keys(currentThemeMods)) {
		if (key === 'custom_css_post_id' || key === 'sidebars_widgets') continue;
		if (opts.menus && key === 'nav_menu_locations') continue;
		if (!(key in backupThemeMods) && !sameMod(key, undefined, currentThemeMods[key])) {
			removeThemeMods.push(key);
		}
	}
	if (removeThemeMods.length > 0) {
		plan.remove_theme_mods = removeThemeMods;
	}

	// 6. options
	const backupOptions =
		backup.options && typeof backup.options === 'object' && !Array.isArray(backup.options)
			? backup.options
			: {};
	const currentOptions =
		current.options && typeof current.options === 'object' && !Array.isArray(current.options)
			? current.options
			: {};

	const planOptions = {};
	for (const [key, val] of Object.entries(backupOptions)) {
		if (key === 'blocksy_active_extensions') continue;
		if (!(key in currentOptions) || !same(val, currentOptions[key])) {
			planOptions[key] = val;
		}
	}
	if (Object.keys(planOptions).length > 0) {
		plan.options = planOptions;
	}

	// 7. remove_options
	const removeOptions = [];
	for (const key of Object.keys(currentOptions)) {
		if (key === 'blocksy_active_extensions') continue;
		if (!(key in backupOptions)) {
			removeOptions.push(key);
		}
	}
	if (removeOptions.length > 0) {
		plan.remove_options = removeOptions;
	}

	// 8. menus (only when opts.menus is true)
	let menusRestored = 0;
	let menusDeleted = 0;
	if (opts.menus) {
		const backupMenus = Array.isArray(backup.menus) ? backup.menus : [];
		const currentMenus = Array.isArray(current.menus) ? current.menus : [];
		const planMenus = [];

		for (const bMenu of backupMenus) {
			const cMenu = currentMenus.find((m) => m.name === bMenu.name);
			const bClean = cleanMenuForCompare(bMenu);
			const cClean = cleanMenuForCompare(cMenu);

			if (!cMenu || !same(bClean, cClean)) {
				planMenus.push({
					name: bMenu.name,
					locations: bMenu.locations || [],
					replace: true,
					items: (bMenu.items || []).map(toPlanItem),
				});
				menusRestored++;
			}
		}

		for (const cMenu of currentMenus) {
			const existsInBackup = backupMenus.some((m) => m.name === cMenu.name);
			if (!existsInBackup) {
				planMenus.push({
					name: cMenu.name,
					delete: true,
				});
				menusDeleted++;
			}
		}

		if (planMenus.length > 0) {
			plan.menus = planMenus;
		}
	}

	// 9. content_blocks (only when opts.contentBlocks is true)
	let blocksRestored = 0;
	let blocksDeleted = 0;
	if (opts.contentBlocks) {
		const backupBlocks = Array.isArray(backup.content_blocks) ? backup.content_blocks : [];
		const currentBlocks = Array.isArray(current.content_blocks) ? current.content_blocks : [];
		const planBlocks = [];

		for (const bBlock of backupBlocks) {
			const cBlock = currentBlocks.find((b) => b.key === bBlock.key);
			const bClean = cleanBlockForCompare(bBlock);
			const cClean = cleanBlockForCompare(cBlock);

			if (!cBlock || !same(bClean, cClean)) {
				const restored = {
					key: bBlock.key,
					title: bBlock.title,
					status: bBlock.status,
					template_type: bBlock.template_type,
					content: bBlock.content,
					meta: normalizeMeta(bBlock.meta),
				};
				if (typeof bBlock.id === 'number') {
					restored.id = bBlock.id;
				}
				planBlocks.push(restored);
				blocksRestored++;
			}
		}

		for (const cBlock of currentBlocks) {
			const existsInBackup = backupBlocks.some((b) => b.key === cBlock.key);
			if (!existsInBackup) {
				const del = {
					key: cBlock.key,
				};
				if (typeof cBlock.id === 'number') {
					del.id = cBlock.id;
				}
				del.delete = true;
				planBlocks.push(del);
				blocksDeleted++;
			}
		}

		if (planBlocks.length > 0) {
			plan.content_blocks = planBlocks;
		}
	}

	// 10. post_meta and remove_post_meta
	const planPostMeta = {};
	const planRemovePostMeta = {};

	if (Array.isArray(backup.post_meta) && Array.isArray(current.post_meta)) {
		const backupEntries = new Map();
		for (const entry of backup.post_meta) {
			if (entry && entry.post_type !== 'ct_content_block' && entry.post_type !== 'nav_menu_item') {
				const meta = normalizeMeta(entry.meta);
				delete meta.styles_descriptor;
				backupEntries.set(String(entry.id), meta);
			}
		}

		const currentEntries = new Map();
		for (const entry of current.post_meta) {
			if (entry && entry.post_type !== 'ct_content_block' && entry.post_type !== 'nav_menu_item') {
				const meta = normalizeMeta(entry.meta);
				delete meta.styles_descriptor;
				currentEntries.set(String(entry.id), meta);
			}
		}

		for (const [id, bMeta] of backupEntries.entries()) {
			const cur = currentEntries.get(id) || {};
			const changed = {};
			for (const key of Object.keys(bMeta)) {
				if (!(key in cur) || !same(bMeta[key], cur[key])) {
					changed[key] = bMeta[key];
				}
			}
			if (Object.keys(changed).length > 0) {
				planPostMeta[id] = changed;
			}

			const removed = Object.keys(cur).filter((k) => !(k in bMeta));
			if (removed.length > 0) {
				planRemovePostMeta[id] = removed;
			}
		}

		for (const [id, cur] of currentEntries.entries()) {
			if (!backupEntries.has(id)) {
				const keys = Object.keys(cur);
				if (keys.length > 0) {
					planRemovePostMeta[id] = keys;
				}
			}
		}

		if (Object.keys(planPostMeta).length > 0) {
			plan.post_meta = planPostMeta;
		}
		if (Object.keys(planRemovePostMeta).length > 0) {
			plan.remove_post_meta = planRemovePostMeta;
		}
	}

	const postMetaPostIds = new Set([
		...Object.keys(planPostMeta),
		...Object.keys(planRemovePostMeta),
	]);

	// 11. cache
	plan.cache = true;

	// Summary
	const notes = [];
	if (backup.portable === true) {
		notes.push(
			'Backup is a portable export: IDs and nav_menu_locations were stripped. Prefer a non-portable backup for same-site restore.'
		);
	}
	if (!opts.menus && !same(backup.menus || [], current.menus || [])) {
		notes.push('Menus differ from the backup; re-run with --menus to restore them.');
	}
	if (!opts.contentBlocks && !same(backup.content_blocks || [], current.content_blocks || [])) {
		notes.push('Content Blocks differ from the backup; re-run with --content-blocks to restore them.');
	}
	if (Array.isArray(backup.post_meta) !== Array.isArray(current.post_meta)) {
		notes.push('post_meta was not compared: export both files with the post-meta flag.');
	}

	const summary = {
		theme_mods_set: Object.keys(planThemeMods).length,
		theme_mods_removed: removeThemeMods.length,
		options_set: Object.keys(planOptions).length,
		options_removed: removeOptions.length,
		extensions_activated: activate.length,
		extensions_deactivated: deactivate.length,
		menus_restored: menusRestored,
		menus_deleted: menusDeleted,
		content_blocks_restored: blocksRestored,
		content_blocks_deleted: blocksDeleted,
		post_meta_posts_changed: postMetaPostIds.size,
		notes,
	};

	const allZero =
		summary.theme_mods_set === 0 &&
		summary.theme_mods_removed === 0 &&
		summary.options_set === 0 &&
		summary.options_removed === 0 &&
		summary.extensions_activated === 0 &&
		summary.extensions_deactivated === 0 &&
		summary.menus_restored === 0 &&
		summary.menus_deleted === 0 &&
		summary.content_blocks_restored === 0 &&
		summary.content_blocks_deleted === 0 &&
		summary.post_meta_posts_changed === 0;

	if (allZero) {
		notes.push('No differences: the site already matches the backup.');
	}

	return { plan, summary };
}

let isMain = false;
try {
	if (process.argv[1] && fs.realpathSync(process.argv[1]) === fileURLToPath(import.meta.url)) {
		isMain = true;
	}
} catch {
	// Not direct execution
}

if (isMain) {
	const args = process.argv.slice(2);
	const positional = args.filter((a) => !a.startsWith('--'));

	if (positional.length < 2) {
		process.stderr.write(
			'Usage: node scripts/restore-plan.mjs <backup.json> <current.json> [--menus] [--content-blocks]\n'
		);
		process.exit(2);
	}

	const [backupPath, currentPath] = positional;

	let backupJson;
	try {
		const raw = fs.readFileSync(backupPath, 'utf8');
		backupJson = JSON.parse(raw);
	} catch (err) {
		process.stderr.write(`Cannot read ${backupPath}: ${err.message}\n`);
		process.exit(2);
	}

	let currentJson;
	try {
		const raw = fs.readFileSync(currentPath, 'utf8');
		currentJson = JSON.parse(raw);
	} catch (err) {
		process.stderr.write(`Cannot read ${currentPath}: ${err.message}\n`);
		process.exit(2);
	}

	try {
		const { plan, summary } = buildRestorePlan(backupJson, currentJson, {
			menus: args.includes('--menus'),
			contentBlocks: args.includes('--content-blocks'),
		});

		process.stdout.write(JSON.stringify(plan, null, 2) + '\n');
		process.stderr.write(JSON.stringify(summary, null, 2) + '\n');
		process.exit(0);
	} catch (err) {
		process.stderr.write(`${err.message}\n`);
		process.exit(2);
	}
}
