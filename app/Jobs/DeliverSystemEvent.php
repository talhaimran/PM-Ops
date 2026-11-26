<?php

namespace App\Jobs;

use App\Models\SystemEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 3-R22: POSTs one event, signed "sha256=<HMAC of {timestamp}.{body}>". Carries only the id and re-reads the row, so every
 * retry sends the same id and body. A non-2xx (a redirect included: it isn't followed) or a connection failure retries.
 */
class DeliverSystemEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $eventId) {}

    /** 1, 5, 15, 60 minutes, then hourly (the last value repeats). */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(24);
    }

    public function handle(): void
    {
        $event = SystemEvent::find($this->eventId);
        $url = config('pm.system_events_url');
        $secret = config('pm.system_events_secret');
        if (! $event || $event->delivered_at || ! $url || ! $secret) {
            return;
        }

        $body = json_encode($event->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) now()->timestamp;
        $event->increment('attempts');

        try {
            $response = Http::timeout(10)->withOptions(['allow_redirects' => false])->withBody($body, 'application/json')->withHeaders([
                'X-Pm-Event-Id' => $event->id,
                'X-Pm-Timestamp' => $timestamp,
                'X-Pm-Signature' => 'sha256='.hash_hmac('sha256', "{$timestamp}.{$body}", $secret),
            ])->post($url);
        } catch (ConnectionException $e) {
            // The message can quote the request, so it is scrubbed before it is stored or rethrown.
            $message = Str::limit(str_replace($secret, '[secret]', $e->getMessage()), 400);
            $event->update(['last_status' => null, 'last_error' => $message]);

            throw new RuntimeException($message);
        }

        if ($response->successful()) {
            $event->update(['delivered_at' => now(), 'last_status' => $response->status(), 'last_error' => null]);

            return;
        }
        $event->update(['last_status' => $response->status(), 'last_error' => "HTTP {$response->status()}"]);

        throw new RuntimeException("HTTP {$response->status()}");
    }
}
