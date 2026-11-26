<?php

use App\Jobs\DeliverSystemEvent;
use App\Models\SystemEvent;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// 3-R22 (Review Focus 5): signed, retried with the same id, never following a redirect, never leaking the secret.

const DELIVERY_SECRET = 'delivery-secret-value';

beforeEach(function () {
    config(['pm.system_events_url' => 'https://platform.example.test/events', 'pm.system_events_secret' => DELIVERY_SECRET]);
    Queue::fake();
    $this->ws = makeWorkspace(User::factory()->create(), 'acme');
    $this->event = SystemEvent::where('type', 'workspace.created')->sole();
});

function deliver(SystemEvent $event): void
{
    (new DeliverSystemEvent($event->id))->handle();
}

test('the request carries the id, timestamp and a signature of "{timestamp}.{body}" that verifies with the secret', function () {
    $this->freezeSecond();
    Http::fake(['platform.example.test/*' => Http::response('', 204)]);

    deliver($this->event);

    Http::assertSent(function ($request) {
        $timestamp = $request->header('X-Pm-Timestamp')[0];

        return $request->url() === 'https://platform.example.test/events'
            && $request->header('X-Pm-Event-Id') === [$this->event->id]
            && $timestamp === (string) now()->timestamp
            && $request->header('X-Pm-Signature') === ['sha256='.hash_hmac('sha256', "{$timestamp}.{$request->body()}", DELIVERY_SECRET)]
            && json_decode($request->body(), true)['id'] === $this->event->id
            && $request->header('Content-Type')[0] === 'application/json';
    });
    expect($this->event->fresh())->delivered_at->not->toBeNull()->attempts->toBe(1)->last_status->toBe(204)->last_error->toBeNull();
});

test('a 500 fails the attempt so the queue retries, with the same event id and body', function () {
    Http::fake(['platform.example.test/*' => Http::response('boom', 500)]);

    expect(fn () => deliver($this->event))->toThrow(RuntimeException::class);
    expect(fn () => deliver($this->event))->toThrow(RuntimeException::class);

    $bodies = Http::recorded()->map(fn ($pair) => $pair[0]->body());
    expect($bodies)->toHaveCount(2)->and($bodies[0])->toBe($bodies[1]);
    Http::assertSent(fn ($request) => $request->header('X-Pm-Event-Id') === [$this->event->id]);
    expect($this->event->fresh())->delivered_at->toBeNull()->attempts->toBe(2)->last_status->toBe(500)->last_error->toBe('HTTP 500');
});

test('a 302 is not followed and counts as a failed attempt', function () {
    Http::fake([
        'platform.example.test/*' => Http::response('', 302, ['Location' => 'https://elsewhere.example.test/']),
        'elsewhere.example.test/*' => Http::response('', 200),
    ]);

    expect(fn () => deliver($this->event))->toThrow(RuntimeException::class);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'elsewhere'));
    expect($this->event->fresh())->delivered_at->toBeNull()->last_status->toBe(302);
});

test('retries back off 1, 5, 15, 60 minutes then hourly, for 24 hours, and a delivered event is not sent again', function () {
    $this->freezeSecond();
    $job = new DeliverSystemEvent($this->event->id);

    expect($job->backoff())->toBe([60, 300, 900, 3600])
        ->and($job->retryUntil()->equalTo(now()->addHours(24)))->toBeTrue();

    Http::fake(['platform.example.test/*' => Http::response('', 200)]);
    deliver($this->event);
    deliver($this->event);
    Http::assertSentCount(1);
});

test('the secret never reaches last_error or the failure message', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error: could not connect using '.DELIVERY_SECRET));

    try {
        deliver($this->event);
    } catch (Throwable $e) {
        expect($e->getMessage())->not->toContain(DELIVERY_SECRET);
    }

    expect($this->event->fresh()->last_error)->not->toContain(DELIVERY_SECRET)->not->toBeNull()
        ->and(json_encode($this->event->fresh()->toArray()))->not->toContain(DELIVERY_SECRET);
});
