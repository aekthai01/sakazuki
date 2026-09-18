<?php
/**
 * Cross-site security registry shared by SAK-010 and ONL-005.
 *
 * The hub stores only the data needed to enforce manual blocks and keep
 * blocked email/device/IP signals visible across the two websites. No behavioural scoring
 * or automated risk decisions are performed here.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';

function sharedSecurityEnsureBridgeLoaded(): bool
{
    if (function_exists('sharedLedgerRemoteConnection')
        && function_exists('sharedLedgerCurrentSiteId')
        && function_exists('supplierBridgeApiRequest')) return true;
    $path = __DIR__ . '/store_bridge.php';
    if (!is_file($path)) return false;
    require_once $path;
    return function_exists('sharedLedgerRemoteConnection')
        && function_exists('sharedLedgerCurrentSiteId')
        && function_exists('supplierBridgeApiRequest');
}

function sharedSecurityCurrentSiteId(): string
{
    if (!sharedSecurityEnsureBridgeLoaded()) return '';
    $siteId = strtoupper(trim((string) sharedLedgerCurrentSiteId()));
    return preg_match('/^[A-Z0-9][A-Z0-9_-]{0,63}$/D', $siteId) === 1 ? $siteId : '';
}

function sharedSecurityStableSecret(): string
{
    if (!sharedSecurityEnsureBridgeLoaded() || !function_exists('sharedLedgerInternalSecret')) return '';
    $secret = (string) sharedLedgerInternalSecret();
    return strlen($secret) >= 16 ? $secret : '';
}

function sharedSecurityNormalizeEmail($value): string
{
    if (!is_scalar($value)) return '';
    $email = strtolower(trim((string) $value));
    if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return '';
    return $email;
}

function sharedSecurityEmailHash($email): string
{
    $email = sharedSecurityNormalizeEmail($email);
    $secret = sharedSecurityStableSecret();
    if ($email === '' || $secret === '') return '';
    return hash_hmac('sha256', "email\0" . $email, $secret);
}

function sharedSecurityNormalizeSiteId($value): string
{
    if (!is_scalar($value)) return '';
    $siteId = strtoupper(trim((string) $value));
    return preg_match('/^[A-Z0-9][A-Z0-9_-]{0,63}$/D', $siteId) === 1 ? $siteId : '';
}

function sharedSecurityNormalizeHash($value): string
{
    if (!is_scalar($value)) return '';
    $hash = strtolower(trim((string) $value));
    return preg_match('/^[a-f0-9]{64}$/D', $hash) === 1 ? $hash : '';
}

function sharedSecuritySubject(string $type, $value): ?array
{
    $type = strtolower(trim($type));
    if ($type === 'ip') {
        $ip = is_scalar($value) ? trim((string) $value) : '';
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return null;
        return ['type' => 'ip', 'hash' => hash('sha256', $ip), 'display' => $ip];
    }
    if ($type === 'email') {
        $email = sharedSecurityNormalizeEmail($value);
        $hash = sharedSecurityEmailHash($email);
        if ($email === '' || $hash === '') return null;
        return ['type' => 'email', 'hash' => $hash, 'display' => $email];
    }
    if ($type === 'device') {
        $hash = sharedSecurityNormalizeHash($value);
        if ($hash === '') return null;
        return ['type' => 'device', 'hash' => $hash, 'display' => substr($hash, 0, 12)];
    }
    return null;
}

function sharedSecuritySanitizeText($value, int $maxLength): string
{
    $text = is_scalar($value) ? trim((string) $value) : '';
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $text);
    if (!is_string($text)) $text = '';
    return function_exists('mb_substr')
        ? mb_substr($text, 0, $maxLength, 'UTF-8')
        : substr($text, 0, $maxLength);
}

function sharedSecurityTableReady(string $table, bool $refresh = false): bool
{
    global $conn;
    if (function_exists('sakazukiTableReady')) return (bool) sakazukiTableReady($table, $refresh);
    if (!isset($conn) || !($conn instanceof mysqli) || preg_match('/^[a-z0-9_]+$/iD', $table) !== 1) return false;
    static $cache = [];
    if (!$refresh && array_key_exists($table, $cache)) return $cache[$table];
    $stmt = $conn->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    if (!$stmt) return $cache[$table] = false;
    $stmt->bind_param('s', $table);
    if (!$stmt->execute()) { $stmt->close(); return $cache[$table] = false; }
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $cache[$table] = ((int) $count > 0);
}

function sharedSecurityTableColumnsReady(string $table, array $columns, bool $refresh = false): bool
{
    global $conn;
    if (!sharedSecurityTableReady($table, $refresh) || $columns === []) return $columns === [];
    $normalized = [];
    foreach ($columns as $column) {
        $column = is_scalar($column) ? trim((string) $column) : '';
        if (preg_match('/^[a-z0-9_]+$/iD', $column) !== 1) return false;
        $normalized[$column] = true;
    }
    $stmt = $conn->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    if (!$stmt->execute()) { $stmt->close(); return false; }
    $result = $stmt->get_result();
    $found = [];
    while ($row = $result ? $result->fetch_assoc() : null) {
        if (!$row) break;
        $found[(string) ($row['COLUMN_NAME'] ?? '')] = true;
    }
    $stmt->close();
    foreach (array_keys($normalized) as $column) if (!isset($found[$column])) return false;
    return true;
}

function sharedSecurityCentralSchemaReady(bool $refresh = false): bool
{
    return sharedSecurityTableReady('shared_security_blocks', $refresh)
        && sharedSecurityTableColumnsReady('shared_security_devices', ['browser_label'], $refresh);
}

/**
 * Execute one shared-security operation on the central hub database.
 * This function is also used by the HMAC-authenticated Store API endpoint.
 */
function sharedSecurityApiAction(string $action, array $input): array
{
    global $conn;
    $action = strtolower(trim($action));
    if (!sharedSecurityCentralSchemaReady()) {
        return ['success' => false, 'http_code' => 503, 'code' => 'security_registry_unavailable', 'message' => 'Shared security registry is unavailable'];
    }

    if ($action === 'shared_security_check') {
        $subjectsRaw = is_array($input['subjects'] ?? null) ? $input['subjects'] : [];
        $subjects = [];
        foreach ($subjectsRaw as $item) {
            if (!is_array($item)) continue;
            $type = strtolower(trim((string) ($item['type'] ?? '')));
            $hash = sharedSecurityNormalizeHash($item['hash'] ?? '');
            if (!in_array($type, ['ip', 'device', 'email'], true) || $hash === '') continue;
            $subjects[$type . ':' . $hash] = ['type' => $type, 'hash' => $hash];
            if (count($subjects) >= 8) break;
        }
        if ($subjects === []) return ['success' => true, 'http_code' => 200, 'blocked' => []];

        $stmt = $conn->prepare('SELECT subject_type,subject_hash,subject_display,reason,blocked_by_site_id,created_at FROM shared_security_blocks WHERE subject_type=? AND subject_hash=? LIMIT 1');
        if (!$stmt) return ['success' => false, 'http_code' => 503, 'code' => 'query_failed', 'message' => 'Shared security check could not be prepared'];
        $blocked = [];
        foreach ($subjects as $subject) {
            $stmt->bind_param('ss', $subject['type'], $subject['hash']);
            if (!$stmt->execute()) { $stmt->close(); return ['success' => false, 'http_code' => 503, 'code' => 'query_failed', 'message' => 'Shared security check failed']; }
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            if ($row) $blocked[] = $row;
        }
        $stmt->close();
        return ['success' => true, 'http_code' => 200, 'blocked' => $blocked];
    }

    if ($action === 'shared_security_block') {
        $type = strtolower(trim((string) ($input['subject_type'] ?? '')));
        $hash = sharedSecurityNormalizeHash($input['subject_hash'] ?? '');
        if (!in_array($type, ['ip', 'device', 'email'], true) || $hash === '') {
            return ['success' => false, 'http_code' => 422, 'code' => 'invalid_subject', 'message' => 'Invalid security subject'];
        }
        $display = sharedSecuritySanitizeText($input['subject_display'] ?? '', 190);
        $reason = sharedSecuritySanitizeText($input['reason'] ?? '', 255);
        $siteId = sharedSecurityNormalizeSiteId($input['site_id'] ?? '');
        $adminId = max(0, (int) ($input['admin_id'] ?? 0));
        if ($siteId === '') return ['success' => false, 'http_code' => 422, 'code' => 'invalid_site', 'message' => 'Invalid site identity'];

        $stmt = $conn->prepare(
            'INSERT INTO shared_security_blocks (subject_type,subject_hash,subject_display,reason,blocked_by_site_id,blocked_by_user_id,created_at,updated_at)
             VALUES (?,?,?,?,?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE subject_display=VALUES(subject_display),reason=VALUES(reason),blocked_by_site_id=VALUES(blocked_by_site_id),blocked_by_user_id=VALUES(blocked_by_user_id),updated_at=NOW()'
        );
        if (!$stmt) return ['success' => false, 'http_code' => 503, 'code' => 'query_failed', 'message' => 'Shared block could not be prepared'];
        $stmt->bind_param('sssssi', $type, $hash, $display, $reason, $siteId, $adminId);
        $ok = $stmt->execute();
        $stmt->close();
        return ['success' => $ok, 'http_code' => $ok ? 200 : 503, 'code' => $ok ? 'blocked' : 'query_failed'];
    }

    if ($action === 'shared_security_unblock') {
        $type = strtolower(trim((string) ($input['subject_type'] ?? '')));
        $hash = sharedSecurityNormalizeHash($input['subject_hash'] ?? '');
        if (!in_array($type, ['ip', 'device', 'email'], true) || $hash === '') {
            return ['success' => false, 'http_code' => 422, 'code' => 'invalid_subject', 'message' => 'Invalid security subject'];
        }
        $stmt = $conn->prepare('DELETE FROM shared_security_blocks WHERE subject_type=? AND subject_hash=?');
        if (!$stmt) return ['success' => false, 'http_code' => 503, 'code' => 'query_failed', 'message' => 'Shared unblock could not be prepared'];
        $stmt->bind_param('ss', $type, $hash);
        $ok = $stmt->execute();
        $stmt->close();
        return ['success' => $ok, 'http_code' => $ok ? 200 : 503, 'code' => $ok ? 'unblocked' : 'query_failed'];
    }

    if ($action === 'shared_security_touch_device') {
        $siteId = sharedSecurityNormalizeSiteId($input['site_id'] ?? '');
        $userId = max(0, (int) ($input['user_id'] ?? 0));
        $deviceHash = sharedSecurityNormalizeHash($input['device_hash'] ?? '');
        $username = sharedSecuritySanitizeText($input['username'] ?? '', 190);
        $role = sharedSecuritySanitizeText($input['role'] ?? '', 32);
        $label = sharedSecuritySanitizeText($input['device_label'] ?? '', 160);
        $browserLabel = sharedSecuritySanitizeText($input['browser_label'] ?? '', 96);
        $ip = is_scalar($input['last_ip'] ?? null) ? trim((string) $input['last_ip']) : '';
        if ($siteId === '' || $userId < 1 || $deviceHash === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['success' => false, 'http_code' => 422, 'code' => 'invalid_device', 'message' => 'Invalid device observation'];
        }
        $stmt = $conn->prepare(
            'INSERT INTO shared_security_devices (site_id,user_id,username,role,device_hash,device_label,browser_label,first_ip,last_ip,first_seen_at,last_seen_at)
             VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE username=VALUES(username),role=VALUES(role),device_label=VALUES(device_label),browser_label=VALUES(browser_label),last_ip=VALUES(last_ip),last_seen_at=NOW()'
        );
        if (!$stmt) return ['success' => false, 'http_code' => 503, 'code' => 'query_failed'];
        $stmt->bind_param('sisssssss', $siteId, $userId, $username, $role, $deviceHash, $label, $browserLabel, $ip, $ip);
        $ok = $stmt->execute();
        $stmt->close();
        return ['success' => $ok, 'http_code' => $ok ? 200 : 503, 'code' => $ok ? 'touched' : 'query_failed'];
    }

    if ($action === 'shared_security_device_detail') {
        $siteId = sharedSecurityNormalizeSiteId($input['target_site_id'] ?? '');
        $userId = max(0, (int) ($input['target_user_id'] ?? 0));
        $deviceHash = sharedSecurityNormalizeHash($input['device_hash'] ?? '');
        if ($siteId === '' || $userId < 1 || $deviceHash === '') {
            return ['success' => false, 'http_code' => 422, 'code' => 'invalid_device_detail'];
        }

        $stmt = $conn->prepare(
            'SELECT site_id,user_id,username,role,device_hash,device_label,browser_label,first_ip,last_ip,first_seen_at,last_seen_at
             FROM shared_security_devices WHERE site_id=? AND user_id=? AND device_hash=? LIMIT 1'
        );
        if (!$stmt) return ['success' => false, 'http_code' => 503, 'code' => 'query_failed'];
        $stmt->bind_param('sis', $siteId, $userId, $deviceHash);
        if (!$stmt->execute()) { $stmt->close(); return ['success' => false, 'http_code' => 503, 'code' => 'query_failed']; }
        $result = $stmt->get_result();
        $device = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        if (!$device) return ['success' => false, 'http_code' => 404, 'code' => 'device_not_found'];

        $deviceMatches = [];
        $stmt = $conn->prepare(
            'SELECT site_id,user_id,username,role,device_label,browser_label,last_ip,last_seen_at
             FROM shared_security_devices WHERE device_hash=? ORDER BY last_seen_at DESC LIMIT 20'
        );
        if ($stmt) {
            $stmt->bind_param('s', $deviceHash);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result) while ($row = $result->fetch_assoc()) $deviceMatches[] = $row;
            }
            $stmt->close();
        }

        $ipMatches = [];
        $lastIp = trim((string) ($device['last_ip'] ?? ''));
        if ($lastIp !== '' && filter_var($lastIp, FILTER_VALIDATE_IP)) {
            $stmt = $conn->prepare(
                'SELECT site_id,user_id,username,role,device_label,browser_label,last_ip,last_seen_at
                 FROM shared_security_devices WHERE last_ip=? ORDER BY last_seen_at DESC LIMIT 20'
            );
            if ($stmt) {
                $stmt->bind_param('s', $lastIp);
                if ($stmt->execute()) {
                    $result = $stmt->get_result();
                    if ($result) while ($row = $result->fetch_assoc()) $ipMatches[] = $row;
                }
                $stmt->close();
            }
        }

        return [
            'success' => true,
            'http_code' => 200,
            'device' => $device,
            'same_device_accounts' => $deviceMatches,
            'same_ip_accounts' => $ipMatches,
            'same_device_count' => count($deviceMatches),
            'same_ip_count' => count($ipMatches),
        ];
    }

    if ($action === 'shared_security_account_devices') {
        $siteId = sharedSecurityNormalizeSiteId($input['target_site_id'] ?? '');
        $userId = max(0, (int) ($input['target_user_id'] ?? 0));
        $limit = max(1, min(50, (int) ($input['limit'] ?? 20)));
        if ($siteId === '' || $userId < 1) return ['success' => false, 'http_code' => 422, 'code' => 'invalid_account'];
        $rows = [];
        $stmt = $conn->prepare(
            'SELECT site_id,user_id,username,role,device_hash,device_label,browser_label,first_ip,last_ip,first_seen_at,last_seen_at
             FROM shared_security_devices WHERE site_id=? AND user_id=? ORDER BY last_seen_at DESC LIMIT ?'
        );
        if (!$stmt) return ['success' => false, 'http_code' => 503, 'code' => 'query_failed'];
        $stmt->bind_param('sii', $siteId, $userId, $limit);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            if ($result) while ($row = $result->fetch_assoc()) $rows[] = $row;
        }
        $stmt->close();
        return ['success' => true, 'http_code' => 200, 'devices' => $rows];
    }

    if ($action === 'shared_security_list') {
        $perPage = max(10, min(100, (int) ($input['per_page'] ?? 50)));
        $devicePage = max(1, (int) ($input['device_page'] ?? 1));
        $blockPage = max(1, (int) ($input['block_page'] ?? 1));
        $q = sharedSecuritySanitizeText($input['q'] ?? '', 120);
        $like = '%' . strtr($q, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';

        $deviceTotal = 0;
        $blockTotal = 0;
        if ($q === '') {
            $result = $conn->query('SELECT COUNT(*) AS total FROM shared_security_devices');
            $deviceTotal = (int) (($result ? $result->fetch_assoc() : null)['total'] ?? 0);
            $result = $conn->query('SELECT COUNT(*) AS total FROM shared_security_blocks');
            $blockTotal = (int) (($result ? $result->fetch_assoc() : null)['total'] ?? 0);
        } else {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total FROM shared_security_devices
                 WHERE site_id LIKE ? ESCAPE '=' OR username LIKE ? ESCAPE '=' OR role LIKE ? ESCAPE '='
                    OR device_hash LIKE ? ESCAPE '=' OR device_label LIKE ? ESCAPE '=' OR browser_label LIKE ? ESCAPE '='
                    OR first_ip LIKE ? ESCAPE '=' OR last_ip LIKE ? ESCAPE '='"
            );
            if ($stmt) {
                $stmt->bind_param('ssssssss', $like, $like, $like, $like, $like, $like, $like, $like);
                if ($stmt->execute()) { $result = $stmt->get_result(); $deviceTotal = (int) (($result ? $result->fetch_assoc() : null)['total'] ?? 0); }
                $stmt->close();
            }
            $stmt = $conn->prepare(
                "SELECT COUNT(*) AS total FROM shared_security_blocks
                 WHERE subject_type LIKE ? ESCAPE '=' OR subject_display LIKE ? ESCAPE '='
                    OR reason LIKE ? ESCAPE '=' OR blocked_by_site_id LIKE ? ESCAPE '='"
            );
            if ($stmt) {
                $stmt->bind_param('ssss', $like, $like, $like, $like);
                if ($stmt->execute()) { $result = $stmt->get_result(); $blockTotal = (int) (($result ? $result->fetch_assoc() : null)['total'] ?? 0); }
                $stmt->close();
            }
        }

        $devicePages = max(1, (int) ceil($deviceTotal / $perPage));
        $blockPages = max(1, (int) ceil($blockTotal / $perPage));
        $devicePage = min($devicePage, $devicePages);
        $blockPage = min($blockPage, $blockPages);
        $deviceOffset = ($devicePage - 1) * $perPage;
        $blockOffset = ($blockPage - 1) * $perPage;
        $blocks = [];
        $devices = [];

        if ($q === '') {
            $stmt = $conn->prepare('SELECT subject_type,subject_hash,subject_display,reason,blocked_by_site_id,blocked_by_user_id,created_at,updated_at FROM shared_security_blocks ORDER BY updated_at DESC LIMIT ? OFFSET ?');
            if ($stmt) {
                $stmt->bind_param('ii', $perPage, $blockOffset);
                if ($stmt->execute()) { $result = $stmt->get_result(); if ($result) while ($row = $result->fetch_assoc()) $blocks[] = $row; }
                $stmt->close();
            }
            $stmt = $conn->prepare('SELECT site_id,user_id,username,role,device_hash,device_label,browser_label,first_ip,last_ip,first_seen_at,last_seen_at FROM shared_security_devices ORDER BY last_seen_at DESC LIMIT ? OFFSET ?');
            if ($stmt) {
                $stmt->bind_param('ii', $perPage, $deviceOffset);
                if ($stmt->execute()) { $result = $stmt->get_result(); if ($result) while ($row = $result->fetch_assoc()) $devices[] = $row; }
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare(
                "SELECT subject_type,subject_hash,subject_display,reason,blocked_by_site_id,blocked_by_user_id,created_at,updated_at
                 FROM shared_security_blocks
                 WHERE subject_type LIKE ? ESCAPE '=' OR subject_display LIKE ? ESCAPE '=' OR reason LIKE ? ESCAPE '=' OR blocked_by_site_id LIKE ? ESCAPE '='
                 ORDER BY updated_at DESC LIMIT ? OFFSET ?"
            );
            if ($stmt) {
                $stmt->bind_param('ssssii', $like, $like, $like, $like, $perPage, $blockOffset);
                if ($stmt->execute()) { $result = $stmt->get_result(); if ($result) while ($row = $result->fetch_assoc()) $blocks[] = $row; }
                $stmt->close();
            }
            $stmt = $conn->prepare(
                "SELECT site_id,user_id,username,role,device_hash,device_label,browser_label,first_ip,last_ip,first_seen_at,last_seen_at
                 FROM shared_security_devices
                 WHERE site_id LIKE ? ESCAPE '=' OR username LIKE ? ESCAPE '=' OR role LIKE ? ESCAPE '=' OR device_hash LIKE ? ESCAPE '='
                    OR device_label LIKE ? ESCAPE '=' OR browser_label LIKE ? ESCAPE '=' OR first_ip LIKE ? ESCAPE '=' OR last_ip LIKE ? ESCAPE '='
                 ORDER BY last_seen_at DESC LIMIT ? OFFSET ?"
            );
            if ($stmt) {
                $stmt->bind_param('ssssssssii', $like, $like, $like, $like, $like, $like, $like, $like, $perPage, $deviceOffset);
                if ($stmt->execute()) { $result = $stmt->get_result(); if ($result) while ($row = $result->fetch_assoc()) $devices[] = $row; }
                $stmt->close();
            }
        }

        return [
            'success' => true,
            'http_code' => 200,
            'blocks' => $blocks,
            'devices' => $devices,
            'device_pagination' => ['page' => $devicePage, 'pages' => $devicePages, 'total' => $deviceTotal, 'per_page' => $perPage],
            'block_pagination' => ['page' => $blockPage, 'pages' => $blockPages, 'total' => $blockTotal, 'per_page' => $perPage],
            'q' => $q,
        ];
    }

    return ['success' => false, 'http_code' => 400, 'code' => 'invalid_action', 'message' => 'Invalid shared security action'];
}

function sharedSecurityRouteRequest(string $action, array $payload): array
{
    if (!sharedSecurityEnsureBridgeLoaded()) return ['success' => false, 'unavailable' => true, 'code' => 'bridge_unavailable'];
    $siteId = sharedSecurityCurrentSiteId();
    if ($siteId === '') return ['success' => false, 'unavailable' => true, 'code' => 'site_identity_unavailable'];
    if (!isset($payload['site_id'])) $payload['site_id'] = $siteId;

    $route = sharedLedgerRemoteConnection();
    if (!empty($route['unavailable'])) return ['success' => false, 'unavailable' => true, 'code' => 'hub_unavailable'];
    if (($route['mode'] ?? 'local') === 'remote') {
        $connection = is_array($route['connection'] ?? null) ? $route['connection'] : null;
        if (!$connection) return ['success' => false, 'unavailable' => true, 'code' => 'hub_unavailable'];
        $api = supplierBridgeApiRequest($connection, $action, 'POST', $payload);
        $data = is_array($api['data'] ?? null) ? $api['data'] : [];
        if (empty($api['ok'])) {
            return [
                'success' => false,
                'unavailable' => !empty($api['transport_error']) || (int) ($api['http_code'] ?? 0) >= 500,
                'http_code' => (int) ($api['http_code'] ?? 0),
                'code' => (string) ($data['code'] ?? $api['error_code'] ?? 'remote_failed'),
                'message' => (string) ($data['message'] ?? $api['error'] ?? ''),
            ];
        }
        return $data + ['success' => !empty($data['success']), 'http_code' => (int) ($api['http_code'] ?? 200)];
    }
    return sharedSecurityApiAction($action, $payload);
}

function sharedSecurityCheckSubjects(array $subjects): array
{
    $payload = [];
    foreach ($subjects as $subject) {
        if (!is_array($subject)) continue;
        $type = strtolower(trim((string) ($subject['type'] ?? '')));
        $hash = sharedSecurityNormalizeHash($subject['hash'] ?? '');
        if (!in_array($type, ['ip', 'device', 'email'], true) || $hash === '') continue;
        $payload[] = ['type' => $type, 'hash' => $hash];
        if (count($payload) >= 8) break;
    }
    if ($payload === []) return ['success' => true, 'blocked' => []];
    return sharedSecurityRouteRequest('shared_security_check', ['subjects' => $payload]);
}

function sharedSecurityBlock(string $type, $value, string $reason, int $adminId): array
{
    $subject = sharedSecuritySubject($type, $value);
    if (!$subject) return ['success' => false, 'code' => 'invalid_subject'];
    return sharedSecurityRouteRequest('shared_security_block', [
        'subject_type' => $subject['type'],
        'subject_hash' => $subject['hash'],
        'subject_display' => $subject['display'],
        'reason' => sharedSecuritySanitizeText($reason, 255),
        'admin_id' => max(0, $adminId),
    ]);
}

function sharedSecurityUnblock(string $type, $value): array
{
    $subject = sharedSecuritySubject($type, $value);
    if (!$subject) return ['success' => false, 'code' => 'invalid_subject'];
    return sharedSecurityRouteRequest('shared_security_unblock', [
        'subject_type' => $subject['type'],
        'subject_hash' => $subject['hash'],
    ]);
}


function sharedSecurityTouchDevice(int $userId, string $username, string $role, string $deviceHash, string $deviceLabel, string $browserLabel, string $ip): array
{
    return sharedSecurityRouteRequest('shared_security_touch_device', [
        'user_id' => $userId,
        'username' => sharedSecuritySanitizeText($username, 190),
        'role' => sharedSecuritySanitizeText($role, 32),
        'device_hash' => $deviceHash,
        'device_label' => sharedSecuritySanitizeText($deviceLabel, 160),
        'browser_label' => sharedSecuritySanitizeText($browserLabel, 96),
        'last_ip' => $ip,
    ]);
}

function sharedSecurityList(array $options = []): array
{
    return sharedSecurityRouteRequest('shared_security_list', [
        'q' => sharedSecuritySanitizeText($options['q'] ?? '', 120),
        'device_page' => max(1, (int) ($options['device_page'] ?? 1)),
        'block_page' => max(1, (int) ($options['block_page'] ?? 1)),
        'per_page' => max(10, min(100, (int) ($options['per_page'] ?? 50))),
    ]);
}

function sharedSecurityDeviceDetail(string $siteId, int $userId, string $deviceHash): array
{
    return sharedSecurityRouteRequest('shared_security_device_detail', [
        'target_site_id' => sharedSecurityNormalizeSiteId($siteId),
        'target_user_id' => max(0, $userId),
        'device_hash' => sharedSecurityNormalizeHash($deviceHash),
    ]);
}

function sharedSecurityAccountDevices(string $siteId, int $userId, int $limit = 20): array
{
    return sharedSecurityRouteRequest('shared_security_account_devices', [
        'target_site_id' => sharedSecurityNormalizeSiteId($siteId),
        'target_user_id' => max(0, $userId),
        'limit' => max(1, min(50, $limit)),
    ]);
}
