import assert from 'node:assert/strict';
import test from 'node:test';
import { dropAnnouncement } from './board.ts';

test('a drop is announced as a move only once the server took it', () => {
    assert.equal(dropAnnouncement('WEB-20', 'In Progress', 2, 10, 'moved'), 'WEB-20 moved to In Progress, position 3 of 10');
    assert.equal(dropAnnouncement('WEB-20', 'In Progress', 2, 10, 'confirm'), 'WEB-20 not moved yet: confirm the move to In Progress.');
    assert.equal(dropAnnouncement('WEB-20', 'In Progress', 2, 10, 'kept'), 'WEB-20 not moved.');
});
