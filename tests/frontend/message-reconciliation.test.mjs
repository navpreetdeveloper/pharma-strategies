import test from 'node:test';
import assert from 'node:assert/strict';
import {reconcileMessageRows} from '../../resources/js/message-reconciliation.js';

function fixture({deleted=false, editing=false, editedAt='', revision='current'}={}) {
    const seen=new Set(['7']);
    let removed=false, replaced=0;
    const row={dataset:{revision,editedAt},classList:{contains:()=>deleted},querySelector:()=>editing?{}:null,remove:()=>{removed=true;},replaceWith:()=>{replaced++;}};
    const options={box:{querySelector:()=>row},seen,messages:[],removed:[],buildRow:message=>message,schedule:()=>{}};
    return {options,seen,result:()=>({removed,replaced})};
}

test('an unchanged revision preserves playing media and DOM focus',()=>{
    const f=fixture(); f.options.messages=[{id:7,revision:'current'}];
    reconcileMessageRows(f.options); assert.equal(f.result().replaced,0);
});

test('older responses cannot restore an unsent body or overwrite a newer edit',()=>{
    const unsent=fixture({deleted:true});unsent.options.messages=[{id:7,body:'Old content',revision:'old'}];
    reconcileMessageRows(unsent.options);assert.equal(unsent.result().replaced,0);
    const edited=fixture({editedAt:'2026-10-19T12:01:00Z'});edited.options.messages=[{id:7,body:'Old content',edited_at:'2026-10-19T12:00:00Z'}];
    reconcileMessageRows(edited.options);assert.equal(edited.result().replaced,0);
});

test('active edits survive ordinary polling but an authoritative unsend removes the form',()=>{
    const f=fixture({editing:true});f.options.messages=[{id:7,body:'Updated',revision:'new'}];
    reconcileMessageRows(f.options);assert.equal(f.result().replaced,0);
    f.options.messages=[{id:7,body:null,deleted_at:'2026-10-19T12:00:00Z',revision:'unsent'}];
    reconcileMessageRows(f.options);assert.equal(f.result().replaced,1);
});

test('pruned rows disappear and remain deduplicated against late live responses',()=>{
    const f=fixture();f.options.removed=[7];reconcileMessageRows(f.options);
    assert.equal(f.result().removed,true);assert.equal(f.seen.has('7'),true);
});
