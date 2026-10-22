import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/chat-runtime.js', import.meta.url), 'utf8');
const attachmentRenderer = source.split('\n').find(line => line.includes('const renderAttachments ='));
const begin = source.indexOf('    const buildMessageRow = m => {');
const end = source.indexOf('    const updateRowBody=', begin);
assert.ok(begin >= 0 && end > begin, 'Chat renderer must be present');

class Element {
    constructor(tag) { this.tag = tag; this.children = []; this.dataset = {}; this.className = ''; this.textContent = ''; this.innerHTML = ''; }
    appendChild(node) { node.parent = this; this.children.push(node); return node; }
    append(...nodes) { nodes.forEach(node => this.appendChild(typeof node === 'string' ? Object.assign(new Element('text'), {textContent:node}) : node)); }
    querySelector(selector) {
        const matches = node => node.className.split(/\s+/).includes(selector.slice(1));
        for (const child of this.children) { if (matches(child)) return child; const nested = child.querySelector(selector); if (nested) return nested; }
        return null;
    }
    remove() { if (this.parent) this.parent.children = this.parent.children.filter(node => node !== this); }
}

function render(message, canWrite = true) {
    const context = {
        document: {createElement:tag => new Element(tag)},
        currentUserId:1, canWrite, editWindowMinutes:10, deleteWindowMinutes:5,
        formatTime:()=>'now', icon:()=>'<svg></svg>',
        attachmentMarkup:file => Object.assign(new Element('a'), {className:'attachment',textContent:file.name}),
        buildActionMenu:()=>Object.assign(new Element('button'), {className:'nc-message-more'}),
        message, result:null,
    };
    vm.runInNewContext(attachmentRenderer + '\n' + source.slice(begin,end) + '\nresult = buildMessageRow(message);', context);
    return context.result;
}
const message = () => ({id:7,user_id:1,user_name:'Employee',body:'Hello',created_at:new Date().toISOString()});

test('dynamically received files are attached to the message bubble', () => {
    const row = render({...message(),attachments:[{name:'private-policy.pdf'}]});
    const files = row.querySelector('.nc-attachments');
    assert.ok(files);
    assert.equal(files.parent.className,'nc-bubble');
    assert.equal(files.children[0].textContent,'private-policy.pdf');
});
test('forwarded display names remain text', () => {
    const name = '<img src=x onerror="alert(1)">';
    const label = render({...message(),forwarded_from:{user_name:name}}).querySelector('.nc-forwarded-label');
    assert.equal(label.textContent,'Forwarded from '+name);
    assert.equal(label.innerHTML,'');
});
test('read-only conversations hide all message actions', () => {
    const row = render(message(),false);
    assert.equal(row.querySelector('.nc-message-more'),null);
    assert.equal(row.dataset.editable,'0');
    assert.equal(row.dataset.deletable,'0');
});
test('unsent messages do not render attachments', () => {
    const row = render({...message(),deleted_at:new Date().toISOString(),attachments:[{name:'removed.pdf'}]});
    assert.equal(row.querySelector('.nc-attachments'),null);
    assert.equal(row.querySelector('.nc-message-more'),null);
});
