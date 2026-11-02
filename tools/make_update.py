"""
Pack only the files that changed since the last upload to Hostinger.

  python tools/make_update.py            # builds dist/update-<date>.zip and remembers what was packed
  python tools/make_update.py --list     # just show what changed

dist/deployed.json holds a fingerprint of every file as it is on the server. Extract the update zip
inside public_html/firetrack: it only contains the changed files, in their folders.
"""
import hashlib
import json
import os
import sys
import time
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
STATE = os.path.join(ROOT, 'dist', 'deployed.json')
SKIP_DIRS = {'.claude', 'tools', 'dist', 'sessions', '.git'}
SKIP_FILES = {'config.php', 'error.log', 'cache_public.json'}


def files():
    for d, dirs, names in os.walk(ROOT):
        dirs[:] = [x for x in dirs if x not in SKIP_DIRS]
        for f in names:
            if f in SKIP_FILES or f.endswith(('.xlsx', '.tmp')):
                continue
            p = os.path.join(d, f)
            yield '/'.join(os.path.relpath(p, ROOT).split(os.sep)), p


def fingerprint():
    return {rel: hashlib.sha256(open(p, 'rb').read()).hexdigest() for rel, p in files()}


def main():
    now = fingerprint()
    before = json.load(open(STATE, encoding='utf-8')) if os.path.exists(STATE) else {}
    changed = sorted(r for r in now if before.get(r) != now[r])
    removed = sorted(r for r in before if r not in now)
    if '--list' in sys.argv or not changed:
        print('\n'.join(changed) or 'Nothing changed since the last upload.')
        if removed:
            print('Removed (delete on the server):', ', '.join(removed))
        return
    out = os.path.join(ROOT, 'dist', 'update-' + time.strftime('%Y%m%d-%H%M') + '.zip')
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
        for rel in changed:
            z.write(os.path.join(ROOT, *rel.split('/')), rel)
    json.dump(now, open(STATE, 'w', encoding='utf-8'), indent=1, sort_keys=True)
    print(out)
    print('\n'.join(changed))
    if removed:
        print('Removed (delete on the server):', ', '.join(removed))


if __name__ == '__main__':
    main()
