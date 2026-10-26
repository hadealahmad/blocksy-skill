# Menus

Menus are core WordPress objects. Blocksy only adds locations, header/footer
menu items, and mega-menu item meta.

## Registered locations

Read the live list:

```php
get_registered_nav_menus();
```

or `schema.menu_locations` / `info.menus.locations` from the bridge.

With Blocksy + Companion Pro active, common locations are:

| Location | Used by |
| --- | --- |
| `menu_1` | Header Menu 1 / primary menu |
| `menu_2` | Header Menu 2 |
| `menu_3` | Header Menu 3 (Pro) |
| `footer` | Footer Menu 1 |
| `footer_2` | Footer Menu 2 (Pro) |
| `menu_mobile` | Mobile Menu 1 (Pro) |
| `menu_mobile_2` | Mobile Menu 2 (Pro) |

The exact set depends on the active theme/Companion version. Do not hardcode.

## Menu item values in the builder

The header `menu` item value can be:

- `blocksy_location` — use the theme location assigned to this item's location
  (`menu_1`, `menu_2`, `menu_3`). This is the default.
- A nav menu term ID — force a specific menu object.

The footer `menu` item uses the `footer` location. `menu-secondary` uses
`footer_2`.

## Plan

```json
{
  "menus": [
    {
      "name": "Main Menu",
      "location": "menu_1",
      "replace": true,
      "items": [
        { "key": "home", "title": "Home", "type": "custom", "url": "/" },
        { "key": "blog", "title": "Blog", "type": "custom", "url": "/blog/" },
        {
          "key": "services",
          "title": "Services",
          "type": "post_type",
          "page_id": 12
        },
        {
          "key": "contact",
          "title": "Contact",
          "type": "custom",
          "url": "/contact/",
          "parent": "services"
        }
      ]
    }
  ]
}
```

- `replace: true` deletes existing items and rebuilds. This is the predictable
  behavior for site setup.
- `replace: false` appends.
- `delete: true` deletes the whole menu.
- `location` or `locations` (array) assigns one or more locations.
- `parent` references another item's `key`.
- `meta` on an item writes `blocksy_post_meta_options` (mega menu).

## Menu item types

| `type` | Fields |
| --- | --- |
| `custom` | `url` |
| `post_type` | `page_id` or `object_id`, optional `object`; `slug` is resolved with `get_page_by_path()` |
| `taxonomy` | `taxonomy`, `term_id` |

Standard fields: `title`, `classes` (string or array), `target`, `description`,
`attr_title`, `status`.

## Mega menu (Pro)

The Advanced Menu extension stores item settings in the nav menu item's
`blocksy_post_meta_options`:

```json
{
  "key": "services",
  "title": "Services",
  "type": "custom",
  "url": "#",
  "meta": {
    "has_mega_menu": "yes",
    "mega_menu_columns": "4",
    "3_columns_layout": "repeat(3, 1fr)",
    "mega_menu_content_block": 123
  }
}
```

The exact keys are in `data/builder-options.json` under the `mega-menu`
extension? No — mega-menu options are not a builder item; read the extension's
`options.php` or the live mega-menu UI. The main keys are `has_mega_menu`,
`mega_menu_columns`, the `<n>_columns_layout` keys, and the content block/icon/
badge keys. The bridge accepts an arbitrary `meta` object on a menu item; it is
admin-only and sanitized by Blocksy when the theme helper is available.

## Core REST fallback

If only core REST is available:

1. `POST /wp/v2/menus` with `{name, locations:["menu_1"]}`.
2. `POST /wp/v2/menu-items` for each item. Fields mirror the plan:
   `title`, `url`, `menu`, `parent`, `type`, `object`, `object_id`, `classes`,
   `target`, `description`.
3. `POST /wp/v2/menu-locations/menu_1` with `{menus:[menu_id]}` or use the
   menu `locations` field.

This covers normal menus but not the mega-menu post meta, because
`blocksy_post_meta_options` is not a registered REST meta field on
`nav_menu_item`.

## Verification

```sh
wp menu list
wp menu location list
wp menu item list "Main Menu" --fields=title,type,object,object_id,url
```

Front end:

```sh
curl -s https://example.com/ | grep -o 'menu-item[^"]*' | head
```

If the header item still points at `blocksy_location` and the location is
assigned, the menu renders. If the header item points at a stale term ID, it
falls back to the location (Blocksy checks `wp_get_nav_menu_object`).
