<?php
if (!headers_sent()) {
    http_response_code(404);
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=utf-8');
}

echo 'Not Found';
exit();
