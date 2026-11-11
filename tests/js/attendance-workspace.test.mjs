import assert from 'node:assert/strict';
import test from 'node:test';
import { AttendanceScanQueue } from '../../resources/js/attendance-workspace.js';

const activity = { id: 1, title: 'Selected activity' };
const job = (value) => ({ activity, value, kind: 'uid' });

test('submissions are processed in order with no overlapping requests', async () => {
    const calls = [];
    const results = [];
    let active = 0;
    let maximum = 0;
    const queue = new AttendanceScanQueue(async (entry) => {
        maximum = Math.max(maximum, ++active);
        calls.push(entry.value);
        await new Promise((resolve) => setTimeout(resolve, entry.value === 'first' ? 20 : 1));
        results.push(entry.value);
        active--;
    });
    for (const value of ['first', 'second', 'third']) queue.enqueue(job(value));
    await queue.waitForIdle();
    assert.deepEqual(calls, ['first', 'second', 'third']);
    assert.deepEqual(results, calls);
    assert.equal(maximum, 1);
    assert.equal(queue.items.length, 0);
});

test('uncertain outcomes retain the current submission and the remaining queue for retry', async () => {
    const calls = [];
    let fail = true;
    const queue = new AttendanceScanQueue(async (entry) => {
        calls.push(entry.value);
        if (fail) return { retry: true };
    });
    queue.enqueue(job('first'));
    queue.enqueue(job('second'));
    await queue.waitForIdle();
    assert.equal(queue.paused, true);
    assert.deepEqual(queue.items.map((entry) => entry.value), ['first', 'second']);
    assert.deepEqual(calls, ['first']);
    fail = false;
    queue.resume();
    await queue.waitForIdle();
    assert.deepEqual(calls, ['first', 'first', 'second']);
    assert.equal(queue.items.length, 0);
});

test('exceptions pause without losing submitted UIDs', async () => {
    const queue = new AttendanceScanQueue(async () => { throw new Error('Connection lost'); });
    queue.enqueue(job('first'));
    queue.enqueue(job('second'));
    await queue.waitForIdle();
    assert.equal(queue.paused, true);
    assert.equal(queue.items.length, 2);
    assert.equal(queue.running, false);
});

test('queued submissions keep an immutable snapshot of their selected activity', async () => {
    const context = { id: 7, title: 'Original activity' };
    let received;
    const queue = new AttendanceScanQueue(async (entry) => {
        await new Promise((resolve) => setTimeout(resolve, 1));
        received = entry;
    });
    queue.enqueue({ activity: context, value: 'ABCDEF12' });
    context.id = 8;
    context.title = 'Different activity';
    await queue.waitForIdle();
    assert.equal(received.activity.id, 7);
    assert.equal(received.activity.title, 'Original activity');
    assert.equal(Object.isFrozen(received), true);
    assert.equal(Object.isFrozen(received.activity), true);
});

test('a rejected value can finish while the following submission continues', async () => {
    const calls = [];
    const queue = new AttendanceScanQueue(async (entry) => {
        calls.push(entry.value);
        // A definitive validation rejection has no retry flag.
        return entry.value === 'invalid' ? { validationError: true } : undefined;
    });
    queue.enqueue(job('invalid'));
    queue.enqueue(job('valid'));
    await queue.waitForIdle();
    assert.deepEqual(calls, ['invalid', 'valid']);
    assert.equal(queue.paused, false);
    assert.equal(queue.items.length, 0);
});
