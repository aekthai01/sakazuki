<?php

const CSRF_TOKEN_TTL = 3600;

/**
 * CSRF Protection Module
 * Prevents Cross-Site Request Forgery attacks
 */

/**
 * Generate a CSRF token and store it in the session
 * @return string The generated token
 */
function generateCsrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    
    // Regenerate token if older than the configured TTL
    if (time() - (int) $_SESSION['csrf_token_time'] > CSRF_TOKEN_TTL) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
    }
    
    return $_SESSION['csrf_token'];
}

/**
 * Validate a CSRF token
 * @param string|null $token The token to validate
 * @return bool True if valid
 */
function validateCsrfToken($token): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($token) || !is_string($token) || empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time'])) {
        return false;
    }

    // Reject malformed or expired tokens. The token is a 64-character hex string.
    if (strlen($token) !== 64 || !ctype_xdigit($token)) {
        return false;
    }

    if ((time() - (int) $_SESSION['csrf_token_time']) > CSRF_TOKEN_TTL) {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
        return false;
    }
    
    // Timing-safe comparison
    return hash_equals((string) $_SESSION['csrf_token'], $token);
}

/**
 * Output a hidden input field with the CSRF token
 * @return string HTML hidden input
 */
function csrfField(): string
{
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Get the CSRF token value (for AJAX requests)
 * @return string The token value
 */
function getCsrfToken(): string
{
    return generateCsrfToken();
}

/**
 * Validate CSRF token from POST request, die on failure
 * @param string $redirectUrl URL to redirect to on failure (optional)
 */
function requireCsrfToken(string $redirectUrl = ''): void
{
    // Only validate if it's a POST request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

    // Reject parameter pollution such as csrf_token[]=... without throwing a TypeError.
    if (!is_string($token) || !validateCsrfToken($token)) {
        http_response_code(403);
        if ($redirectUrl) {
            header('Location: ' . $redirectUrl);
            exit();
        }
        if (function_exists('isAjaxRequest') && isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']);
            exit();
        }
        die('Security validation failed. Please go back and try again.');
    }
}
