#!/usr/bin/env python3
"""
extract-builder-items.py

Re-runnable script that extracts every header and footer builder item from
Blocksy theme, Blocksy Companion, and Blocksy Companion Pro.

Usage:
    python3 research/extract-builder-items.py [path_to_wp_content]

Output:
    Writes data/builder-items.json relative to the repository root.
"""

import sys
import os
import re
import json
from datetime import datetime, timezone

# ---------------------------------------------------------------------------
# Path configuration
# ---------------------------------------------------------------------------

REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
CATALOG_PATH = os.path.join(REPO_ROOT, 'data', 'builder-options.json')
OUTPUT_PATH = os.path.join(REPO_ROOT, 'data', 'builder-items.json')

DEFAULT_WPC = '/run/media/hadi/SSD2/Coding/wordpress/blocksy-skill-test/app/public/wp-content'

if len(sys.argv) > 1 and sys.argv[1].strip():
    WPC = os.path.abspath(sys.argv[1].strip())
else:
    WPC = DEFAULT_WPC

THEME = os.path.join(WPC, 'themes', 'blocksy')
COMP = os.path.join(WPC, 'plugins', 'blocksy-companion')
PRO = os.path.join(WPC, 'plugins', 'blocksy-companion-pro')

# ---------------------------------------------------------------------------
# PHP Mini-Parser for config.php
# ---------------------------------------------------------------------------

class PhpParser:
    def __init__(self, text):
        self.text = text
        self.pos = 0
        self.length = len(text)

    def skip_whitespace_and_comments(self):
        while self.pos < self.length:
            if self.text[self.pos].isspace():
                self.pos += 1
                continue
            if self.text.startswith('//', self.pos) or self.text.startswith('#', self.pos):
                nl = self.text.find('\n', self.pos)
                self.pos = nl + 1 if nl != -1 else self.length
                continue
            if self.text.startswith('/*', self.pos):
                end = self.text.find('*/', self.pos)
                self.pos = end + 2 if end != -1 else self.length
                continue
            break

    def peek(self):
        self.skip_whitespace_and_comments()
        if self.pos >= self.length:
            return ''
        return self.text[self.pos]

    def parse_string(self):
        quote = self.text[self.pos]
        self.pos += 1
        res = []
        while self.pos < self.length:
            ch = self.text[self.pos]
            if ch == '\\':
                self.pos += 1
                if self.pos < self.length:
                    res.append(self.text[self.pos])
                    self.pos += 1
                continue
            if ch == quote:
                self.pos += 1
                return ''.join(res)
            res.append(ch)
            self.pos += 1
        return ''.join(res)

    def parse_value(self):
        self.skip_whitespace_and_comments()
        if self.pos >= self.length:
            return None

        # Localization wrapper: __('Text', 'domain')
        if self.text.startswith('__', self.pos):
            self.pos += 2
            self.skip_whitespace_and_comments()
            if self.peek() == '(':
                self.pos += 1
                val = self.parse_value()
                self.skip_whitespace_and_comments()
                while self.pos < self.length and self.text[self.pos] != ')':
                    self.pos += 1
                if self.pos < self.length and self.text[self.pos] == ')':
                    self.pos += 1
                return val

        ch = self.text[self.pos]
        if ch in ("'", '"'):
            return self.parse_string()

        if ch == '[':
            return self.parse_array()

        # Booleans / null / integers
        m = re.match(r'(true|false|null|-?\d+)\b', self.text[self.pos:], re.IGNORECASE)
        if m:
            val_str = m.group(1).lower()
            self.pos += len(m.group(1))
            if val_str == 'true':
                return True
            if val_str == 'false':
                return False
            if val_str == 'null':
                return None
            return int(val_str)

        # Non-literal expression (e.g. function_exists(...) || ...)
        start = self.pos
        depth = 0
        while self.pos < self.length:
            c = self.text[self.pos]
            if c in ('(', '['):
                depth += 1
            elif c in (')', ']'):
                if depth == 0:
                    break
                depth -= 1
            elif c == ',' and depth == 0:
                break
            self.pos += 1
        raw_expr = self.text[start:self.pos].strip()
        raw_expr = re.sub(r'\s+', ' ', raw_expr).strip('()')
        return raw_expr

    def parse_array(self):
        self.pos += 1  # consume '['
        items = []
        is_dict = False

        while self.pos < self.length:
            self.skip_whitespace_and_comments()
            if self.pos >= self.length:
                break
            if self.text[self.pos] == ']':
                self.pos += 1
                break

            val1 = self.parse_value()
            self.skip_whitespace_and_comments()

            if self.text.startswith('=>', self.pos):
                is_dict = True
                self.pos += 2
                val2 = self.parse_value()
                items.append((val1, val2))
            else:
                items.append(val1)

            self.skip_whitespace_and_comments()
            if self.pos < self.length and self.text[self.pos] == ',':
                self.pos += 1

        if is_dict:
            res_dict = {}
            for item in items:
                if isinstance(item, tuple):
                    res_dict[item[0]] = item[1]
                else:
                    res_dict[len(res_dict)] = item
            return res_dict
        return items


def parse_config_file(filepath):
    if not os.path.exists(filepath):
        return {}
    with open(filepath, 'r', encoding='utf-8', errors='ignore') as fp:
        c = fp.read()
    idx = c.find('$config')
    if idx == -1:
        return {}
    parser = PhpParser(c[idx + len('$config'):])
    parser.skip_whitespace_and_comments()
    if parser.peek() == '=':
        parser.pos += 1
        parser.skip_whitespace_and_comments()
        res = parser.parse_value()
        if isinstance(res, dict):
            return res
    return {}

# ---------------------------------------------------------------------------
# Static option ID extractor for options.php
# ---------------------------------------------------------------------------

EXCLUDE_KEYS = {
    'options', 'inner-options', 'choices', 'sync', 'setting',
    'condition', 'conditions', 'responsive', 'value', 'design',
    'attr', 'title', 'desc', 'label'
}

def extract_options_ast(content):
    content_no_comments = re.sub(r'//.*', '', content)
    content_no_comments = re.sub(r'#.*', '', content_no_comments)
    content_no_comments = re.sub(r'/\*.*?\*/', '', content_no_comments, flags=re.DOTALL)

    matches = []
    has_rand = False

    for m in re.finditer(r"(?:'([A-Za-z0-9_\-]+)'|blocksy_rand_md5\(\))\s*=>\s*\[", content_no_comments):
        key = m.group(1) if m.group(1) else '<rand>'
        if key in EXCLUDE_KEYS or (key != '<rand>' and (key.startswith('__') or 'panel' in key.lower())):
            continue

        start_bracket = m.end() - 1
        depth = 0
        end_bracket = -1
        in_string = False
        str_char = ''
        escape = False

        for i in range(start_bracket, min(len(content_no_comments), start_bracket + 15000)):
            ch = content_no_comments[i]
            if escape:
                escape = False
                continue
            if ch == '\\':
                escape = True
                continue
            if in_string:
                if ch == str_char:
                    in_string = False
                continue
            if ch in ("'", '"'):
                in_string = True
                str_char = ch
                continue
            if ch == '[':
                depth += 1
            elif ch == ']':
                depth -= 1
                if depth == 0:
                    end_bracket = i
                    break

        if end_bracket == -1:
            continue

        arr_content = content_no_comments[start_bracket + 1:end_bracket]
        cur_depth = 0
        cur_str = []
        in_s = False
        s_char = ''
        esc = False

        for c in arr_content:
            if esc:
                esc = False
                if cur_depth == 0: cur_str.append(c)
                continue
            if c == '\\':
                esc = True
                if cur_depth == 0: cur_str.append(c)
                continue
            if in_s:
                if c == s_char:
                    in_s = False
                if cur_depth == 0: cur_str.append(c)
                continue
            if c in ("'", '"'):
                in_s = True
                s_char = c
                if cur_depth == 0: cur_str.append(c)
                continue
            if c in ('[', '('):
                cur_depth += 1
            elif c in (']', ')'):
                cur_depth -= 1
            elif cur_depth == 0:
                cur_str.append(c)

        d0_text = ''.join(cur_str)
        type_m = re.search(r"'type'\s*=>\s*(?:'([^']+)'|[^,;\n]+?'(ct-[a-z0-9_\-]+|text|textarea|select|switch|radio|number|wp-editor|hidden|html))", d0_text)
        if type_m:
            if key == '<rand>':
                if not has_rand:
                    has_rand = True
                    matches.append('<rand>')
            else:
                if key not in matches:
                    matches.append(key)

    return matches

def parse_item_options(opt_path, visited=None):
    if visited is None:
        visited = set()
    if opt_path in visited or not os.path.exists(opt_path):
        return []
    visited.add(opt_path)

    with open(opt_path, 'r', encoding='utf-8', errors='ignore') as f:
        content = f.read()

    option_ids = []

    # Follow includes/requires and helper loaders
    inc_matches = re.findall(
        r'(?:blocksy_get_options|blocksy_companion_get_options|blocksy_companion_get_variables_from_file|require|include|require_once|include_once)\s*\(\s*(.+?)(?:,|\);|\)\s*;)',
        content,
        re.DOTALL
    )
    for inc_expr in inc_matches:
        target_path = None
        cleaned = inc_expr.strip()
        m_php = re.search(r"['\"]([^'\"]+\.php)['\"]", cleaned)
        if m_php:
            rel_php = m_php.group(1)
            if 'get_template_directory()' in cleaned:
                target_path = os.path.join(THEME, rel_php.lstrip('/'))
            elif '__DIR__' in cleaned or 'dirname(__FILE__)' in cleaned:
                target_path = os.path.join(os.path.dirname(opt_path), rel_php.lstrip('/'))
            else:
                target_path = os.path.join(os.path.dirname(opt_path), rel_php.lstrip('/'))
                if not os.path.exists(target_path):
                    target_path = os.path.join(THEME, rel_php.lstrip('/'))

        if target_path and os.path.exists(target_path):
            sub_ids = parse_item_options(target_path, visited)
            for sid in sub_ids:
                if sid not in option_ids:
                    option_ids.append(sid)

    ids = extract_options_ast(content)
    for sid in ids:
        if sid not in option_ids:
            option_ids.append(sid)

    return option_ids

# ---------------------------------------------------------------------------
# Version Reader
# ---------------------------------------------------------------------------

def get_blocksy_version(theme_dir):
    style_css = os.path.join(theme_dir, 'style.css')
    if os.path.exists(style_css):
        with open(style_css, 'r', encoding='utf-8', errors='ignore') as f:
            for line in f:
                m = re.match(r'Version:\s*([0-9\.]+)', line, re.IGNORECASE)
                if m:
                    return m.group(1)
    return '2.1.60'

# ---------------------------------------------------------------------------
# Main Extraction Logic
# ---------------------------------------------------------------------------

def main():
    if not os.path.exists(WPC):
        print(f"Error: WPC path '{WPC}' does not exist.", file=sys.stderr)
        sys.exit(1)

    # Load catalog for comparison
    catalog = {'header': [], 'footer': []}
    if os.path.exists(CATALOG_PATH):
        with open(CATALOG_PATH, 'r', encoding='utf-8') as f:
            catalog = json.load(f)

    catalog_header_map = {item['id']: len(item.get('options', [])) for item in catalog.get('header', [])}
    catalog_footer_map = {item['id']: len(item.get('options', [])) for item in catalog.get('footer', [])}

    primary_items = {'top-row', 'middle-row', 'bottom-row', 'offcanvas'}

    # Collect Header items
    # 1. Theme header items
    # 2. Companion header items (account)
    # 3. Companion Pro header items (contacts, content-block, divider, language-switcher, menu-tertiary, mobile-menu-secondary, search-input, widget-area-1)
    # 4. Pro extension items (color-mode-switcher, compare, wish-list)

    header_defs = [
        # (id, source_dir, tier, pro_overrides, requires_dict)
        ('bottom-row', 'themes/blocksy/inc/panel-builder/header/bottom-row', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('button', 'themes/blocksy/inc/panel-builder/header/button', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('cart', 'themes/blocksy/inc/panel-builder/header/cart', 'free-theme', False,
         {'extension': None, 'plugins': ['woocommerce'],
          'notes': "enabled check: function_exists('is_woocommerce') in themes/blocksy/inc/panel-builder/header/cart/config.php:5-7; filtered at runtime in themes/blocksy/inc/components/woocommerce/boot.php:74"}),
        ('logo', 'themes/blocksy/inc/panel-builder/header/logo', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('menu', 'themes/blocksy/inc/panel-builder/header/menu', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('menu-secondary', 'themes/blocksy/inc/panel-builder/header/menu-secondary', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('middle-row', 'themes/blocksy/inc/panel-builder/header/middle-row', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('mobile-menu', 'themes/blocksy/inc/panel-builder/header/mobile-menu', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('offcanvas', 'themes/blocksy/inc/panel-builder/header/offcanvas', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('offcanvas-logo', 'themes/blocksy/inc/panel-builder/header/offcanvas-logo', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('search', 'themes/blocksy/inc/panel-builder/header/search', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('socials', 'themes/blocksy/inc/panel-builder/header/socials', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('text', 'themes/blocksy/inc/panel-builder/header/text', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('top-row', 'themes/blocksy/inc/panel-builder/header/top-row', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('trigger', 'themes/blocksy/inc/panel-builder/header/trigger', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('account', 'plugins/blocksy-companion/framework/features/header/items/account', 'free-companion', True,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('color-mode-switcher', 'plugins/blocksy-companion-pro/framework/premium/extensions/color-mode-switch/header-items/color-mode-switcher', 'pro', False,
         {'extension': 'color-mode-switch', 'plugins': [],
          'notes': 'registered only when extension color-mode-switch is active: plugins/blocksy-companion-pro/framework/premium/extensions/color-mode-switch/extension.php:95'}),
        ('compare', 'plugins/blocksy-companion-pro/framework/premium/extensions/woocommerce-extra/features/compare/header-items/compare', 'pro', False,
         {'extension': 'woocommerce-extra', 'plugins': ['woocommerce'],
          'notes': "requires WooCommerce active (plugins/blocksy-companion-pro/framework/premium/extensions/woocommerce-extra/extension.php:26-38) and compareview feature enabled (extension.php:60-63; feature registered in features/compare/feature.php:20)"}),
        ('wish-list', 'plugins/blocksy-companion-pro/framework/premium/extensions/woocommerce-extra/features/wish-list/header-items/wish-list', 'pro', False,
         {'extension': 'woocommerce-extra', 'plugins': ['woocommerce'],
          'notes': "requires WooCommerce active (plugins/blocksy-companion-pro/framework/premium/extensions/woocommerce-extra/extension.php:26-38) and wishlist feature enabled (extension.php:53-56; feature registered in features/wish-list/feature.php:100)"}),
        ('contacts', 'plugins/blocksy-companion-pro/framework/premium/features/premium-header/items/contacts', 'pro', False,
         {'extension': None, 'plugins': [],
          'notes': 'registered via blocksy:header:items-paths in plugins/blocksy-companion-pro/framework/premium/features/premium-header.php:78'}),
        ('content-block', 'plugins/blocksy-companion-pro/framework/premium/features/premium-header/items/content-block', 'pro', False,
         {'extension': None, 'plugins': [],
          'notes': 'registered via blocksy:header:items-paths in plugins/blocksy-companion-pro/framework/premium/features/premium-header.php:78'}),
        ('divider', 'plugins/blocksy-companion-pro/framework/premium/features/premium-header/items/divider', 'pro', False,
         {'extension': None, 'plugins': [],
          'notes': 'registered via blocksy:header:items-paths in plugins/blocksy-companion-pro/framework/premium/features/premium-header.php:78'}),
        ('language-switcher', 'plugins/blocksy-companion-pro/framework/premium/features/premium-header/items/language-switcher', 'pro', False,
         {'extension': None, 'plugins': ['wpml', 'polylang', 'translatepress', 'weglot'],
          'notes': "enabled only when translation plugin is active (function_exists('icl_object_id') || function_exists('pll_the_languages') || class_exists('TRP_Translate_Press') || function_exists('weglot_get_current_language') in plugins/blocksy-companion-pro/framework/premium/features/premium-header/items/language-switcher/config.php:7-14; registered in premium-header.php:78)"}),
        ('menu-tertiary', 'plugins/blocksy-companion-pro/framework/premium/features/premium-header/items/menu-tertiary', 'pro', False,
         {'extension': None, 'plugins': [],
          'notes': 'registered via blocksy:header:items-paths in plugins/blocksy-companion-pro/framework/premium/features/premium-header.php:78'}),
        ('mobile-menu-secondary', 'plugins/blocksy-companion-pro/framework/premium/features/premium-header/items/mobile-menu-secondary', 'pro', False,
         {'extension': None, 'plugins': [],
          'notes': 'registered via blocksy:header:items-paths in plugins/blocksy-companion-pro/framework/premium/features/premium-header.php:78'}),
        ('search-input', 'plugins/blocksy-companion-pro/framework/premium/features/premium-header/items/search-input', 'pro', False,
         {'extension': None, 'plugins': [],
          'notes': 'registered via blocksy:header:items-paths in plugins/blocksy-companion-pro/framework/premium/features/premium-header.php:78'}),
        ('widget-area-1', 'plugins/blocksy-companion-pro/framework/premium/features/premium-header/items/widget-area-1', 'pro', False,
         {'extension': None, 'plugins': [],
          'notes': 'registered via blocksy:header:items-paths in plugins/blocksy-companion-pro/framework/premium/features/premium-header.php:78'})
    ]

    # Collect Footer items
    # 1. Theme footer items (12)
    # 2. Pro footer items-paths (menu-secondary)
    # 3. Pro items-root-paths (logo, button, text, content-block, search-input, language-switcher, contacts)

    footer_defs = [
        ('bottom-row', 'themes/blocksy/inc/panel-builder/footer/bottom-row', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('button', 'themes/blocksy/inc/panel-builder/header/button', 'free-theme', False,
         {'extension': None, 'plugins': [],
          'notes': 'registered in footer only when Blocksy Companion Pro is active: plugins/blocksy-companion-pro/framework/premium/features/premium-footer.php:135'}),
        ('contacts', 'plugins/blocksy-companion-pro/framework/premium/features/premium-header/items/contacts', 'pro', False,
         {'extension': None, 'plugins': [],
          'notes': 'registered in footer only when Blocksy Companion Pro is active: plugins/blocksy-companion-pro/framework/premium/features/premium-footer.php:141'}),
        ('content-block', 'plugins/blocksy-companion-pro/framework/premium/features/premium-header/items/content-block', 'pro', False,
         {'extension': None, 'plugins': [],
          'notes': 'registered in footer only when Blocksy Companion Pro is active: plugins/blocksy-companion-pro/framework/premium/features/premium-footer.php:138'}),
        ('copyright', 'themes/blocksy/inc/panel-builder/footer/copyright', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('language-switcher', 'plugins/blocksy-companion-pro/framework/premium/features/premium-header/items/language-switcher', 'pro', False,
         {'extension': None, 'plugins': ['wpml', 'polylang', 'translatepress', 'weglot'],
          'notes': "registered in footer only when Blocksy Companion Pro is active (plugins/blocksy-companion-pro/framework/premium/features/premium-footer.php:140) and translation plugin is active (plugins/blocksy-companion-pro/framework/premium/features/premium-header/items/language-switcher/config.php:7-14)"}),
        ('logo', 'themes/blocksy/inc/panel-builder/header/logo', 'free-theme', False,
         {'extension': None, 'plugins': [],
          'notes': 'registered in footer only when Blocksy Companion Pro is active: plugins/blocksy-companion-pro/framework/premium/features/premium-footer.php:134'}),
        ('menu', 'themes/blocksy/inc/panel-builder/footer/menu', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('menu-secondary', 'plugins/blocksy-companion-pro/framework/premium/features/premium-footer/items/menu-secondary', 'pro', False,
         {'extension': None, 'plugins': [],
          'notes': 'registered in footer only when Blocksy Companion Pro is active: plugins/blocksy-companion-pro/framework/premium/features/premium-footer.php:16'}),
        ('middle-row', 'themes/blocksy/inc/panel-builder/footer/middle-row', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('search-input', 'plugins/blocksy-companion-pro/framework/premium/features/premium-header/items/search-input', 'pro', False,
         {'extension': None, 'plugins': [],
          'notes': 'registered in footer only when Blocksy Companion Pro is active: plugins/blocksy-companion-pro/framework/premium/features/premium-footer.php:139'}),
        ('socials', 'themes/blocksy/inc/panel-builder/footer/socials', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('text', 'themes/blocksy/inc/panel-builder/header/text', 'free-theme', False,
         {'extension': None, 'plugins': [],
          'notes': 'registered in footer only when Blocksy Companion Pro is active: plugins/blocksy-companion-pro/framework/premium/features/premium-footer.php:136'}),
        ('top-row', 'themes/blocksy/inc/panel-builder/footer/top-row', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('widget-area-1', 'themes/blocksy/inc/panel-builder/footer/widget-area-1', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('widget-area-2', 'themes/blocksy/inc/panel-builder/footer/widget-area-2', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('widget-area-3', 'themes/blocksy/inc/panel-builder/footer/widget-area-3', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('widget-area-4', 'themes/blocksy/inc/panel-builder/footer/widget-area-4', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('widget-area-5', 'themes/blocksy/inc/panel-builder/footer/widget-area-5', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None}),
        ('widget-area-6', 'themes/blocksy/inc/panel-builder/footer/widget-area-6', 'free-theme', False,
         {'extension': None, 'plugins': [], 'notes': None})
    ]

    all_records = []
    missing_count = 0

    for builder_name, defs, cat_map in [
        ('header', header_defs, catalog_header_map),
        ('footer', footer_defs, catalog_footer_map)
    ]:
        for item_id, rel_src, tier, pro_overrides, reqs in defs:
            abs_item_dir = os.path.join(WPC, rel_src)
            config_file = os.path.join(abs_item_dir, 'config.php')
            options_file = os.path.join(abs_item_dir, 'options.php')

            cfg = parse_config_file(config_file)
            name_val = cfg.get('name', item_id.replace('-', ' ').title())

            # Config dictionary: literal keys + is_primary
            is_primary = item_id in primary_items
            cfg['is_primary'] = is_primary

            # Static option IDs
            opt_ids = parse_item_options(options_file)

            in_catalog = item_id in cat_map
            catalog_count = cat_map.get(item_id, None)
            if not in_catalog:
                missing_count += 1

            record = {
                'id': item_id,
                'builder': builder_name,
                'name': name_val,
                'tier': tier,
                'requires': reqs,
                'source_dir': rel_src,
                'config': cfg,
                'option_ids_static': opt_ids,
                'in_catalog': in_catalog,
                'catalog_option_count': catalog_count
            }
            if pro_overrides:
                record['pro_overrides'] = True

            all_records.append(record)

    # Sort items by builder then id
    all_records.sort(key=lambda x: (x['builder'], x['id']))

    header_count = sum(1 for r in all_records if r['builder'] == 'header')
    footer_count = sum(1 for r in all_records if r['builder'] == 'footer')

    version = get_blocksy_version(THEME)
    iso_now = datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')

    data = {
        'blocksy_version': version,
        'generated': iso_now,
        'source': 'static extraction of theme/companion/pro source; see research/builder-items.md',
        'counts': {
            'header': header_count,
            'footer': footer_count,
            'missing_from_catalog': missing_count
        },
        'items': all_records
    }

    os.makedirs(os.path.dirname(OUTPUT_PATH), exist_ok=True)
    with open(OUTPUT_PATH, 'w', encoding='utf-8') as f:
        json.dump(data, f, indent=2, ensure_ascii=False)

    print(f"Successfully generated {OUTPUT_PATH}")
    print(f"Counts: header={header_count}, footer={footer_count}, missing_from_catalog={missing_count}")

if __name__ == '__main__':
    main()
