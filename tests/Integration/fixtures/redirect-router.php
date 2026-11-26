<?php

// php -S router for SystemEventRedirectTest: /events redirects to /landed, which records that it was reached.
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/events') {
    header('Location: /landed', true, 302);

    return;
}
file_put_contents(getenv('LANDED_FILE'), 'reached');
http_response_code(200);
