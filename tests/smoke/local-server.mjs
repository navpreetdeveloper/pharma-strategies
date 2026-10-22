import assert from 'node:assert/strict';

const base = new URL(process.env.LOCAL_TEST_URL || process.argv[2] || 'http://127.0.0.1:8000');
assert.ok(['127.0.0.1', 'localhost', '[::1]'].includes(base.hostname), 'Use a loopback test server.');

async function request(path, expectedStatus = 200) {
    const start = performance.now();
    const response = await fetch(new URL(path, base), {signal: AbortSignal.timeout(15000)});
    const body = await response.text();
    assert.equal(response.status, expectedStatus, `${path}: unexpected HTTP status`);
    assert.doesNotMatch(body, /Fatal error|Failed opening required|Warning:\s*(?:<[^>]+>\s*)*require(?:_once)?/i, `${path}: PHP startup error`);
    console.log(`${path}: ${response.status}, ${Math.round(performance.now() - start)} ms`);
    return {response, body};
}

const home = await request('/');
assert.match(home.body, /<html\b/i);
assert.match(home.body, /Private communication/i);
const login = await request('/login');
assert.match(login.body, /name="email"/);
assert.match(login.body, /name="password"/);

for (const path of ['/chat', '/platform/users', '/session/status']) {
    const result = await request(path);
    assert.equal(new URL(result.response.url).pathname, '/login', `${path}: guests must reach login`);
    assert.match(result.body, /name="password"/);
}
await request('/missing-local-smoke-page', 404);
await request('/.env', 404);
await request('/composer.json', 404);

const {body} = await request('/build/manifest.json');
const manifest = JSON.parse(body);
const assetFiles = new Set();
function collectAssets(entry) {
    assert.ok(manifest[entry], `${entry}: missing build entry`);
    assetFiles.add(manifest[entry].file);
    for (const dependency of manifest[entry].imports || []) collectAssets(dependency);
}
for (const entry of ['resources/css/app.css', 'resources/js/app.js', 'resources/js/public.js']) collectAssets(entry);
for (const file of assetFiles) {
    assert.match(file, /^assets\/[a-zA-Z0-9._-]+$/);
    const asset = await request(`/build/${file}`);
    assert.doesNotMatch(asset.body, /<!doctype html/i);
    assert.ok(asset.body.length > 0, `${file}: asset was not served`);
}
console.log('Local server smoke checks passed.');
