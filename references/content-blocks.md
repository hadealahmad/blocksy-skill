# Content Blocks, templates, popups, and hooks

Content Blocks are a **Pro** feature. They are a custom post type:

- Post type: `ct_content_block`
- Post meta `template_type`: what kind of template it is
- Post meta `blocksy_post_meta_options`: conditions, subtype, structure, popup
  triggers, visibility
- Post content: normal Gutenberg block markup

If `post_type_exists('ct_content_block')` is false, Blocksy Companion Pro (or
its licence) is not active. The bridge reports
`blocksy.content_blocks_available: false` and refuses a plan that contains
Content Blocks.

## Template types

| `template_type` | Replaces | Notes |
| --- | --- | --- |
| `header` | The whole header | Replaces the builder header when conditions match |
| `footer` | The whole footer | Replaces the builder footer when conditions match |
| `single` | Single post/page rendering | `template_subtype`: `canvas` (full page) or `content` (content area) |
| `archive` | Archive/blog listing | `template_subtype`: `canvas`, `content`, or `card` |
| `404` | 404 page | Conditions are ignored (`match_conditions=false`) |
| `nothing_found` | Empty archive/search state | Conditions ignored |
| `hook` | A Blocksy action hook | `location` + `priority` |
| `popup` | A popup | Trigger settings + conditions |
| `maintenance` | Maintenance mode | Any published matching block puts non-admins into maintenance |

## Selection order

For a given template type, Blocksy loads `ct_content_block` posts with
`get_posts()` (date descending) and uses the **first matching block**. Avoid
overlapping blocks for the same type; a newer block silently wins.

Use a stable `_blocksy_bridge_key` via the plan `key` so re-applying updates the
same block instead of creating duplicates.

## Conditions

`blocksy_post_meta_options.conditions`:

```php
[
  ['type' => 'include', 'rule' => 'singulars', 'payload' => []],
  ['type' => 'exclude', 'rule' => 'page_ids', 'payload' => ['post_id' => 12]],
]
```

- `type`: `include` or `exclude`
- `rule`: a registered rule ID
- `payload`: rule-specific

Nested groups:

```php
[
  'relation' => 'OR',
  'conditions' => [
    ['type' => 'include', 'rule' => 'single_post', 'payload' => []],
    ['type' => 'include', 'rule' => 'post_categories', 'payload' => []],
  ],
]
```

### Common rules

| Rule | Meaning |
| --- | --- |
| `everywhere` | Entire website |
| `singulars` | All singular views |
| `archives` | All archives |
| `front_page` | Front page |
| `blog` | Posts page |
| `search` | Search results |
| `404` | 404 |
| `author` | Author archive |
| `post_ids`, `page_ids`, `custom_post_type_ids` | Specific post/page/CPT ID |
| `post_with_taxonomy_ids` | Post in a specific term |
| `taxonomy_ids` | Taxonomy archive |
| `user_logged_in`, `user_logged_out`, `user_role` | User state |
| `start_end_date`, `schedule_date` | Date/time |
| `request_url`, `request_referer`, `request_cookie` | Request matching |

Payload examples:

```php
['type' => 'include', 'rule' => 'page_ids', 'payload' => ['post_id' => 12]]
['type' => 'include', 'rule' => 'taxonomy_ids', 'payload' => ['taxonomy_id' => 7]]
['type' => 'include', 'rule' => 'user_role', 'payload' => ['role' => 'administrator']]
['type' => 'include', 'rule' => 'request_url', 'payload' => ['url' => '/pricing/']]
['type' => 'include', 'rule' => 'start_end_date', 'payload' => ['start' => '2026-01-01', 'end' => '2026-12-31']]
```

Get the exact rule list for the installed version:

```php
$m = new \Blocksy\ConditionsManager();
$rules = $m->get_all_rules(['filter' => 'all']);
```

The bridge's `apply` warns when a rule ID is unknown.

## Header/footer replacement

```json
{
  "key": "site-header",
  "title": "Site Header",
  "template_type": "header",
  "content": "<!-- wp:group -->...<!-- /wp:group -->",
  "meta": {
    "conditions": [
      { "type": "include", "rule": "everywhere", "payload": [] }
    ],
    "content_block_structure": "type-4",
    "content_style": "wide",
    "content_block_spacing": "both"
  }
}
```

This replaces the builder header entirely for matching views. For most sites,
prefer the builder (`header_placements`) unless the header needs a layout the
builder cannot express.

## Single and archive templates

`template_subtype`:

- `canvas` — replaces the whole page (header/footer still controlled by the
  normal builder unless the template renders its own).
- `content` — replaces only the content area.
- `card` — archive card template (archive only).

Useful meta keys: `content_block_structure` (`type-1`…`type-4`),
`content_style` (`wide`/`boxed`), `content_block_spacing` (`both`/`top`/
`bottom`/`none`), background/border/shadow options.

## Hooks

`template_type=hook`, meta:

```php
[
  'conditions' => [
    ['type' => 'include', 'rule' => 'everywhere', 'payload' => []],
  ],
  'location' => 'blocksy:content:top',
  'priority' => 10,
]
```

`location` is a hook ID from `\Blocksy\HooksManager::get_all_hooks()`. Examples:

- `wp_head`, `wp_body_open`
- `blocksy:header:before`, `blocksy:header:after`
- `blocksy:content:before`, `blocksy:content:top`,
  `blocksy:content:bottom`, `blocksy:content:after`
- `blocksy:hero:before`, `blocksy:hero:title:before`,
  `blocksy:hero:after`
- `blocksy:single:top`, `blocksy:single:content:top`
- `blocksy:archive:top`, `blocksy:archive:bottom`
- `blocksy:loop:before`, `blocksy:loop:after`,
  `blocksy:loop:card:start`, `blocksy:loop:card:end`
- `blocksy:sidebar:before`, `blocksy:sidebar:after`
- `dynamic_sidebar_before`, `dynamic_sidebar_after`

For a custom action, set `location` to `custom_hook` and
`custom_location` to the hook name.

## Popups

`template_type=popup`. The trigger lives in meta:

| Key | Values |
| --- | --- |
| `popup_trigger_condition` | `default`, `scroll`, `element_reveal`, `element_click`, `page_load`, `after_inactivity`, `after_x_time`, `after_x_pages`, `exit_intent` |
| `scroll_value` | e.g. `200px` |
| `scroll_direction` | `down`/`up` |
| `scroll_to_element` | CSS class for `element_reveal` |
| `click_to_element` | CSS class for `element_click` |
| `inactivity_value` | Seconds |
| `x_time_value` | Seconds |
| `x_pages_value` | Page count |
| `popup_relaunch_strategy` | Relaunch rules |
| `days_after_close_value` | `{days,hours,minutes}` |
| `days_after_success_value` | `{days,hours,minutes}` |
| `popup_close_button`, `popup_close_with_esc`, `popup_close_with_backdrop_click` | `yes`/`no` |
| `popup_size` | `small`/`medium`/`large`/`custom` |
| `popup_max_width`, `popup_max_height` | Sizes |
| `visibility` | Device visibility |

Popups also need conditions. They render through an AJAX/popup path and may be
cached separately; regenerate caches after changing them.

## Maintenance mode

`template_type=maintenance` with a condition. Any published matching maintenance
block makes the site return 503 for non-administrators and renders the block as
the page. There is no separate "maintenance mode on/off" option; the block's
existence and conditions are the switch.

## 404 and nothing-found

`template_type=404` and `nothing_found` ignore conditions. They are global
overrides. Only one should exist.

## Free vs Pro

- Content Blocks, all template types, popups, maintenance, and hook blocks are
  **Pro**.
- The conditions engine is in free Companion, but the Content Block UI and
  rendering are Pro.
- Replacing a header/footer with a Content Block is Pro. Sticky/transparent
  header settings are free Companion.

## Create with the bridge

```json
{
  "content_blocks": [
    {
      "key": "single-notice",
      "title": "Single Notice",
      "template_type": "hook",
      "content": "<!-- wp:paragraph --><p>Notice</p><!-- /wp:paragraph -->",
      "meta": {
        "conditions": [
          { "type": "include", "rule": "single_post", "payload": [] }
        ],
        "location": "blocksy:content:top",
        "priority": 10
      }
    }
  ]
}
```

Delete with `{ "key": "single-notice", "delete": true }`.

The bridge stores `_blocksy_bridge_key` on the post. That is what makes the
apply idempotent.
