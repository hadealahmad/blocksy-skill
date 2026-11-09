import test from 'node:test';
import assert from 'node:assert/strict';
import { buildRestorePlan, unwrapExport } from '../scripts/restore-plan.mjs';

test('1. identical backup and current', () => {
	const backup = {
		exported_at: '2026-11-09T20:00:00+00:00',
		theme_mods: { maxSiteWidth: 1290 },
		options: { blocksy_active_extensions: ['cookies-consent'] },
	};
	const current = {
		exported_at: '2026-11-09T21:00:00+00:00',
		theme_mods: { maxSiteWidth: 1290 },
		options: { blocksy_active_extensions: ['cookies-consent'] },
	};
	const { plan, summary } = buildRestorePlan(backup, current);
	assert.deepEqual(plan, {
		version: 1,
		description: 'Restore to backup exported at 2026-11-09T20:00:00+00:00',
		cache: true,
	});
	assert.equal(Object.keys(plan).length, 3);
	assert.ok(summary.notes.includes('No differences: the site already matches the backup.'));
});

test('2. changed theme mod', () => {
	const backup = {
		theme_mods: { maxSiteWidth: 1290, site_title_color: '#333' },
	};
	const current = {
		theme_mods: { maxSiteWidth: 1400, site_title_color: '#333' },
	};
	const { plan } = buildRestorePlan(backup, current);
	assert.deepEqual(plan.theme_mods, { maxSiteWidth: 1290 });
});

test('3. theme mod only in current', () => {
	const backup = {
		theme_mods: { maxSiteWidth: 1290 },
	};
	const current = {
		theme_mods: { maxSiteWidth: 1290, header_placements: {} },
	};
	const { plan } = buildRestorePlan(backup, current);
	assert.deepEqual(plan.remove_theme_mods, ['header_placements']);
});

test('4. same object with different key order', () => {
	const backup = {
		theme_mods: {
			colorPalette: { color1: '#111', color2: '#222' },
		},
	};
	const current = {
		theme_mods: {
			colorPalette: { color2: '#222', color1: '#111' },
		},
	};
	const { plan, summary } = buildRestorePlan(backup, current);
	assert.equal(plan.theme_mods, undefined);
	assert.equal(plan.remove_theme_mods, undefined);
	assert.ok(summary.notes.includes('No differences: the site already matches the backup.'));
});

test('5. extensions activate and deactivate, excluded from options', () => {
	const backup = {
		theme_mods: {},
		options: { blocksy_active_extensions: ['a', 'b'] },
	};
	const current = {
		theme_mods: {},
		options: { blocksy_active_extensions: ['b', 'c'] },
	};
	const { plan } = buildRestorePlan(backup, current);
	assert.deepEqual(plan.extensions, { activate: ['a'], deactivate: ['c'] });
	assert.equal(plan.options, undefined);
	assert.equal(plan.remove_options, undefined);
});

test('6. option changed and option only in current', () => {
	const backup = {
		theme_mods: {},
		options: {
			blocksy_custom_palettes: { palettes: ['p1'] },
		},
	};
	const current = {
		theme_mods: {},
		options: {
			blocksy_custom_palettes: { palettes: ['p2'] },
			blocksy_ext_white_label_settings: { agency: 'test' },
		},
	};
	const { plan } = buildRestorePlan(backup, current);
	assert.deepEqual(plan.options, {
		blocksy_custom_palettes: { palettes: ['p1'] },
	});
	assert.deepEqual(plan.remove_options, ['blocksy_ext_white_label_settings']);
});

test('7. menus with opts.menus false and opts.menus true', () => {
	const backup = {
		theme_mods: { nav_menu_locations: { menu_1: 10 } },
		menus: [
			{
				name: 'Main',
				slug: 'main',
				locations: ['menu_1'],
				items: [
					{ id: 1, key: 'item-1', title: 'Home', type: 'custom', url: '/' },
					{
						id: 2,
						key: 'item-2',
						title: 'News',
						type: 'taxonomy',
						object: 'category',
						object_id: 42,
					},
				],
			},
		],
	};
	const current = {
		theme_mods: { nav_menu_locations: { menu_1: 20 } },
		menus: [
			{
				name: 'Main',
				slug: 'main',
				locations: ['menu_1'],
				items: [
					{ id: 99, key: 'item-1', title: 'Home Diff', type: 'custom', url: '/diff' },
				],
			},
			{
				name: 'Footer',
				slug: 'footer',
				locations: ['menu_2'],
				items: [],
			},
		],
	};

	// With opts.menus false
	const resFalse = buildRestorePlan(backup, current, { menus: false });
	assert.equal(resFalse.plan.menus, undefined);
	assert.ok(resFalse.summary.notes.some((note) => note.includes('--menus')));
	assert.deepEqual(resFalse.plan.theme_mods, { nav_menu_locations: { menu_1: 10 } });

	// With opts.menus true
	const resTrue = buildRestorePlan(backup, current, { menus: true });
	assert.equal(resTrue.plan.theme_mods, undefined);
	assert.deepEqual(resTrue.plan.menus, [
		{
			name: 'Main',
			locations: ['menu_1'],
			replace: true,
			items: [
				{ key: 'item-1', title: 'Home', type: 'custom', url: '/' },
				{
					key: 'item-2',
					title: 'News',
					type: 'taxonomy',
					taxonomy: 'category',
					term_id: 42,
				},
			],
		},
		{
			name: 'Footer',
			delete: true,
		},
	]);
});

test('8. content blocks with opts.contentBlocks true', () => {
	const backup = {
		theme_mods: {},
		content_blocks: [
			{
				id: 77,
				key: 'notice',
				title: 'Notice',
				status: 'publish',
				template_type: 'hook',
				content: '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->',
				meta: { conditions: [] },
			},
		],
	};
	const current = {
		theme_mods: {},
		content_blocks: [
			{
				id: 77,
				key: 'notice',
				title: 'Notice',
				status: 'publish',
				template_type: 'hook',
				content: '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->',
				meta: { conditions: [] },
			},
			{
				id: 88,
				key: 'popup',
				title: 'Popup',
				status: 'publish',
				template_type: 'popup',
				content: '<!-- wp:paragraph --><p>Pop</p><!-- /wp:paragraph -->',
				meta: {},
			},
		],
	};

	const { plan } = buildRestorePlan(backup, current, { contentBlocks: true });
	assert.deepEqual(plan.content_blocks, [
		{
			key: 'notice',
			title: 'Notice',
			status: 'publish',
			template_type: 'hook',
			content: '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->',
			meta: { conditions: [] },
			id: 77,
		},
		{
			key: 'popup',
			id: 88,
			delete: true,
		},
	]);
});

test('9. post_meta changed and removed', () => {
	const backup = {
		theme_mods: {},
		post_meta: [
			{
				id: 2,
				post_type: 'page',
				meta: { a: 1, b: 2, styles_descriptor: { x: 1 } },
			},
		],
	};
	const current = {
		theme_mods: {},
		post_meta: [
			{
				id: 2,
				post_type: 'page',
				meta: { a: 1, b: 3, c: 4, styles_descriptor: { x: 2 } },
			},
			{
				id: 9,
				post_type: 'page',
				meta: { z: 1 },
			},
			{
				id: 10,
				post_type: 'page',
				meta: '',
			},
		],
	};

	const { plan } = buildRestorePlan(backup, current);
	assert.deepEqual(plan.post_meta, {
		'2': { b: 2 },
	});
	assert.deepEqual(plan.remove_post_meta, {
		'2': ['c'],
		'9': ['z'],
	});
	assert.equal(plan.post_meta['2'].styles_descriptor, undefined);
	assert.ok(!JSON.stringify(plan).includes('styles_descriptor'));
});

test('10. post_meta present on only one side', () => {
	const backup = {
		theme_mods: {},
		post_meta: [
			{
				id: 2,
				post_type: 'page',
				meta: { a: 1 },
			},
		],
	};
	const current = {
		theme_mods: {},
	};

	const { plan, summary } = buildRestorePlan(backup, current);
	assert.equal(plan.post_meta, undefined);
	assert.equal(plan.remove_post_meta, undefined);
	assert.ok(
		summary.notes.includes(
			'post_meta was not compared: export both files with the post-meta flag.'
		)
	);
});

test('11. unwrapExport returns inner export or throws for invalid', () => {
	const validInner = { theme_mods: { maxSiteWidth: 1290 } };
	const wrapped = { export: validInner };
	assert.deepEqual(unwrapExport(wrapped), validInner);

	assert.throws(
		() => unwrapExport({}),
		{
			name: 'Error',
			message: 'Not a Blocksy export: missing theme_mods',
		}
	);
});

test('12. backup.portable true includes note', () => {
	const backup = {
		portable: true,
		theme_mods: { maxSiteWidth: 1290 },
	};
	const current = {
		theme_mods: { maxSiteWidth: 1290 },
	};
	const { summary } = buildRestorePlan(backup, current);
	assert.ok(
		summary.notes.includes(
			'Backup is a portable export: IDs and nav_menu_locations were stripped. Prefer a non-portable backup for same-site restore.'
		)
	);
});

test('13. nav_menu_locations: an emptied assignment equals no assignment', () => {
	const backup = { theme_mods: { nav_menu_locations: [] }, options: {} };
	const current = { theme_mods: { nav_menu_locations: { menu_1: 0 } }, options: {} };
	const { plan, summary } = buildRestorePlan(backup, current);
	assert.equal(plan.theme_mods, undefined);
	assert.equal(summary.theme_mods_set, 0);

	const missing = buildRestorePlan({ theme_mods: {}, options: {} }, current);
	assert.equal(missing.plan.remove_theme_mods, undefined);

	const real = buildRestorePlan(
		{ theme_mods: { nav_menu_locations: { menu_1: 5 } }, options: {} },
		current
	);
	assert.deepEqual(real.plan.theme_mods, { nav_menu_locations: { menu_1: 5 } });
});
