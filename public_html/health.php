<?php
// Diagnostics are disabled in production to avoid disclosing server details.
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
