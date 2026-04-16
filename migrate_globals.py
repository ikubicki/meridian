#!/usr/bin/env python3
"""
Replaces legacy phpBB global variable declarations with phpbb\Container calls.

For each `global $db, $config, ...;` statement in PHP files:
  - Removes the global declaration for mappable services
  - Adds `global $phpbb_app_container;` + `$x = $phpbb_app_container->getX();` calls
  - Keeps un-mappable globals (SID, root_path, etc.) as-is
  - Deduplicates consecutive `global $phpbb_app_container;` lines
"""

import re
import sys

CONTAINER_MAP = {
    '$db':                      '$phpbb_app_container->getDb()',
    '$config':                  '$phpbb_app_container->getConfig()',
    '$user':                    '$phpbb_app_container->getUser()',
    '$auth':                    '$phpbb_app_container->getAuth()',
    '$template':                '$phpbb_app_container->getTemplate()',
    '$request':                 '$phpbb_app_container->getRequest()',
    '$cache':                   '$phpbb_app_container->getCache()',
    '$language':                '$phpbb_app_container->getLanguage()',
    '$phpbb_dispatcher':        '$phpbb_app_container->getDispatcher()',
    '$phpbb_log':               '$phpbb_app_container->getLog()',
    '$phpbb_extension_manager': '$phpbb_app_container->getExtensionManager()',
    '$phpbb_filesystem':        '$phpbb_app_container->getFilesystem()',
    '$phpbb_path_helper':       '$phpbb_app_container->getPathHelper()',
    '$phpbb_container':         '$phpbb_app_container->get(\'service_container\')',
}


def replace_global_statement(match):
    indent = match.group(1)
    vars_str = match.group(2)

    vars_list = [v.strip() for v in vars_str.split(',')]

    keep_vars = []
    container_vars = []

    for var in vars_list:
        if var in CONTAINER_MAP:
            container_vars.append(var)
        else:
            keep_vars.append(var)

    if not container_vars:
        return match.group(0)

    lines = []

    if keep_vars:
        lines.append(f'{indent}global {", ".join(keep_vars)};')

    lines.append(f'{indent}global $phpbb_app_container;')
    for var in container_vars:
        lines.append(f'{indent}{var} = {CONTAINER_MAP[var]};')

    return '\n'.join(lines)


def deduplicate_app_container_globals(content):
    """Remove consecutive duplicate `global $phpbb_app_container;` lines."""
    return re.sub(
        r'((\t+)global \$phpbb_app_container;\n)(\2global \$phpbb_app_container;\n)+',
        r'\1',
        content
    )


def process_file(filepath):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    # Match tab-indented global statements
    pattern = r'(\t+)global (\$[A-Za-z_$][A-Za-z0-9_, $]*);'
    new_content = re.sub(pattern, replace_global_statement, content)
    new_content = deduplicate_app_container_globals(new_content)

    if new_content != content:
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(new_content)
        return True
    return False


if __name__ == '__main__':
    changed = 0
    for filepath in sys.argv[1:]:
        try:
            if process_file(filepath):
                print(f'  modified: {filepath}')
                changed += 1
        except Exception as e:
            print(f'  ERROR {filepath}: {e}')
    print(f'\nTotal modified: {changed}/{len(sys.argv) - 1}')
