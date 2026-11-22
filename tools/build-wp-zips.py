"""Builds installable zips of the WordPress plugins in the repository root.

For every plugin in wordpress-plugin/<slug>/<slug>.php it writes <slug>.zip in
the repository root, with the same layout as the GitHub release zips
(<slug>/... inside, shared updater in lib/, no debug.log or old zips).

A zip is rebuilt only when it is missing or its plugin version differs from
the one in the source, so running it often is cheap. Zips are ignored by git
(.gitignore), they stay on this computer only.

Usage:
    python tools/build-wp-zips.py           # build new versions only
    python tools/build-wp-zips.py --force   # rebuild every zip

Also run automatically by the local git hook .git/hooks/post-commit
(see tools/install-git-hooks.sh).
"""
import fnmatch
import os
import re
import sys
import zipfile

REPO = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
PLUGINS = os.path.join(REPO, 'wordpress-plugin')
SHARED_UPDATER = os.path.join(PLUGINS, '_shared', 'class-area051-github-updater.php')
OUT_DIR = REPO
EXCLUDE = ['*.zip', 'debug.log', '.DS_Store', '.git*', 'node_modules', 'Thumbs.db']
FORCE = '--force' in sys.argv

VERSION_RE = re.compile(r'^\s*\*?\s*Version:\s*(\S+)', re.M)


def plugin_version(text):
    match = VERSION_RE.search(text[:4000])
    return match.group(1) if match else ''


def plugins():
    for slug in sorted(os.listdir(PLUGINS)):
        main = os.path.join(PLUGINS, slug, slug + '.php')
        if slug.startswith('_') or not os.path.isfile(main):
            continue
        with open(main, encoding='utf-8', errors='replace') as fh:
            text = fh.read()
        if 'Plugin Name:' not in text[:4000]:
            continue
        yield slug, plugin_version(text)


def zipped_version(zip_path, slug):
    try:
        with zipfile.ZipFile(zip_path) as zf:
            text = zf.read(slug + '/' + slug + '.php').decode('utf-8', errors='replace')
        return plugin_version(text)
    except (OSError, KeyError, zipfile.BadZipFile):
        return None


def excluded(name):
    return any(fnmatch.fnmatch(name, pattern) for pattern in EXCLUDE)


def build(slug, zip_path):
    src = os.path.join(PLUGINS, slug)
    tmp = zip_path + '.tmp'
    with zipfile.ZipFile(tmp, 'w', zipfile.ZIP_DEFLATED) as zf:
        for root, dirs, files in os.walk(src):
            dirs[:] = sorted(d for d in dirs if not excluded(d))
            rel_root = os.path.relpath(root, PLUGINS).replace(os.sep, '/')
            zf.write(root, rel_root + '/')
            for name in sorted(files):
                if excluded(name):
                    continue
                arcname = rel_root + '/' + name
                path = os.path.join(root, name)
                # Always ship the canonical updater, like the GitHub release does.
                if arcname == slug + '/lib/class-area051-github-updater.php' and os.path.isfile(SHARED_UPDATER):
                    path = SHARED_UPDATER
                zf.write(path, arcname)
    os.replace(tmp, zip_path)


built = []
for slug, version in plugins():
    zip_path = os.path.join(OUT_DIR, slug + '.zip')
    current = zipped_version(zip_path, slug) if os.path.isfile(zip_path) else None
    if not FORCE and current == version:
        continue
    build(slug, zip_path)
    built.append('%s %s%s' % (slug, version, '' if current is None else ' (era %s)' % current))

if built:
    print('Zip plugin aggiornati in %s:' % OUT_DIR)
    for line in built:
        print('  ' + line)
