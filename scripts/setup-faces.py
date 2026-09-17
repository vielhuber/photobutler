import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import urllib.request
import venv

if sys.version_info[:2] != (3, 12):
    raise RuntimeError('This pinned runtime requires Python 3.12 on Linux x86_64')

os.umask(0o077)
scripts = Path(__file__).resolve().parent
data = Path(sys.argv[1] if len(sys.argv) > 1 else scripts.parent / '.data').resolve()
runtime = data / 'face-runtime'
manifest = json.loads((scripts / 'face-models.json').read_text())
venv.create(runtime, with_pip=True)
subprocess.run([str(runtime / 'bin/python'), '-m', 'pip', 'install', '--require-hashes', '-r', str(scripts / 'face-requirements.txt')], check=True)
models = runtime / 'models'
models.mkdir(exist_ok=True)
for model in manifest['models']:
    path = models / model['file']
    if not path.exists() or hashlib.sha256(path.read_bytes()).hexdigest() != model['sha256']:
        url = f"https://media.githubusercontent.com/media/opencv/opencv_zoo/{manifest['commit']}/models/{model['directory']}/{model['file']}"
        content = urllib.request.urlopen(url, timeout=120).read()
        if hashlib.sha256(content).hexdigest() != model['sha256']:
            raise RuntimeError('Model checksum mismatch')
        path.write_bytes(content)
    url = f"https://raw.githubusercontent.com/opencv/opencv_zoo/{manifest['commit']}/models/{model['directory']}/LICENSE"
    (models / (model['directory'] + '-LICENSE')).write_bytes(urllib.request.urlopen(url, timeout=30).read())
print('Local CPU face runtime installed; no photos processed.')
