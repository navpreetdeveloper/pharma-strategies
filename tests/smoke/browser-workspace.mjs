// Real browser/auth/realtime acceptance using disposable accounts, never the app DB.
import assert from 'node:assert/strict';
import {mkdir, writeFile, readFile, unlink, appendFile} from 'node:fs/promises';
import {resolve} from 'node:path';
import {spawn, execFile} from 'node:child_process';
import {promisify} from 'node:util';
import {randomUUID, randomBytes} from 'node:crypto';

const root = resolve('.');
const directory = resolve('storage/app/private/performance');
await mkdir(directory, {recursive: true});
const id = randomUUID();
const database = resolve(directory, `queue-${id}.sqlite`);
const mysql = process.env.PERFORMANCE_DB === 'mysql' || process.argv.includes('--mysql');
const databaseName = mysql ? 'pharma_probe_'+id.replaceAll('-','') : database;
const httpLog = resolve(directory, `http-${id}.jsonl`);
const php = process.env.PERFORMANCE_PHP || 'C:\\php\\php.exe';
const chrome = process.env.PERFORMANCE_CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const env = {...process.env, APP_ENV: 'performance', APP_DEBUG: 'false', APP_URL: 'http://127.0.0.1:8002', APP_KEY: 'base64:'+randomBytes(32).toString('base64'), DB_CONNECTION: mysql ? 'mysql' : 'sqlite', DB_DATABASE: databaseName, SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', SESSION_DOMAIN: '', CACHE_STORE: 'array', QUEUE_CONNECTION: 'database', WEB_PUSH_ENABLED: 'false', MAIL_MAILER: 'array', REQUIRE_PRIVILEGED_MFA: 'false', REQUIRE_EMAIL_VERIFICATION: 'false'};
const execute = promisify(execFile);
env.PERFORMANCE_HTTP_LOG = httpLog;
const processes = [];
const debugPort = 16000 + Math.floor(Math.random() * 12000);
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
let ws;
let closeBrowser;
const reports = {conditions: 'Disposable SQLite accounts; real HTTP login/forms; separate Chrome browser contexts; real local Reverb; no mail or push provider calls', layouts: [], deliveries_ms: [], interactions: []};
reports.conditions = 'Disposable accounts; real HTTP login/forms; separate Chrome browser contexts; real local Reverb; no mail or push provider calls';
reports.database = mysql ? 'Local MySQL/MariaDB disposable database' : 'Disposable SQLite file';
let fixtureCreated = false;
try {
    await writeFile(database, '');
    const {stdout} = await execute(php, ['-d', 'opcache.enable_cli=0', 'tests/Performance/queue-fixture.php', 'setup-browser'], {cwd: root, env, timeout: 120000});
    const fixture = JSON.parse(stdout.trim());
    fixtureCreated = true;
    console.log('Disposable browser fixture ready.');
    const start = (binary, args, cwd, environment = env) => {
        const child = spawn(binary, args, {cwd, env: environment, windowsHide: true, stdio: binary === chrome ? ['ignore', 'ignore', 'pipe'] : 'ignore'});
        if (binary === chrome) child.stderr.on('data', data => appendFile(resolve(directory, 'chrome-'+id+'.log'), data).catch(()=>{}));
        processes.push(child); return child;
    };
    start(php, ['-d', 'opcache.enable_cli=1', '-d', 'opcache.cache_id=pharma-browser-web-'+id, '-d', 'opcache.memory_consumption=192', '-d', 'opcache.max_accelerated_files=20000', '-d', 'upload_max_filesize=128M', '-d', 'post_max_size=128M', '-d', 'memory_limit=256M', '-d', 'auto_prepend_file='+resolve('tests/Performance/profile-request.php'), '-S', '127.0.0.1:8002', '-t', resolve('public'), resolve('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], resolve('public'));
    start(php, ['-d', 'opcache.enable_cli=1', '-d', 'opcache.cache_id=pharma-browser-worker-'+id, 'artisan', 'queue:work', 'database', '--queue=default,push', '--sleep=0.2', '--tries=3', '--timeout=60'], root);
    start(chrome, ['--headless=new', '--disable-gpu', '--disable-background-timer-throttling', '--disable-renderer-backgrounding', '--disable-backgrounding-occluded-windows', '--no-first-run', '--no-default-browser-check', '--disable-default-apps', '--disable-background-networking', '--remote-debugging-address=127.0.0.1', '--remote-debugging-port='+debugPort, `--user-data-dir=${resolve(directory, 'workspace-browser-'+id)}`, 'about:blank'], root, process.env);
    let browser;
    for (let i=0; i<100; i++) {
        try { browser = await (await fetch('http://127.0.0.1:'+debugPort+'/json/version')).json(); if (browser.webSocketDebuggerUrl && (await fetch('http://127.0.0.1:8002/login')).ok) break; } catch {}
        await sleep(100);
    }
    assert.ok(browser?.webSocketDebuggerUrl, 'Browser did not start');
    ws = new WebSocket(browser.webSocketDebuggerUrl);
    await new Promise((ok, fail) => {ws.addEventListener('open', ok, {once:true}); ws.addEventListener('error', event => fail(new Error(event.error?.message || event.message || 'Browser debugger WebSocket connection failed', {cause: event.error})), {once:true});});
    let sequence=0;
    const pending=new Map();
    const errors=[];
    ws.addEventListener('message', event => {
        const m=JSON.parse(event.data);
        if (m.id) {const p=pending.get(m.id); pending.delete(m.id); if(p)clearTimeout(p.timer); if(m.error)p?.reject(new Error(m.error.message));else p?.resolve(m.result);}
        if(m.method==='Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.text);
    });
    const send=(method, params={}, sessionId) => new Promise((resolve,reject)=> {const id=++sequence;const timer=setTimeout(()=>{pending.delete(id);reject(new Error('Browser command timed out: '+method));},30000);pending.set(id,{resolve,reject,timer});ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});
    console.log('Browser debugger connected.');
    closeBrowser = () => send('Browser.close');
    const evaluate=async (session, expression) => {
        const r=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true},session);
        if(r.exceptionDetails) throw new Error('Browser evaluation failed');
        return r.result.value;
    };
    const until=async (session, expression) => {
        for(let i=0;i<400;i++){if(await evaluate(session,expression))return;await sleep(50);}
        throw new Error('Browser condition timed out');
    };
    const targets = new Map();
    const context=async () => {
        const {browserContextId}=await send('Target.createBrowserContext');
        const {targetId}=await send('Target.createTarget',{url:'about:blank',browserContextId});
        const {sessionId}=await send('Target.attachToTarget',{targetId,flatten:true});
        targets.set(sessionId, targetId);
        await send('Page.enable',{},sessionId);await send('Runtime.enable',{},sessionId);
        return sessionId;
    };
    const navigate=async (session,path) => {
        await evaluate(session,'window.__testOldDocument=true;true');
        await send('Page.navigate',{url:'http://127.0.0.1:8002'+path},session);
        await until(session,`!window.__testOldDocument && document.readyState==='complete' && location.pathname===${JSON.stringify(path.split('?')[0])} && !!window.PharmaFeedback`);
    };
    const login=async (session,email,landing) => {
        await navigate(session,'/login');
        await evaluate(session,`document.querySelector('[name=email]').value=${JSON.stringify(email)};document.querySelector('[name=password]').value=${JSON.stringify(fixture.password)};document.querySelector('form').requestSubmit();true`);
        await until(session,`document.readyState==='complete' && location.pathname===${JSON.stringify(landing)} && !!window.PharmaFeedback`);
    };
    const sender=await context(), recipient=await context();
    console.log('Checking isolated sender login.');
    await login(sender,fixture.sender_email,'/chat');
    console.log('Sender login passed.');
    assert.equal(await evaluate(sender,"JSON.parse(document.querySelector('[data-chat-config]').textContent).conversationId"),null,'Login should open the chat list');
    await login(recipient,fixture.recipient_email,'/chat');
    for (const session of [sender,recipient]) await navigate(session,'/chat?conversation='+fixture.conversation);
    await send('Page.bringToFront',{},recipient);
    await until(recipient,`window.Echo?.connector?.pusher?.channels?.channels[${JSON.stringify('private-App.Models.User.'+fixture.recipient)}]?.subscribed`);
    await evaluate(recipient,`window.__liveReceived=0;window.Echo.private(${JSON.stringify('App.Models.User.'+fixture.recipient)}).listen('.workplace.notification',()=>window.__liveReceived++);true`);
    for(const session of [sender,recipient]) await evaluate(session,"window.__requests=[];window.__originalFetch=window.fetch;window.fetch=async (...args)=>{const start=Date.now();const r=await window.__originalFetch(...args);if(r.ok&&new URL(args[0],location.href).pathname.endsWith('/read'))window.__lastRead=JSON.parse(args[1].body).last_message_id;window.__requests.push({path:new URL(args[0],location.href).pathname,start,ms:Date.now()-start});return r;};true");
    reports.request_timings = [];
    for(let i=0;i<5;i++) {
        const started=Date.now();
        await evaluate(sender,`document.getElementById('message').value=${JSON.stringify('Synthetic browser message '+i)};document.getElementById('composer').requestSubmit();true`);
        await until(recipient,`Array.from(document.querySelectorAll('#messages .nc-message-body')).some(n=>n.textContent===${JSON.stringify('Synthetic browser message '+i)})`);
        reports.deliveries_ms.push(Date.now()-started);
        await until(sender,"!document.getElementById('send-button').disabled");
        await until(recipient,`window.__liveReceived>=${i+1}`);
        reports.request_timings.push({sender:await evaluate(sender,'window.__requests.splice(0)'),recipient:await evaluate(recipient,'window.__requests.splice(0)'),recipient_visibility:await evaluate(recipient,'document.visibilityState')});
    }
    assert.equal(await evaluate(recipient,"document.querySelectorAll('#messages [data-message-id]').length"),5,'Messages must not duplicate');
    console.log(JSON.stringify({live_message_samples_ms:reports.deliveries_ms}));
    await writeFile(resolve(directory,'browser-workspace-checkpoint.json'),JSON.stringify(reports,null,2));
    reports.interactions.push('Two isolated logins; login opens list; five live messages render without reload or duplicates');
    await until(recipient,"window.__lastRead>=Math.max(...Array.from(document.querySelectorAll('#messages [data-message-id]'),row=>Number(row.dataset.messageId)))");
    await navigate(recipient,'/chat');
    assert.ok(await evaluate(recipient,`!!document.querySelector('[data-conversation-id="${fixture.conversation}"]')`),'Sent conversation must appear');
    await evaluate(sender,"document.getElementById('message').value='Synthetic unread message';document.getElementById('composer').requestSubmit();true");
    await until(recipient,`!!document.querySelector('[data-conversation-id="${fixture.conversation}"] [data-unread-count="1"]')`);
    await navigate(recipient,'/chat?conversation='+fixture.conversation);
    await until(recipient,`!document.querySelector('[data-conversation-id="${fixture.conversation}"] [data-unread-count]')`);
    reports.interactions.push('A new message updates unread badge without refresh; opening the thread clears it');
    await until(recipient,`window.Echo?.connector?.pusher?.channels?.channels[${JSON.stringify('private-conversation.'+fixture.conversation)}]?.subscribed`);
    const editedId=await evaluate(sender,"Array.from(document.querySelectorAll('#messages [data-message-id]')).at(-1).dataset.messageId");
    // Simulate lost mutation events: polling must reconcile existing rows too.
    await evaluate(recipient,`window.Echo.private(${JSON.stringify('conversation.'+fixture.conversation)}).stopListening('.message.edited').stopListening('.message.deleted');true`);
    await evaluate(sender,`document.querySelector('[data-message-id="${editedId}"] .nc-message-more').click();document.querySelector('#global-message-menu [data-message-action=edit]').click();document.querySelector('.nc-edit-form textarea').value='Synthetic browser edit';document.querySelector('.nc-edit-form').requestSubmit();true`);
    await until(recipient,`document.querySelector('[data-message-id="${editedId}"] .nc-message-body')?.textContent==='Synthetic browser edit'`);
    await evaluate(sender,`document.querySelector('[data-message-id="${editedId}"] .nc-message-more').click();document.querySelector('#global-message-menu [data-message-action=delete]').click();true`);
    await until(sender,"!!document.querySelector('.swal2-confirm')");
    await evaluate(sender,"document.querySelector('.swal2-confirm').click();true");
    await until(recipient,`document.querySelector('[data-message-id="${editedId}"]')?.classList.contains('is-deleted')`);
    reports.interactions.push('Missed edit and unsend events recover from the database without reload');

    // Layout checks need one active page, not abandoned chat tabs polling the
    // single-process local PHP server throughout the remaining checks.
    await send('Target.closeTarget', {targetId: targets.get(recipient)});
    await navigate(sender, '/profile');

    const admin=await context();await login(admin,fixture.admin_email,'/company');
    const platform=await context();await login(platform,fixture.platform_email,'/platform/companies');
    const publicSession=await context();
    const pages=process.env.PERFORMANCE_REALTIME_ONLY === '1' ? [] : [
        [publicSession,'/'],[publicSession,'/login'],[publicSession,'/privacy'],[publicSession,'/forgot-password'],[sender,'/workspace/privacy'],[sender,'/profile'],[sender,'/chat'],[sender,'/chat?conversation='+fixture.conversation],
        [admin,'/company'],[admin,'/admin/employees'],[admin,'/admin/privacy'],[admin,'/admin/audit-logs'],[admin,'/account/authenticator'],
        [platform,'/platform/users'],[platform,'/platform/companies'],[platform,'/platform/companies?create=1'],[platform,'/platform/employees'],[platform,'/platform/activity'],
    ];
    for (const [session,path] of pages) {
        console.log(`Checking layout: ${path.split('?')[0]}`);
        for (const width of [375,768,1366]) {
            await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width===375},session);
            await navigate(session,path);
            const metrics=await evaluate(session,"({width:innerWidth,scrollWidth:document.documentElement.scrollWidth,overflow:document.documentElement.scrollWidth>innerWidth+1,title:document.title,buttons:document.querySelectorAll('button').length})");
            reports.layouts.push({path,...metrics});
            assert.equal(metrics.overflow,false,`Page overflow: ${path} at ${width}`);
            assert.match(metrics.title,/Pharma Strategies/);
            if(width===375 && await evaluate(session,"!!document.getElementById('navigation-toggle')")) {
                await evaluate(session,"document.getElementById('navigation-toggle').click();true");
                assert.equal(await evaluate(session,"document.getElementById('navigation-toggle').getAttribute('aria-expanded')"),'true');
                await evaluate(session,"document.getElementById('navigation-toggle').click();true");
            }
            if(path==='/admin/employees') {
                await evaluate(session,"document.querySelector('[data-employee-editor]').click();true");
                assert.equal(await evaluate(session,"!!document.querySelector('.nc-employee-dialog[open]')"),true);
                const fits=await evaluate(session,"(()=>{const r=document.querySelector('.nc-employee-dialog[open]').getBoundingClientRect();return r.left>=-1&&r.right<=innerWidth+1&&r.height<=innerHeight+1;})()");
                assert.equal(fits,true,'Employee dialog must fit viewport');
                await evaluate(session,"document.querySelector('.nc-employee-dialog[open]').close();true");
            }
            if(width===375 && ['/admin/employees','/platform/users','/chat?conversation='+fixture.conversation].includes(path)) {
                const screenshot=await send('Page.captureScreenshot',{format:'png'},session);
                await writeFile(resolve(directory,`workspace-${path.startsWith('/admin')?'employees':path.startsWith('/platform')?'people':'chat'}.png`),Buffer.from(screenshot.data,'base64'));
            }
        }
        if (path === '/chat?conversation='+fixture.conversation) await navigate(sender, '/profile');
    }
    assert.deepEqual(errors,[],'Unhandled browser exceptions');
    await navigate(sender,'/chat');
    await evaluate(sender,"document.querySelector('form[action$=\"/logout\"] button[type=submit]').click();true");
    await until(sender,"!!document.querySelector('.swal2-confirm')");
    await evaluate(sender,"document.querySelector('.swal2-confirm').click();true");
    await until(sender,"location.pathname==='/login' && !!document.querySelector('[name=password]')");
    await evaluate(sender,"history.back();true");
    await sleep(1500);
    await until(sender,"location.pathname==='/login' && !document.querySelector('#messages')");
    reports.interactions.push('Logout and browser Back do not restore the private chat');
    if(pages.length) reports.interactions.push('Mobile navigation and employee dialog open/close at phone, tablet and desktop widths');
    const sorted=[...reports.deliveries_ms].sort((a,b)=>a-b);
    reports.delivery_median_ms=sorted[2];reports.delivery_max_ms=sorted.at(-1);
    reports.browser_exceptions=errors;
    reports.http_execution = (await readFile(httpLog,'utf8').catch(()=>'')) .trim().split('\n').filter(Boolean).map(line=>JSON.parse(line));
    await writeFile(resolve(directory,mysql?'browser-workspace-mysql.json':'browser-workspace.json'),JSON.stringify(reports,null,2));
    console.log(JSON.stringify({layouts_checked:reports.layouts.length,delivery_samples_ms:reports.deliveries_ms,median_ms:reports.delivery_median_ms,max_ms:reports.delivery_max_ms,interactions:reports.interactions,browser_exceptions:errors}));
    await send('Browser.close');
} finally {
    try { if(closeBrowser && ws?.readyState===WebSocket.OPEN) await closeBrowser(); } catch {}
    ws?.close();
    for(const child of processes.reverse()) {
        if(child.exitCode===null){const exited=new Promise(ok=>child.once('exit',ok));child.kill();await Promise.race([exited,sleep(3000)]);}
    }
    if(mysql && fixtureCreated) await execute(php,['-d','opcache.enable_cli=1','tests/Performance/queue-fixture.php','drop-browser'],{cwd:root,env,timeout:30000});
    await unlink(database).catch(()=>{});
}
