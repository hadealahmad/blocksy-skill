# Blocksy research snapshot

Generated from the LocalWP site at
`/run/media/hadi/SSD2/Coding/wordpress/blocksy-skill-test` on 2026-10-26.

This is the raw material used to build the skill in the parent directory. The
live `schema` endpoint in the bridge and `scripts/inspect.php` supersede these
files for any target site.

- WordPress 7.1.3, PHP 8.4 (site), WP-CLI run with system PHP + Local socket.
- Blocksy 2.1.60, Blocksy Companion Pro 2.1.60 (agency licence).
- Active theme: `blocksy-child` (template `blocksy`).
- No extensions were active when these files were generated.

These JSON files are a snapshot of the installed Blocksy version, not a
hand-written contract. Regenerate them against the target site before relying
on option ids or defaults.

## Files

| File | What it contains |
| --- | --- |
| `theme-options-blocksy-2.1.60.json` | Every Blocksy customizer setting: id, control type, default, responsive flag, section path, and choices where available. 674 entries. |
| `builder-options-blocksy-2.1.60.json` | Header and footer builder items, their per-item option ids/types/defaults (903 options), header section settings (12), footer section settings (8), plus the default `header_placements` / `footer_placements` structures. |
| `post-meta-options-blocksy-2.1.60.json` | Per-post Blocksy meta options for `post` (51), `page` (57) and the posts page/blog (2). |
| `content-block-options-blocksy-2.1.60.json` | Content Block meta options for every `template_type` (header, footer, single, archive, 404, hook, popup, maintenance, nothing_found). |

## How they were generated

WP-CLI needs the Local MySQL socket on this machine:

```sh
php -d mysqli.default_socket=/home/hadi/.config/Local/run/NJVUbgRm4/mysql/mysqld.sock \
  /usr/local/bin/wp eval-file <script.php> --path=/run/media/hadi/SSD2/Coding/wordpress/blocksy-skill-test/app/public --allow-root
```

The scripts walk the same PHP option arrays that the Blocksy customizer uses
(`blocksy_get_options('customizer')`, `blocksy_manager()->builder->get_data_for_customizer()`,
and `blocksy_get_options('meta/<type>')`) and record every leaf option.

## Caveats

- Option ids and defaults change between Blocksy versions. Treat these files as
  a version-pinned reference and re-run the extractor on the target site.
- Options that depend on other plugins (WooCommerce, Tutor LMS, bbPress,
  Elementor, etc.) are only present when those plugins are active.
- Labels are in the site language (Arabic in this snapshot). The ids and
  defaults are the useful part.
- The builder item names are lower-case because the config files' translated
  names are only loaded in admin/customizer context; item ids are unaffected.
