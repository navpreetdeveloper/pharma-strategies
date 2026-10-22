import {readFile, writeFile, mkdir, unlink} from 'node:fs/promises';
import {resolve} from 'node:path';
import {spawn, execFile} from 'node:child_process';
import {promisify} from 'node:util';
import {randomUUID, randomBytes, createHmac} from 'node:crypto';
import assert from 'node:assert/strict';

const execute = promisify(execFile);
const root = resolve('.');
const directory = resolve('storage/app/private/performance');
await mkdir(directory, {recursive: true});
const php = process.env.PERFORMANCE_PHP || 'C:\\php\\php.exe';
const targets = await (await fetch('http://127.0.0.1:9231/json')).json();
const target = targets.find(t => t.type === 'page');
const ws = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((ok, fail) => { ws.addEventListener('open', ok, {once: true}); ws.addEventListener('error', fail, {once: true}); });
let sequence = 0;
const pending = new Map();
const exceptions = [];
ws.addEventListener('message', event => {
    const data = JSON.parse(event.data);
    if (data.id) {
        const task = pending.get(data.id); pending.delete(data.id);
        if (data.error) task?.reject(new Error(data.error.message)); else task?.resolve(data.result);
    }
    if (data.method === 'Runtime.exceptionThrown') exceptions.push(data.params.exceptionDetails.text);
});
const send = (method, params = {}) => new Promise((resolve, reject) => { const id = ++sequence; pending.set(id, {resolve, reject}); ws.send(JSON.stringify({id, method, params})); });
const evaluate = async expression => {
    const r = await send('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true});
    if (r.exceptionDetails) throw new Error('Browser evaluation failed.');
    return r.result.value;
};
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
let worker;
let database;
try {
    await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
    await send('Network.setCacheDisabled', {cacheDisabled: true});
    await send('Page.addScriptToEvaluateOnNewDocument', {source: `window.__lcp=0;new PerformanceObserver(list=>{for(const e of list.getEntries())window.__lcp=e.startTime;}).observe({type:'largest-contentful-paint',buffered:true});`});
    const pages = [];
    for (const width of [375, 768, 1366]) {
        await send('Emulation.setDeviceMetricsOverride', {width, height: 900, deviceScaleFactor: 1, mobile: width === 375});
        for (const path of ['/', '/login']) {
            await send('Page.navigate', {url: `http://127.0.0.1:8000${path}`});
            for (let i = 0; i < 100; i++) {
                if (await evaluate(`document.readyState==='complete' && location.pathname===${JSON.stringify(path)}`)) break;
                await delay(50);
            }
            await delay(200);
            const metrics = await evaluate(`({path:location.pathname,width:innerWidth,overflow:document.documentElement.scrollWidth>innerWidth,lcp_ms:window.__lcp,load_ms:performance.getEntriesByType('navigation')[0]?.loadEventEnd,valid:document.body.textContent.includes(${JSON.stringify(path === '/' ? 'Private communication' : 'Work email')}),feedback_ready:!!window.PharmaFeedback})`);
            assert.equal(metrics.overflow, false); assert.equal(metrics.valid, true); assert.equal(metrics.feedback_ready, true);
            pages.push(metrics);
        }
    }
    assert.deepEqual(exceptions, []);

    // Subscribe only to a synthetic, very high ID; no real user channel is used.
    const environment = {};
    for (const line of (await readFile('.env', 'utf8')).split(/\r?\n/)) {
        const match = line.match(/^([A-Z0-9_]+)=(.*)$/);
        if (match) environment[match[1]] = match[2].replace(/^['"]|['"]$/g, '');
    }
    const key = environment.REVERB_APP_KEY;
    const secret = environment.REVERB_APP_SECRET;
    database = resolve(directory, `queue-${randomUUID()}.sqlite`);
    await writeFile(database, '');
    const env = {...process.env, APP_ENV: 'performance', APP_DEBUG: 'false', APP_KEY: 'base64:'+randomBytes(32).toString('base64'), DB_CONNECTION: 'sqlite', DB_DATABASE: database, SESSION_DRIVER: 'array', CACHE_STORE: 'array', QUEUE_CONNECTION: 'database', WEB_PUSH_ENABLED: 'false', REQUIRE_PRIVILEGED_MFA: 'false', REQUIRE_EMAIL_VERIFICATION: 'false', BROADCAST_CONNECTION: 'reverb'};
    const fixture = async action => {
        const {stdout} = await execute(php, ['-d', 'opcache.enable_cli=1', 'tests/Performance/queue-fixture.php', action], {cwd: root, env, timeout: 30000});
        return JSON.parse(stdout.trim());
    };
    const {recipient} = await fixture('setup');
    await evaluate(`window.__probe={messages:[],socket:null};window.__probe.ws=new WebSocket(${JSON.stringify(`ws://127.0.0.1:8081/app/${key}?protocol=7&client=performance&version=1&flash=false`)});window.__probe.ws.onmessage=e=>{const m=JSON.parse(e.data);if(m.event==='pusher:connection_established')window.__probe.socket=JSON.parse(m.data).socket_id;if(m.event==='pusher_internal:subscription_succeeded')window.__probe.subscribed=true;if(m.event==='workplace.notification')window.__probe.messages.push({data:JSON.parse(m.data).data,received_ms:Date.now()});};true`);
    for (let i = 0; i < 100 && !await evaluate('window.__probe.socket'); i++) await delay(50);
    const socketId = await evaluate('window.__probe.socket');
    assert.ok(socketId, 'Realtime connection did not establish.');
    const channel = `private-App.Models.User.${recipient}`;
    const signature = createHmac('sha256', secret).update(`${socketId}:${channel}`).digest('hex');
    await evaluate(`window.__probe.ws.send(${JSON.stringify(JSON.stringify({event: 'pusher:subscribe', data: {channel, auth: `${key}:${signature}`}}))});true`);
    for (let i = 0; i < 100 && !await evaluate('!!window.__probe.subscribed'); i++) await delay(50);
    assert.equal(await evaluate('!!window.__probe.subscribed'), true);
    const queue = [];
    for (const sleep of ['1', '0.2']) {
        worker = spawn(php, ['-d', 'opcache.enable_cli=1', 'artisan', 'queue:work', 'database', '--queue=default,push', `--sleep=${sleep}`, '--tries=3', '--timeout=20'], {cwd: root, env, windowsHide: true, stdio: 'ignore'});
        await delay(1000);
        const samples = [];
        for (let i = 0; i < 5; i++) {
            await delay(100 + Math.random() * 300);
            const message = await fixture('enqueue');
            let delivered;
            for (let j = 0; j < 150; j++) {
                delivered = await evaluate(`window.__probe.messages.find(m=>Number(m.data.message_id)===${message.message_id})`);
                if (delivered) break;
                await delay(20);
            }
            assert.ok(delivered, 'Synthetic queued message did not reach the browser.');
            samples.push({persist_ms: message.persist_ms, delivery_ms: delivered.received_ms - message.started_ms});
        }
        for (let j = 0; j < 100; j++) { if ((await fixture('status')).pending === 0) break; await delay(50); }
        const status = await fixture('status');
        assert.equal(status.pending, 0); assert.equal(status.undelivered, 0);
        queue.push({sleep_seconds: Number(sleep), samples, backlog: status});
        worker.kill(); await new Promise(resolve => worker.once('exit', resolve)); worker = null;
    }
    const report = {conditions: 'Local headless Chrome; cold asset cache; SQLite synthetic outbox and production Reverb transport; one private synthetic channel; no push provider calls; five queue samples per configuration', pages, queue, browser_exceptions: exceptions};
    await writeFile(resolve(directory, 'browser-and-queue.json'), JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report, null, 2));
} finally {
    if (worker) worker.kill();
    if (database) await unlink(database).catch(() => {});
    try { await send('Browser.close'); } catch {}
    ws.close();
}
