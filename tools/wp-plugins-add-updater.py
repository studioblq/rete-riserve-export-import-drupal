"""One-off: adds the Area051 GitHub updater to every WordPress plugin.

For each plugin in wordpress-plugin/<slug>/<slug>.php:
- copies _shared/class-area051-github-updater.php to <slug>/lib/
- sets Author / Author URI to area051.com and adds Update URI
- loads the updater right after the ABSPATH guard
- bumps the patch version (only with --bump)

Idempotent: running it again changes nothing already in place.
Usage: python tools/wp-plugins-add-updater.py [--bump]
"""
import os
import re
import shutil
import sys

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'wordpress-plugin')
SHARED = os.path.join(ROOT, '_shared', 'class-area051-github-updater.php')
UPDATE_URI = 'https://github.com/studioblq/rete-riserve-export-import-drupal'
AUTHOR = 'area051.com'
AUTHOR_URI = 'https://area051.com'
BUMP = '--bump' in sys.argv

LOADER = (
    "require_once __DIR__ . '/lib/class-area051-github-updater.php';\n"
    "new Area051_GitHub_Updater_V3(__FILE__, '{slug}');\n"
)


def plugins():
    for slug in sorted(os.listdir(ROOT)):
        main = os.path.join(ROOT, slug, slug + '.php')
        if slug.startswith('_') or not os.path.isfile(main):
            continue
        with open(main, encoding='utf-8', newline='') as fh:
            if 'Plugin Name:' not in fh.read(4000):
                continue
        yield slug, main


def set_header(header, key, value, after_key=None):
    """Sets ' * Key: value' in the plugin header, keeping the existing alignment."""
    pattern = re.compile(r'^(\s*\*\s*' + re.escape(key) + r':)(\s*)(.*)$', re.M)
    m = pattern.search(header)
    if m:
        spacing = m.group(2) if m.group(2) else ' '
        return header[:m.start()] + m.group(1) + spacing + value + header[m.end():]

    anchor = re.compile(r'^\s*\*\s*' + re.escape(after_key or 'Version') + r':.*$', re.M).search(header)
    if not anchor:
        anchor = re.compile(r'^\s*\*\s*Plugin Name:.*$', re.M).search(header)
    line = ' * ' + key + ': ' + value
    return header[:anchor.end()] + '\n' + line + header[anchor.end():]


def bump(version):
    parts = version.strip().split('.')
    while len(parts) < 3:
        parts.append('0')
    parts[-1] = str(int(re.sub(r'\D.*', '', parts[-1]) or 0) + 1)
    return '.'.join(parts)


for slug, main in plugins():
    with open(main, encoding='utf-8', newline='') as fh:
        raw = fh.read()
    nl = '\r\n' if '\r\n' in raw else '\n'
    # Some files have broken "\r\r\n" endings: normalise them to the file's style.
    src = raw.replace('\r\r\n', '\n').replace('\r\n', '\n')

    end = src.index('*/')
    header, body = src[:end], src[end:]

    header = set_header(header, 'Author', AUTHOR)
    header = set_header(header, 'Author URI', AUTHOR_URI, after_key='Author')
    header = set_header(header, 'Update URI', UPDATE_URI, after_key='Author URI')

    version_match = re.search(r'^\s*\*\s*Version:\s*(\S+)', header, re.M)
    version = version_match.group(1)
    if BUMP and 'Area051_GitHub_Updater_V3' not in body:
        new_version = bump(version)
        header = set_header(header, 'Version', new_version)
        # Keep version constants in sync, e.g. define('SJAI_VERSION', '1.6.0').
        body = re.sub(r"(define\(\s*'[A-Z0-9_]*VERSION'\s*,\s*')" + re.escape(version) + "'", r"\g<1>" + new_version + "'", body)
        version = new_version

    if 'Area051_GitHub_Updater_V3' not in body:
        guard = re.search(r"if\s*\(\s*!\s*defined\(\s*'ABSPATH'\s*\)\s*\)\s*\{\s*exit;\s*\}\n", body)
        if not guard:
            raise SystemExit('ABSPATH guard not found in ' + main)
        body = body[:guard.end()] + '\n' + LOADER.format(slug=slug) + body[guard.end():]

    with open(main, 'w', encoding='utf-8', newline='') as fh:
        fh.write((header + body).replace('\n', nl))

    lib = os.path.join(ROOT, slug, 'lib')
    os.makedirs(lib, exist_ok=True)
    shutil.copyfile(SHARED, os.path.join(lib, 'class-area051-github-updater.php'))
    print('%-32s %s' % (slug, version))
