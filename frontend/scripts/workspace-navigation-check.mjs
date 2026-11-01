import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const fixture = `<html data-button-scope="tenant" data-theme="light" data-primary-theme="teal"><div id="root"></div><script type="module">
import React from'react';import{createRoot}from'react-dom/client';import{BrowserRouter,Routes,Route,Outlet}from'react-router-dom';import{QueryClient,QueryClientProvider,useQuery}from'@tanstack/react-query';import{WorkspaceProvider,useWorkspace}from'/src/features/workspace/WorkspaceProvider.tsx';import{WorkspaceTabBar}from'/src/components/desktop/WorkspaceTabBar.tsx';import{PosDataGrid}from'/src/components/desktop/PosDataGrid.tsx';import'/src/appearance.css';import'/src/motion.css';const e=React.createElement;window.cachedRows=Array.from({length:10000},(_,i)=>({ulid:String(i),name:'Cached '+i}));
function Slow(){const q=useQuery({queryKey:['delayed-list'],queryFn:()=>new Promise(resolve=>window.finishData=resolve)});return e('section',{id:'slow-module'},e('h1',null,'Product View controls'),e('input',{id:'search',placeholder:'Search products'}),e('div',{id:'slow-table',style:{height:300}},e(PosDataGrid,{columns:[{key:'name',header:'Name',render:r=>r.name}],rows:q.data??[],rowKey:r=>r.ulid,loading:q.isLoading,virtualize:true})))}
function Cached(){return e('section',{id:'cached-module'},e('h1',null,'Products controls'),e('div',{id:'cached-table',style:{height:300}},e(PosDataGrid,{columns:[{key:'name',header:'Name',render:r=>r.name}],rows:window.cachedRows,rowKey:r=>r.ulid,virtualize:true})))}
function Frame(){const w=useWorkspace();return e('main',null,e('button',{id:'open-slow',onClick:()=>w.openModule('/definition/product-view')},'Open Product View'),e('button',{id:'open-cached',onClick:()=>w.openModule('/definition/products')},'Open Products'),e(WorkspaceTabBar),e(Outlet))}
createRoot(document.getElementById('root')).render(e(QueryClientProvider,{client:new QueryClient({defaultOptions:{queries:{retry:false,staleTime:Infinity}}})},e(BrowserRouter,null,e(WorkspaceProvider,null,e(Routes,null,e(Route,{element:e(Frame)},e(Route,{path:'/navigation-check.html',element:e('p',null,'Home')}),e(Route,{path:'/definition/product-view',element:e(Slow)}),e(Route,{path:'/definition/products',element:e(Cached)})))))));
</script><style>.pos-grid-wrap{height:300px;overflow:auto}.pos-grid{width:100%}.pos-grid td{height:22px}</style></html>
`;
import { spawn } from 'node:child_process';
const root = path.resolve(fileURLToPath(new URL('../..', import.meta.url)));
const fixturePath = root + '/frontend/navigation-check.html';
const browserPath = process.env.BLUEPOS_TEST_BROWSER ?? ['C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/chromium', '/usr/bin/google-chrome'].find(p => fs.existsSync(p));
if (!browserPath || !fs.existsSync(browserPath))
    throw Error('Set BLUEPOS_TEST_BROWSER to a Chromium browser executable.');
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'bluepos-navigation-check-'));
fs.writeFileSync(fixturePath, fixture);
const sleep = ms => new Promise(r => setTimeout(r, ms)), vite = spawn(process.execPath, ['node_modules/vite/bin/vite.js', '--host', '127.0.0.1', '--port', '5194', '--strictPort'], { cwd: root + '/frontend', windowsHide: true, stdio: ['pipe', 'ignore', 'ignore'] }), browser = spawn(browserPath, ['--headless=new', '--disable-gpu', '--no-sandbox', '--remote-debugging-port=9344', '--user-data-dir=' + profile, '--no-first-run'], { windowsHide: true, stdio: 'ignore' });
let ws;
try {
    for (let i = 0; i < 60; i++) {
        try {
            await fetch('http://127.0.0.1:5194/navigation-check.html');
            break;
        }
        catch {
            await sleep(150);
        }
    }
    let target;
    for (let i = 0; i < 50; i++) {
        try {
            target = await (await fetch('http://localhost:9344/json/new?http://127.0.0.1:5194/navigation-check.html', { method: 'PUT' })).json();
            break;
        }
        catch {
            await sleep(150);
        }
    }
    ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise(r => ws.onopen = r);
    let seq = 0;
    const pending = new Map();
    ws.onmessage = e => { const m = JSON.parse(e.data); if (m.id) {
        pending.get(m.id)?.(m);
        pending.delete(m.id);
    } };
    const send = (method, params = {}) => new Promise((r, j) => { const id = ++seq, t = setTimeout(() => j(Error(method)), 15000); pending.set(id, m => { clearTimeout(t); r(m); }); ws.send(JSON.stringify({ id, method, params })); });
    const run = async (expression) => { const m = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true }); if (m.result.exceptionDetails)
        throw Error(JSON.stringify(m.result.exceptionDetails)); return m.result.result.value; };
    await send('Runtime.runIfWaitingForDebugger');
    for (let i = 0; i < 150; i++) {
        if (await run("!!document.getElementById('open-slow')"))
            break;
        await sleep(100);
    }
    const check = (ok, msg) => { if (!ok)
        throw Error(msg); console.log('PASS ' + msg); };
    await run("document.getElementById('open-slow').click()");
    await sleep(100);
    check(await run("!!document.getElementById('slow-module') && !!window.finishData"), 'new module opens while its data promise is unresolved');
    check(await run("document.querySelector('[role=tab][aria-selected=true]').textContent.includes('Product View')"), 'new tab is registered and selected before data');
    check(await run("!!document.getElementById('search') && !!document.querySelector('#slow-table .ui-skeleton') && document.querySelectorAll('.ui-skeleton').length===1"), 'controls visible; loader only inside list');
    await run("document.getElementById('open-cached').click()");
    await sleep(150);
    check(await run("!!document.getElementById('cached-module') && document.querySelector('[role=tab][aria-selected=true]').textContent.includes('Products')"), 'can switch tabs while the other request is still pending');
    check(await run("document.querySelectorAll('#cached-table tbody tr').length<100"), '10,000 cached rows use bounded DOM rendering');
    await run("window.finishData([{ulid:'server',name:'Fetched product'}])");
    await sleep(80);
    await run("[...document.querySelectorAll('[role=tab]')].find(b=>b.textContent.includes('Product View')).click()");
    await sleep(150);
    check(await run("document.querySelector('#slow-table').textContent.includes('Fetched product') && !document.querySelector('#slow-table .ui-skeleton')"), 'data replaces only the local skeleton');
    check(await run("getComputedStyle(document.querySelector('#slow-table tbody')).animationName==='pos-grid-data-reveal'"), 'loaded list has smooth reveal');
    await run("document.getElementById('open-cached').click()");
    await sleep(120);
    await run("document.querySelector('#cached-table .pos-grid-wrap').scrollTop=100000;document.querySelector('#cached-table .pos-grid-wrap').dispatchEvent(new Event('scroll',{bubbles:true}))");
    await sleep(80);
    check(await run("document.querySelector('#cached-table tbody').textContent.includes('Cached 4') && document.querySelectorAll('#cached-table tbody tr').length<100"), 'large cached list remains scrollable with bounded rows');
}
finally {
    ws?.close();
    browser.kill();
    vite.kill();
    fs.rmSync(fixturePath, { force: true });
    if (path.dirname(profile) === path.resolve(os.tmpdir()) && path.basename(profile).startsWith('bluepos-navigation-check-')) {
        try {
            fs.rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 100 });
        }
        catch { /* Browser child processes may release profile files after exit. */ }
    }
}
