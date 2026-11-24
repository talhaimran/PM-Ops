import assert from 'node:assert/strict';
import { test } from 'node:test';
import { firstError } from './statusErrors.ts';

test('picks the refusal from whichever field carries it', () => {
    assert.equal(firstError({ status: 'In Review is used by 3 issues.' }), 'In Review is used by 3 issues.');
    assert.equal(firstError({ into: 'Pick a different status.', color: 'x' }), 'Pick a different status.');
    assert.equal(firstError({}), undefined);
});
