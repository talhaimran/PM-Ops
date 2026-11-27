<?php

namespace App\Services\Ai;

use RuntimeException;

/** A failed provider call. The message is safe to show and log: never the response body, headers, endpoint path or key. */
class AiProviderError extends RuntimeException {}
