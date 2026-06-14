<?php

declare(strict_types=1);

function accountSessionsEnsureStorage(?PDO $pdo): void
{
    static $initialized = false;

    if (!$pdo instanceof PDO || $initialized) {
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_account_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            session_id_hash CHAR(64) NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(1024) DEFAULT NULL,
            device_label VARCHAR(160) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked_at DATETIME DEFAULT NULL,
            UNIQUE KEY uniq_user_account_session (user_id, session_id_hash),
            KEY idx_user_account_sessions_user (user_id, revoked_at, last_activity_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $initialized = true;
}

function accountSessionsCurrentSessionId(): string
{
    $sessionId = session_id();
    if (!is_string($sessionId) || $sessionId === '') {
        throw new RuntimeException('Session PHP indisponible.');
    }

    return $sessionId;
}

function accountSessionsHashSessionId(string $sessionId): string
{
    return hash('sha256', $sessionId);
}

function accountSessionsClientIp(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }

        $parts = array_map('trim', explode(',', $candidate));
        foreach ($parts as $part) {
            if ($part !== '') {
                return substr($part, 0, 45);
            }
        }
    }

    return 'Inconnue';
}

function accountSessionsUserAgent(): string
{
    $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Navigateur inconnu'));
    if ($userAgent === '') {
        return 'Navigateur inconnu';
    }

    return substr($userAgent, 0, 1024);
}

function accountSessionsInferDeviceLabel(string $userAgent): string
{
    $normalized = strtolower($userAgent);

    $platform = 'Appareil inconnu';
    if (strpos($normalized, 'iphone') !== false) {
        $platform = 'iPhone';
    } elseif (strpos($normalized, 'ipad') !== false) {
        $platform = 'iPad';
    } elseif (strpos($normalized, 'android') !== false) {
        $platform = 'Appareil Android';
    } elseif (strpos($normalized, 'windows') !== false) {
        $platform = 'PC Windows';
    } elseif (strpos($normalized, 'mac os') !== false || strpos($normalized, 'macintosh') !== false) {
        $platform = 'Mac';
    } elseif (strpos($normalized, 'linux') !== false || strpos($normalized, 'x11') !== false) {
        $platform = 'Poste Linux';
    }

    $browser = 'Navigateur';
    if (strpos($normalized, 'edg/') !== false) {
        $browser = 'Edge';
    } elseif (strpos($normalized, 'opr/') !== false || strpos($normalized, 'opera') !== false) {
        $browser = 'Opera';
    } elseif (strpos($normalized, 'chrome/') !== false && strpos($normalized, 'edg/') === false) {
        $browser = 'Chrome';
    } elseif (strpos($normalized, 'firefox/') !== false) {
        $browser = 'Firefox';
    } elseif (strpos($normalized, 'safari/') !== false && strpos($normalized, 'chrome/') === false) {
        $browser = 'Safari';
    }

    return $platform . ' · ' . $browser;
}

function accountSessionsTouchCurrent(?PDO $pdo, int $userId): array
{
    if (!$pdo instanceof PDO) {
        return [];
    }

    accountSessionsEnsureStorage($pdo);

    $sessionId = accountSessionsCurrentSessionId();
    $sessionIdHash = accountSessionsHashSessionId($sessionId);
    $userAgent = accountSessionsUserAgent();
    $ipAddress = accountSessionsClientIp();
    $deviceLabel = accountSessionsInferDeviceLabel($userAgent);

    $stmt = $pdo->prepare(
        'INSERT INTO user_account_sessions (user_id, session_id_hash, ip_address, user_agent, device_label, created_at, last_activity_at, revoked_at)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NULL)
         ON DUPLICATE KEY UPDATE
            ip_address = VALUES(ip_address),
            user_agent = VALUES(user_agent),
            device_label = VALUES(device_label),
            last_activity_at = NOW(),
            revoked_at = NULL'
    );
    $stmt->execute([$userId, $sessionIdHash, $ipAddress, $userAgent, $deviceLabel]);

    $select = $pdo->prepare(
        'SELECT * FROM user_account_sessions WHERE user_id = ? AND session_id_hash = ? LIMIT 1'
    );
    $select->execute([$userId, $sessionIdHash]);
    $row = $select->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
}

function accountSessionsIsCurrentSessionRevoked(?PDO $pdo, int $userId): bool
{
    if (!$pdo instanceof PDO) {
        return false;
    }

    accountSessionsEnsureStorage($pdo);

    $stmt = $pdo->prepare(
        'SELECT revoked_at FROM user_account_sessions WHERE user_id = ? AND session_id_hash = ? LIMIT 1'
    );
    $stmt->execute([$userId, accountSessionsHashSessionId(accountSessionsCurrentSessionId())]);
    $revokedAt = $stmt->fetchColumn();

    return $revokedAt !== false && $revokedAt !== null;
}

function accountSessionsListForUser(?PDO $pdo, int $userId): array
{
    if (!$pdo instanceof PDO) {
        return [];
    }

    accountSessionsEnsureStorage($pdo);

    $stmt = $pdo->prepare(
        'SELECT * FROM user_account_sessions WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_activity_at DESC, created_at DESC'
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

function accountSessionsRevokeById(?PDO $pdo, int $userId, int $sessionRecordId): bool
{
    if (!$pdo instanceof PDO) {
        return false;
    }

    accountSessionsEnsureStorage($pdo);

    $stmt = $pdo->prepare(
        'UPDATE user_account_sessions SET revoked_at = NOW() WHERE id = ? AND user_id = ? AND revoked_at IS NULL LIMIT 1'
    );
    $stmt->execute([$sessionRecordId, $userId]);

    return $stmt->rowCount() > 0;
}

function accountSessionsRevokeCurrent(?PDO $pdo, int $userId): void
{
    if (!$pdo instanceof PDO) {
        return;
    }

    accountSessionsEnsureStorage($pdo);

    $stmt = $pdo->prepare(
        'UPDATE user_account_sessions SET revoked_at = NOW() WHERE user_id = ? AND session_id_hash = ? AND revoked_at IS NULL'
    );
    $stmt->execute([$userId, accountSessionsHashSessionId(accountSessionsCurrentSessionId())]);
}

function accountSessionsRevokeOtherSessions(?PDO $pdo, int $userId): int
{
    if (!$pdo instanceof PDO) {
        return 0;
    }

    accountSessionsEnsureStorage($pdo);

    $stmt = $pdo->prepare(
        'UPDATE user_account_sessions SET revoked_at = NOW() WHERE user_id = ? AND session_id_hash <> ? AND revoked_at IS NULL'
    );
    $stmt->execute([$userId, accountSessionsHashSessionId(accountSessionsCurrentSessionId())]);

    return $stmt->rowCount();
}

function accountSessionsDestroyPhpSession(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
    }

    session_destroy();
}

function accountSessionsFormatDate(?string $value): string
{
    if (!is_string($value) || trim($value) === '') {
        return 'Inconnue';
    }

    try {
        $date = new DateTimeImmutable($value);
        return $date->format('d/m/Y H:i');
    } catch (Throwable $exception) {
        return $value;
    }
}
