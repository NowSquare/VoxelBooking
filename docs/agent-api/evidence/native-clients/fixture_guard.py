"""Reject ordinary installations; these scripts approve synthetic fixture reviews only."""
import json
from pathlib import Path
from urllib.parse import urlparse
from urllib.request import Request, urlopen

def client_root(value):
    root = Path(value).resolve()
    manifest = json.loads((root / 'manifest.json').read_text())
    run_id = (root / '.test-run').read_text().strip()
    assert manifest['run_id'] == run_id and manifest['run_root'] == str(root)
    assert root.name == 'voxelbooking-test-' + run_id
    assert manifest['environment']['APP_ENV'] == 'test'
    assert manifest['database']['DB_USERNAME'] == 'vb_test'
    fixture = json.loads((root / 'clients' / 'fixture.json').read_text())
    assert fixture['base'] == manifest['environment']['APP_TEST_URL']
    url = urlparse(fixture['base'])
    assert url.scheme == 'http' and url.hostname == '127.0.0.1'
    response = urlopen(Request(fixture['base'] + '/__test/ready', headers={'X-Test-Run': run_id}), timeout=5)
    assert response.read().decode().strip() == run_id
    return root / 'clients'
