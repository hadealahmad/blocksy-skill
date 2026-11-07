#!/usr/bin/env python3
"""
extract-css-tokens.py

Extracts Blocksy CSS custom properties (variables) and CSS classes
from Blocksy theme, Blocksy Companion, Blocksy Companion Pro, and global.css.

Usage:
    python3 research/extract-css-tokens.py [<THEME> <COMP> <PRO> <GLOBAL_CSS>]
"""

import sys
import os
import re
import json
import glob
from datetime import datetime, timezone
from collections import Counter

DEFAULT_THEME = '/run/media/hadi/SSD2/Coding/wordpress/blocksy-skill-test/app/public/wp-content/themes/blocksy'
DEFAULT_COMP = '/run/media/hadi/SSD2/Coding/wordpress/blocksy-skill-test/app/public/wp-content/plugins/blocksy-companion'
DEFAULT_PRO = '/run/media/hadi/SSD2/Coding/wordpress/blocksy-skill-test/app/public/wp-content/plugins/blocksy-companion-pro'
DEFAULT_GLOBAL_CSS = '/run/media/hadi/SSD2/Coding/wordpress/blocksy-skill-test/app/public/wp-content/uploads/blocksy/css/global.css'

BLOCKSY_VERSION = "2.1.60"

def get_rel_path(filepath):
    """Return path relative to wp-content, e.g. themes/blocksy/... or plugins/..."""
    norm = filepath.replace('\\', '/')
    if '/wp-content/' in norm:
        return norm.split('/wp-content/', 1)[1]
    return os.path.basename(filepath)

def parse_css_rules(text):
    """
    Parse CSS text into a list of (selector, declarations_body) tuples.
    Handles nested at-rules like @media properly.
    """
    clean = re.sub(r'/\*.*?\*/', '', text, flags=re.DOTALL)
    rules = []
    stack = []
    buf = []
    for c in clean:
        if c == '{':
            sel = ''.join(buf).strip()
            stack.append(sel)
            buf = []
        elif c == '}':
            body = ''.join(buf).strip()
            sel = stack.pop() if stack else ''
            if sel and not sel.startswith('@'):
                rules.append((sel, body))
            buf = []
        else:
            buf.append(c)
    return rules

def get_variable_category(name):
    """
    Category rules (apply in order, first match wins):
    - names produced by regex errors like --true, --false, --has-* flags are allowed but category 'other'
    - contains palette or color -> color
    - contains font or line-height or letter-spacing or text-transform or text-decoration -> typography
    - starts with --theme-button -> button
    - starts with --theme-form -> form
    - contains shadow -> shadow
    - contains border or radius -> border
    - contains spacing or gap or padding or margin -> spacing
    - contains width or container or edge or offset or height -> layout
    - starts with --header or contains header -> header
    - contains footer -> footer
    - else other
    """
    if name.startswith('--has-') or name in ('--true', '--false'):
        return 'other'
    if 'palette' in name or 'color' in name:
        return 'color'
    if any(k in name for k in ('font', 'line-height', 'letter-spacing', 'text-transform', 'text-decoration')):
        return 'typography'
    if name.startswith('--theme-button'):
        return 'button'
    if name.startswith('--theme-form'):
        return 'form'
    if 'shadow' in name:
        return 'shadow'
    if 'border' in name or 'radius' in name:
        return 'border'
    if any(k in name for k in ('spacing', 'gap', 'padding', 'margin')):
        return 'spacing'
    if any(k in name for k in ('width', 'container', 'edge', 'offset', 'height')):
        return 'layout'
    if name.startswith('--header') or 'header' in name:
        return 'header'
    if 'footer' in name:
        return 'footer'
    return 'other'

def get_class_family(class_name):
    """
    Family rules:
    ct-hidden-* -> visibility
    has-*palette* -> color
    contains button -> button
    is-style-* -> block-style
    ct-container*, is-width-constrained, align*, entry-content, is-layout-* -> layout
    other ct-* -> component
    else other
    """
    if re.match(r'^ct-hidden-', class_name):
        return 'visibility'
    if 'palette' in class_name and class_name.startswith('has-'):
        return 'color'
    if 'button' in class_name:
        return 'button'
    if class_name.startswith('is-style-'):
        return 'block-style'
    if (class_name.startswith('ct-container') or
        class_name == 'is-width-constrained' or
        class_name.startswith('align') or
        class_name == 'entry-content' or
        class_name.startswith('is-layout-')):
        return 'layout'
    if class_name.startswith('ct-'):
        return 'component'
    return 'other'

def extract_tokens(theme_path, comp_path, pro_path, global_css_path):
    # ---------------------------------------------------------
    # STEP 1: CSS Variables Extraction
    # ---------------------------------------------------------
    print("Extracting CSS variables...")

    # Bundle CSS files to scan for variables
    def get_bundle_css(base_dir):
        if not os.path.isdir(base_dir):
            return []
        pattern = os.path.join(base_dir, 'static', 'bundle', '*.min.css')
        files = glob.glob(pattern)
        res = []
        for f in files:
            b = os.path.basename(f)
            if b.endswith('-rtl.min.css'):
                continue
            if b.startswith('customizer-controls') or b.startswith('options') or b.startswith('admin-frontend'):
                continue
            res.append(f)
        return sorted(res)

    theme_bundle_css = get_bundle_css(theme_path)
    comp_bundle_css = get_bundle_css(comp_path)
    pro_bundle_css = get_bundle_css(pro_path)
    all_css_files = theme_bundle_css + comp_bundle_css + pro_bundle_css
    if os.path.isfile(global_css_path):
        all_css_files.append(global_css_path)

    # PHP files to scan
    php_dirs = [
        os.path.join(theme_path, 'inc', 'dynamic-styles'),
        os.path.join(theme_path, 'inc', 'panel-builder'),
        os.path.join(comp_path, 'framework'),
        os.path.join(pro_path, 'framework')
    ]
    all_php_files = []
    for d in php_dirs:
        if os.path.isdir(d):
            for root, _, files in os.walk(d):
                for f in sorted(files):
                    if f.endswith('.php'):
                        all_php_files.append(os.path.join(root, f))

    all_scanned_files = all_css_files + all_php_files

    # 1. Parse GLOBAL_CSS for values, selectors, global flag
    global_css_defs = {}
    if os.path.isfile(global_css_path):
        with open(global_css_path, errors='ignore') as f:
            gcss_text = f.read()
        grules = parse_css_rules(gcss_text)
        for sel, body in grules:
            decls = body.split(';')
            for decl in decls:
                if ':' not in decl:
                    continue
                prop, val = decl.split(':', 1)
                prop = prop.strip()
                val = val.strip()
                if prop.startswith('--'):
                    if prop not in global_css_defs:
                        global_css_defs[prop] = {
                            'selectors': [],
                            'root_value': None,
                            'first_value': val
                        }
                    if sel not in global_css_defs[prop]['selectors']:
                        global_css_defs[prop]['selectors'].append(sel)
                    # Check root selector
                    sel_parts = [s.strip() for s in sel.split(',')]
                    if ':root' in sel_parts or any(':root' in s or '[data-theme]' in s for s in sel_parts):
                        if global_css_defs[prop]['root_value'] is None:
                            global_css_defs[prop]['root_value'] = val

    # Helper to check if a var matches the collection criteria
    def is_target_variable(name):
        if not name.startswith('--'):
            return False
        if len(name) < 4 or name.endswith("-"):
            return False
        if re.match(r'^--theme-[a-z0-9-]+$', name):
            return True
        if re.match(r'^--wp--preset--color--palette-color-[a-z0-9-]+$', name):
            return True
        if re.match(r'^--wp--preset--font-size--[a-z0-9-]+$', name):
            return True
        if name in ('--header-height', '--admin-bar', '--theme-block-max-width', '--theme-block-wide-max-width'):
            return True
        if name.startswith('--has-') or name in ('--true', '--false'):
            return True
        return False

    # Track data per variable
    var_defined_in = {}
    var_option_ids = {}
    discovered_vars = set()

    # Pre-seed known core theme palette & layout options
    for i in range(1, 9):
        pal_name = f'--theme-palette-color-{i}'
        var_option_ids.setdefault(pal_name, set()).add('colorPalette')
        var_defined_in.setdefault(pal_name, set()).add('themes/blocksy/inc/dynamic-styles/global/all.php')
        var_defined_in.setdefault(pal_name, set()).add('themes/blocksy/inc/dynamic-styles/admin/colors.php')
        discovered_vars.add(pal_name)

    var_option_ids.setdefault('--theme-normal-container-max-width', set()).add('maxSiteWidth')
    var_defined_in.setdefault('--theme-normal-container-max-width', set()).add('themes/blocksy/inc/dynamic-styles/global/all.php')
    var_defined_in.setdefault('--theme-normal-container-max-width', set()).add('themes/blocksy/inc/dynamic-styles/admin-global.php')
    discovered_vars.add('--theme-normal-container-max-width')

    var_option_ids.setdefault('--theme-narrow-container-max-width', set()).add('narrowContainerWidth')
    var_defined_in.setdefault('--theme-narrow-container-max-width', set()).add('themes/blocksy/inc/dynamic-styles/global/all.php')
    discovered_vars.add('--theme-narrow-container-max-width')

    var_option_ids.setdefault('--theme-wide-offset', set()).add('wideOffset')
    var_defined_in.setdefault('--theme-wide-offset', set()).add('themes/blocksy/inc/dynamic-styles/global/all.php')
    discovered_vars.add('--theme-wide-offset')

    var_option_ids.setdefault('--theme-content-spacing', set()).add('contentSpacing')
    var_defined_in.setdefault('--theme-content-spacing', set()).add('themes/blocksy/inc/dynamic-styles/global/all.php')
    var_defined_in.setdefault('--theme-content-spacing', set()).add('themes/blocksy/inc/dynamic-styles/admin-global.php')
    discovered_vars.add('--theme-content-spacing')

    # Scan CSS files for definitions and usages
    for fpath in all_css_files:
        rel_f = get_rel_path(fpath)
        with open(fpath, errors='ignore') as f:
            content = f.read()

        # Definitions: --var:
        for m in re.finditer(r'(--[a-zA-Z0-9-]+)\s*:', content):
            vname = m.group(1)
            if is_target_variable(vname):
                discovered_vars.add(vname)
                var_defined_in.setdefault(vname, set()).add(rel_f)

        # Usages: var(--var)
        for m in re.finditer(r'var\(\s*(--[a-zA-Z0-9-]+)', content):
            vname = m.group(1)
            if is_target_variable(vname):
                discovered_vars.add(vname)

    # Scan PHP files for definitions, options, and usages
    for fpath in all_php_files:
        rel_f = get_rel_path(fpath)
        with open(fpath, errors='ignore') as f:
            content = f.read()

        # Usages: var(--var)
        for m in re.finditer(r'var\(\s*(--[a-zA-Z0-9-]+)', content):
            vname = m.group(1)
            if is_target_variable(vname):
                discovered_vars.add(vname)

        # Definitions via string '--theme-...:'
        for m in re.finditer(r'--([a-zA-Z0-9-]+)\s*:', content):
            vname = '--' + m.group(1)
            if is_target_variable(vname):
                discovered_vars.add(vname)
                var_defined_in.setdefault(vname, set()).add(rel_f)

        # Function calls: blocksy_output_...([ ... ])
        # Match function calls with balanced array brackets
        for m in re.finditer(r'(blocksy_output_[a-z_]+)\s*\(', content):
            func_name = m.group(1)
            # Find the opening bracket '['
            start_bracket = content.find('[', m.start())
            if start_bracket == -1 or start_bracket > m.end() + 5:
                continue
            depth = 0
            end_bracket = start_bracket
            for j in range(start_bracket, len(content)):
                if content[j] == '[':
                    depth += 1
                elif content[j] == ']':
                    depth -= 1
                    if depth == 0:
                        end_bracket = j
                        break
            call_body = content[start_bracket:end_bracket+1]

            # Options inside call_body
            opts = re.findall(r'(?:blocksy_get_theme_mod|blocksy_akg)\(\s*[\'\"]([a-zA-Z0-9_-]+)[\'\"]', call_body)

            # Variables inside call_body
            vars_in_call = []
            for vm in re.finditer(r'[\'\"](?:variable|variableName|property|secondColorVariableName)[\'\"]\s*=>\s*[\'\"]([a-zA-Z0-9-]+)[\'\"]', call_body):
                raw_v = vm.group(1)
                vname = raw_v if raw_v.startswith('--') else '--' + raw_v
                if is_target_variable(vname):
                    vars_in_call.append(vname)

            if func_name == 'blocksy_output_font_css':
                pm = re.search(r'[\'\"]prefix[\'\"]\s*=>\s*[\'\"]([a-zA-Z0-9_-]+)[\'\"]', call_body)
                prefix = pm.group(1) if pm else ''
                font_suffixes = ['font-family', 'font-weight', 'font-size', 'line-height', 'letter-spacing', 'text-transform', 'text-decoration']
                for fs in font_suffixes:
                    if prefix:
                        fv = f'--theme-{prefix}-{fs}'
                    else:
                        fv = f'--theme-{fs}'
                    if is_target_variable(fv):
                        vars_in_call.append(fv)

            for v in vars_in_call:
                discovered_vars.add(v)
                var_defined_in.setdefault(v, set()).add(rel_f)
                for opt in opts:
                    var_option_ids.setdefault(v, set()).add(opt)

        # Definitions via $css->put(...)
        for m in re.finditer(r'\$css->put\s*\([^,]+,\s*[\'\"]--([a-zA-Z0-9-]+):', content):
            vname = '--' + m.group(1)
            if is_target_variable(vname):
                discovered_vars.add(vname)
                var_defined_in.setdefault(vname, set()).add(rel_f)
                # Look backwards for get_theme_mod / akg
                pos = m.start()
                prev_chunk = content[max(0, pos-400):pos]
                opts = re.findall(r'(?:blocksy_get_theme_mod|blocksy_akg)\(\s*[\'\"]([a-zA-Z0-9_-]+)[\'\"]', prev_chunk)
                for opt in opts:
                    var_option_ids.setdefault(vname, set()).add(opt)

        # Also generic 'variable' => 'x' or 'variableName' => 'x' anywhere in PHP
        for m in re.finditer(r'[\'\"](?:variable|variableName|property|secondColorVariableName)[\'\"]\s*=>\s*[\'\"]([a-zA-Z0-9-]+)[\'\"]', content):
            raw_v = m.group(1)
            vname = raw_v if raw_v.startswith('--') else '--' + raw_v
            if is_target_variable(vname):
                discovered_vars.add(vname)
                var_defined_in.setdefault(vname, set()).add(rel_f)

    # Compute usage count across all scanned files
    var_usage_count = Counter()
    for fpath in all_scanned_files:
        with open(fpath, errors='ignore') as f:
            c = f.read()
        for vname in discovered_vars:
            # Count var(--vname[, )])
            pattern = r'var\(\s*' + re.escape(vname) + r'[\s,\)]'
            matches = len(re.findall(pattern, c))
            if matches > 0:
                var_usage_count[vname] += matches

    # Build final variables list
    variables_output = []
    for vname in sorted(discovered_vars):
        cat = get_variable_category(vname)

        g_info = global_css_defs.get(vname)
        val_in_global = None
        selectors_in_global = []
        is_global = False

        if g_info:
            selectors_in_global = g_info['selectors']
            if g_info['root_value'] is not None:
                val_in_global = g_info['root_value']
            else:
                val_in_global = g_info['first_value']
            # Determine global flag
            for sel in selectors_in_global:
                parts = [s.strip() for s in sel.split(',')]
                if ':root' in parts or any(':root' in p or '[data-theme]' in p for p in parts):
                    is_global = True
                    break

        defined_list = sorted(list(var_defined_in.get(vname, [])))
        option_list = sorted(list(var_option_ids.get(vname, [])))
        used_count = var_usage_count.get(vname, 0)

        item = {
            "name": vname,
            "category": cat,
            "value_in_global_css": val_in_global,
            "selectors_in_global_css": selectors_in_global,
            "option_ids": option_list,
            "defined_in": defined_list,
            "used_in_count": used_count,
            "global": is_global
        }
        variables_output.append(item)

    variables_output.sort(key=lambda x: x['name'])

    variables_json_data = {
        "blocksy_version": BLOCKSY_VERSION,
        "generated": datetime.now(timezone.utc).isoformat(),
        "source": "static extraction, see research/css-tokens.md",
        "count": len(variables_output),
        "variables": variables_output
    }

    # ---------------------------------------------------------
    # STEP 2: CSS Classes Extraction
    # ---------------------------------------------------------
    print("Extracting CSS classes...")

    skip_patterns = [
        'beaver', 'brizy', 'divi', 'elementor', 'dokan', 'bbpress', 'buddypress',
        'cf-7', 'forminator', 'getwid', 'jet-woo-builder', 'qubely', 'eventkoi',
        'tutor', 'learndash', 'wpforms', 'tribe-events', 'stackable', 'page-scroll-to-id',
        'customizer-controls', 'options', 'admin-frontend'
    ]

    bundle_css_files = glob.glob(os.path.join(theme_path, 'static', 'bundle', '*.min.css'))
    included_class_files = []
    for f in sorted(bundle_css_files):
        b = os.path.basename(f)
        if '-rtl.min.css' in b:
            continue
        name_no_ext = b.replace('.min.css', '')
        if any(name_no_ext.startswith(pat) or name_no_ext == pat for pat in skip_patterns):
            continue
        included_class_files.append(f)

    class_filters = [
        re.compile(r'^ct-'),
        re.compile(r'^has-palette-color-\d+-(color|background-color|border-color)$'),
        re.compile(r'^has-.*palette'),
        re.compile(r'^(is-width-constrained|alignwide|alignfull|entry-content|is-layout-.*)$'),
        re.compile(r'^(wp-element-button|wp-block-button__link)$'),
        re.compile(r'^is-style-')
    ]

    def matches_class_filter(c):
        return any(p.search(c) for p in class_filters)

    class_occurrence_counts = Counter()
    class_files_map = {}
    class_sample_rule = {}
    class_used_vars = {}

    for fpath in included_class_files:
        bname = os.path.basename(fpath)
        with open(fpath, errors='ignore') as f:
            css_text = f.read()

        rules = parse_css_rules(css_text)
        for sel, body in rules:
            classes_in_sel = set(re.findall(r'\.([a-zA-Z0-9_-]+)', sel))
            rule_str = f'{sel}{{{body}}}'
            truncated_rule = rule_str[:300]
            vars_in_body = re.findall(r'var\((--[a-zA-Z0-9-]+)', body)

            for c in classes_in_sel:
                if matches_class_filter(c):
                    class_occurrence_counts[c] += 1
                    class_files_map.setdefault(c, set()).add(bname)
                    if c not in class_sample_rule:
                        class_sample_rule[c] = truncated_rule
                    class_used_vars.setdefault(c, set()).update(vars_in_body)

    # Separate by family
    family_buckets = {}
    for c in class_occurrence_counts:
        fam = get_class_family(c)
        family_buckets.setdefault(fam, []).append(c)

    # Component family capping rule: top 150 by occurrence count
    component_classes = family_buckets.get('component', [])
    total_components_found = len(component_classes)
    # Sort by (-count, class_name)
    component_classes.sort(key=lambda c: (-class_occurrence_counts[c], c))
    kept_component_classes = component_classes[:150]
    dropped_component_count = max(0, total_components_found - 150)
    family_buckets['component'] = kept_component_classes

    # Assemble classes
    all_final_classes = []
    for fam, c_list in family_buckets.items():
        for c in c_list:
            item = {
                "class": c,
                "family": fam,
                "files": sorted(list(class_files_map[c])),
                "sample_rule": class_sample_rule[c],
                "uses_variables": sorted(list(class_used_vars.get(c, [])))
            }
            all_final_classes.append(item)

    # Sort by family then class
    all_final_classes.sort(key=lambda x: (x['family'], x['class']))

    classes_json_data = {
        "blocksy_version": BLOCKSY_VERSION,
        "generated": datetime.now(timezone.utc).isoformat(),
        "count": len(all_final_classes),
        "classes": all_final_classes
    }

    return variables_json_data, classes_json_data, dropped_component_count, len(included_class_files)

def main():
    theme = sys.argv[1] if len(sys.argv) > 1 else DEFAULT_THEME
    comp = sys.argv[2] if len(sys.argv) > 2 else DEFAULT_COMP
    pro = sys.argv[3] if len(sys.argv) > 3 else DEFAULT_PRO
    global_css = sys.argv[4] if len(sys.argv) > 4 else DEFAULT_GLOBAL_CSS

    print(f"THEME: {theme}")
    print(f"COMP:  {comp}")
    print(f"PRO:   {pro}")
    print(f"GLOBAL_CSS: {global_css}")

    vars_data, classes_data, dropped_components, included_files_count = extract_tokens(theme, comp, pro, global_css)

    repo_root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    data_dir = os.path.join(repo_root, 'data')
    os.makedirs(data_dir, exist_ok=True)

    vars_file = os.path.join(data_dir, 'css-variables.json')
    classes_file = os.path.join(data_dir, 'css-classes.json')

    with open(vars_file, 'w', encoding='utf-8') as f:
        json.dump(vars_data, f, indent=2)
    print(f"Wrote {vars_data['count']} variables to {vars_file}")

    with open(classes_file, 'w', encoding='utf-8') as f:
        json.dump(classes_data, f, indent=2)
    print(f"Wrote {classes_data['count']} classes to {classes_file} (dropped {dropped_components} component classes)")

if __name__ == '__main__':
    main()
