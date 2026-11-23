import assert from 'node:assert/strict';
import test from 'node:test';
import { advanceBase, ago, diffWords, fieldLabel, show } from './conflict.ts';

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

test('an open editor follows the page\'s own saves, never someone else\'s', () => {
    // Opened at 3; the page saved priority at 3, which made 4: still nobody else's change, so the editor reads 4.
    assert.equal(advanceBase(3, 3, 4), 4);
    // Opened at 3, but the page's save went out at 5: 4 and 5 came from someone else, so the editor keeps 3 and conflicts.
    assert.equal(advanceBase(3, 5, 6), 3);
    // A save that changed nothing, or whose answer has no issue, leaves it.
    assert.equal(advanceBase(3, 3, 3), 3);
    assert.equal(advanceBase(3, 3, undefined), 3);
});
