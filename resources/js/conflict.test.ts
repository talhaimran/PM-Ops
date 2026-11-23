import assert from 'node:assert/strict';
import test from 'node:test';
import { ago, diffWords, fieldLabel, show } from './conflict.ts';

test('diffWords keeps the shared start and end and returns each side\'s middle', () => {
    assert.deepEqual(diffWords('Retry five times before failing', 'Retry three times before failing'), { head: 'Retry ', mine: 'five', theirs: 'three', tail: ' times before failing' });
    assert.deepEqual(diffWords('Retry three times', 'Retry three times with backoff'), { head: 'Retry three times', mine: '', theirs: ' with backoff', tail: '' });
    assert.deepEqual(diffWords('same', 'same'), { head: 'same', mine: '', theirs: '', tail: '' });
    assert.deepEqual(diffWords('', 'new text'), { head: '', mine: '', theirs: 'new text', tail: '' });
});

test('values and field names read as text', () => {
    assert.equal(show(null), 'None');
    assert.equal(show(['bug', 'ui']), 'bug, ui');
    assert.equal(show([]), 'None');
    assert.equal(show(3), '3');
    assert.equal(fieldLabel('story_points'), 'Story points');
});

test('ago says how long since, in the largest whole unit', () => {
    const now = Date.parse('2026-11-23T12:00:00Z');
    assert.equal(ago('2026-11-23T11:59:30Z', now), '30 seconds ago');
    assert.equal(ago('2026-11-23T11:58:00Z', now), '2 minutes ago');
    assert.equal(ago('2026-11-23T09:00:00Z', now), '3 hours ago');
    assert.equal(ago('2026-11-20T12:00:00Z', now), '3 days ago');
    // A clock slightly ahead never shows a time in the future.
    assert.equal(ago('2026-11-23T12:00:05Z', now), '0 seconds ago');
});
