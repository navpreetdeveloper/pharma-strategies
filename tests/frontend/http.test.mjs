import test from 'node:test';
import assert from 'node:assert/strict';
import {createHttpClient} from '../../resources/js/http.js';

test('custom request headers preserve CSRF, JSON and same-origin credentials', async () => {
    let request;
    const window = {FormData, fetch: async (url, options) => { request = {url, ...options}; return {ok: true}; }};
    const api = createHttpClient(window, {querySelector: () => ({content: 'csrf-token'})});
    await api('/api/v1/example', {method: 'POST', body: JSON.stringify({value: 1}), headers: {'X-Feature': 'test'}});
    assert.equal(request.credentials, 'same-origin');
    assert.equal(request.headers['X-CSRF-TOKEN'], 'csrf-token');
    assert.equal(request.headers.Accept, 'application/json');
    assert.equal(request.headers['Content-Type'], 'application/json');
    assert.equal(request.headers['X-Feature'], 'test');
});

test('file uploads keep the browser multipart boundary and HTTP errors remain available to callers', async () => {
    let headers;
    const response = {ok: false, status: 422};
    const api = createHttpClient({FormData, fetch: async (_, options) => { headers = options.headers; return response; }}, {querySelector: () => null});
    assert.equal(await api('/upload', {method: 'POST', body: new FormData()}), response);
    assert.equal(headers['Content-Type'], undefined);
});

test('network failures propagate rather than reporting false success or retrying a mutation', async () => {
    let attempts = 0;
    const api = createHttpClient({FormData, fetch: async () => { attempts++; throw new Error('Offline'); }}, {querySelector: () => null});
    await assert.rejects(api('/action', {method: 'POST'}), /Offline/);
    assert.equal(attempts, 1);
});
