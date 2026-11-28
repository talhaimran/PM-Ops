import assert from 'node:assert/strict';
import test from 'node:test';
import { describeChange, runActions, shortArgs } from './agentRun.ts';

const run = (status: string, changes: string[]) => ({ status, changes: changes.map((s) => ({ status: s })) }) as never;

test('buttons follow the run and its changes', () => {
    assert.deepEqual(runActions(run('running', [])), { cancel: true, apply: 0, discard: 0, propose: false });
    assert.deepEqual(runActions(run('finished', ['proposed', 'conflict', 'applied'])), { cancel: false, apply: 1, discard: 2, propose: true });
    assert.deepEqual(runActions(run('finished', ['applied', 'discarded'])), { cancel: false, apply: 0, discard: 0, propose: false });
});

test('changes are described by kind', () => {
    const c = (kind: string, target: string | null, before: unknown, after: unknown) => ({ kind, target, before, after }) as never;
    assert.deepEqual(describeChange(c('field', 'priority', { value: 'medium' }, { value: 'high' })), { field: 'priority', before: 'medium', after: 'high' });
    assert.deepEqual(describeChange(c('field', 'labels', { value: [] }, { value: ['a', 'b'] })), { field: 'labels', before: 'none', after: 'a, b' });
    assert.equal(describeChange(c('child_issue', null, null, { title: 'Test', story_points: 2 })).after, 'Test, 2 points');
    assert.equal(describeChange(c('link', 'WEB-2', null, { type: 'relates to', issue: 'WEB-2' })).after, 'relates to WEB-2');
    assert.equal(describeChange(c('comment', null, null, { body: '<b>x</b>' })).after, '<b>x</b>');
});

test('long arguments are cut', () => {
    assert.equal(shortArgs({ a: 'x'.repeat(100) }).length, 81);
    assert.equal(shortArgs(undefined), '');
});
