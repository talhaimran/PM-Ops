<?php

use App\Jobs\DeliverSystemEvent;
use App\Models\SystemEvent;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

// PR 49 review: against a real HTTP server, a 302 from the events URL is not followed and the attempt fails.

test('delivery does not follow a real 302', function () {
    $port = random_int(20000, 40000);
    $landed = tempnam(sys_get_temp_dir(), 'landed');
    unlink($landed);
    $server = Process::env(['LANDED_FILE' => $landed])->start([PHP_BINARY, '-S', "127.0.0.1:{$port}", base_path('tests/Integration/fixtures/redirect-router.php')]);
    try {
        retry(50, fn () => fsockopen('127.0.0.1', $port) ?: throw new RuntimeException('not up'), 100);
        config(['pm.system_events_url' => "http://127.0.0.1:{$port}/events", 'pm.system_events_secret' => 'redirect-secret']);
        Queue::fake();
        makeWorkspace(User::factory()->create(), 'acme');
        $event = SystemEvent::where('type', 'workspace.created')->sole();

        expect(fn () => (new DeliverSystemEvent($event->id))->handle())->toThrow(RuntimeException::class, 'HTTP 302');

        expect(file_exists($landed))->toBeFalse()
            ->and($event->fresh())->delivered_at->toBeNull()->last_status->toBe(302);
    } finally {
        $server->stop();
        @unlink($landed);
    }
});
