import test from 'node:test';
import assert from 'node:assert/strict';
import {getPushSubscription} from '../../resources/js/push-subscription.js';

test('a current device subscription is reused without prompting or unsubscribing', async () => {
    const key = new Uint8Array([1,2,3]);
    const current = {options:{applicationServerKey:key.buffer},expirationTime:null,unsubscribe:()=>assert.fail('Unexpected unsubscribe')};
    const manager = {getSubscription:async()=>current,subscribe:()=>assert.fail('Unexpected subscribe')};
    assert.equal(await getPushSubscription(manager,key),current);
});

test('an old VAPID subscription is renewed and its server record is cleaned up', async () => {
    const steps=[];
    const previous={options:{applicationServerKey:new Uint8Array([9]).buffer},unsubscribe:async()=>{steps.push('unsubscribe');return true;}};
    const manager={getSubscription:async()=>previous,subscribe:async options=>{steps.push('subscribe');assert.equal(options.userVisibleOnly,true);return {fresh:true};}};
    assert.deepEqual(await getPushSubscription(manager,new Uint8Array([1]),{replaced:async value=>{assert.equal(value,previous);steps.push('cleanup');}}),{fresh:true});
    assert.deepEqual(steps,['unsubscribe','cleanup','subscribe']);
});

test('expired subscriptions renew even when server cleanup temporarily fails', async () => {
    const previous={expirationTime:10,unsubscribe:async()=>true};
    const manager={getSubscription:async()=>previous,subscribe:async()=>({fresh:true})};
    assert.deepEqual(await getPushSubscription(manager,new Uint8Array([1]),{now:11,replaced:async()=>{throw new Error('Offline');}}),{fresh:true});
});

test('failed browser unsubscription does not report a new connection', async () => {
    const manager={getSubscription:async()=>({expirationTime:0,unsubscribe:async()=>false}),subscribe:()=>assert.fail('Unexpected subscribe')};
    await assert.rejects(getPushSubscription(manager,new Uint8Array([1])),/Could not refresh notifications/);
});

test('explicit repair renews a provider-invalid subscription even when its key and expiry look current', async () => {
    const key=new Uint8Array([1]);
    let unsubscribed=false;
    const manager={getSubscription:async()=>({options:{applicationServerKey:key.buffer},expirationTime:null,unsubscribe:async()=>{unsubscribed=true;return true;}}),subscribe:async()=>({fresh:true})};
    assert.deepEqual(await getPushSubscription(manager,key,{renew:true}),{fresh:true});
    assert.equal(unsubscribed,true);
});
