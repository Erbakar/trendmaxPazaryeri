#!/usr/bin/env python3
"""Build a clean web release; never includes local/customer runtime data."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parent.parent
ROOT_FILES = {'.htaccess', '.env.example', 'index.php', 'installer.php',
              'composer.json', 'composer.lock', 'robots.txt', 'logom.png',
              'ozellikler.png', 'LICENSING.html', 'kurulum.html',
              'pazaryeri-kurulum-rehberi.html'}
SOURCE_DIRS = {'app', 'config', 'cron', 'database', 'migrations', 'views', 'modules', 'vendor', 'public', 'api-kurulum'}
PUBLIC_PACKAGE = 'public/downloads/ozyconnect-opencart-connector-1.0.0.zip'


def include(name):
    p = Path(name)
    if p.is_absolute() or '..' in p.parts:
        return False
    if name == PUBLIC_PACKAGE:
        return True
    if p.suffix.lower() in {'.zip', '.tar', '.tgz', '.gz', '.sqlbak', '.log', '.bak'}:
        return False
    if name.startswith(('storage/', 'public/uploads/')):
        return p.name in {'.gitkeep', '.htaccess'}
    if any(part.startswith('.') for part in p.parts):
        return name in {'.htaccess', '.env.example'} or p.name == '.htaccess'
    if name in {'tools/store-archive.php', 'tools/reconcile-trendyol-listings.php'}:
        return True
    return name in ROOT_FILES or p.parts[0] in SOURCE_DIRS


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', default=str(ROOT / 'storage/local/releases/trendmax-web.zip'))
    parser.add_argument('--composer', default='composer', help='Composer command or verified composer.phar path')
    args = parser.parse_args()
    output = Path(args.output).resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    # Include working changes as well as tracked sources, excluding ignored data.
    names = subprocess.check_output(
        ['git', 'ls-files', '-c', '-o', '--exclude-standard', '-z'], cwd=ROOT
    ).decode().split('\0')
    manifest = {}
    with tempfile.TemporaryDirectory(prefix='trendmax-release-') as temp:
        stage = Path(temp)
        for name in sorted(set(names)):
            if not name or name.startswith('vendor/') or not include(name):
                continue
            file = ROOT / name
            if file.is_symlink():
                raise RuntimeError('Symlinks are not allowed in releases: ' + name)
            if not file.is_file():
                continue
            target = stage / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(file.read_bytes())
        composer = ['php', str(Path(args.composer).resolve())] if args.composer.endswith('.phar') else [args.composer]
        subprocess.run(composer + ['install', '--no-dev', '--prefer-dist', '--optimize-autoloader',
                                  '--no-scripts', '--no-interaction'], cwd=stage, check=True)
        subprocess.run(composer + ['check-platform-reqs', '--no-dev'], cwd=stage, check=True)
        with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
            for file in sorted(stage.rglob('*')):
                if not file.is_file():
                    continue
                name = file.relative_to(stage).as_posix()
                content = file.read_bytes()
                entry = zipfile.ZipInfo(name)
                entry.create_system = 3
                entry.external_attr = (0o100644 << 16)
                entry.compress_type = zipfile.ZIP_DEFLATED
                archive.writestr(entry, content)
                manifest[name] = hashlib.sha256(content).hexdigest()
    output.with_suffix('.manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    print(json.dumps({'file': str(output), 'files': len(manifest),
                      'sha256': hashlib.sha256(output.read_bytes()).hexdigest()}))


if __name__ == '__main__':
    main()
