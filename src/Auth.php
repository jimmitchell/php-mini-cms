<?php

declare(strict_types=1);

namespace CMS;

use RuntimeException;

class Auth
{
    private array $config;
    private Database $db;

    public function __construct(array $config, Database $db)
    {
        $this->config = $config;
        $this->db     = $db;
    }

    // ── Session bootstrap ─────────────────────────────────────────────────────

    /**
     * Start the session using the name and cookie settings from config.
     * Must be called before any output.
     */
    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $name     = $this->config['admin']['session_name'] ?? 'cms_session';
        $lifetime = (int) ($this->config['admin']['session_lifetime'] ?? 3600);

        session_name($name);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    // ── Authentication ────────────────────────────────────────────────────────

    /**
     * Attempt a login. Returns true on success, false on failure.
     * On failure, records the attempt; on success, regenerates session.
     */
    public function login(string $username, string $password): bool
    {
        $ip = $this->clientIp();

        if ($this->isLockedOut($ip)) {
            return false;
        }

        $expectedUser = $this->config['admin']['username'] ?? '';
        $hash         = $this->config['admin']['password_hash'] ?? '';

        $ok = ($username === $expectedUser)
            && $hash !== ''
            && password_verify($password, $hash);

        $this->recordAttempt($ip, $ok);

        if ($ok) {
            session_regenerate_id(true);
            if ($this->isTotpEnabled()) {
                $_SESSION['totp_pending']      = true;
                $_SESSION['totp_pending_user'] = $username;
                $_SESSION['csrf_token']        = $this->generateToken();
            } else {
                $_SESSION['authenticated'] = true;
                $_SESSION['user']          = $username;
                $_SESSION['csrf_token']    = $this->generateToken();
            }
        }

        return $ok;
    }

    /** Destroy the session and redirect to login. */
    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        header('Location: /admin/');
        exit;
    }

    /**
     * Require a valid session. Redirects to login if not authenticated.
     * Call this at the top of every protected admin page.
     */
    public function check(): void
    {
        if (empty($_SESSION['authenticated'])) {
            header('Location: /admin/');
            exit;
        }
    }

    /** Returns true if the current session is authenticated. */
    public function isAuthenticated(): bool
    {
        return !empty($_SESSION['authenticated']);
    }

    // ── CSRF ──────────────────────────────────────────────────────────────────

    /** Return the CSRF token for the current session (generate if absent). */
    public function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = $this->generateToken();
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify the CSRF token from a POST request.
     * Terminates with 403 if invalid.
     */
    public function verifyCsrf(string $token): void
    {
        $expected = $_SESSION['csrf_token'] ?? '';
        if (!hash_equals($expected, $token)) {
            http_response_code(403);
            exit('CSRF token mismatch.');
        }
    }

    // ── Rate limiting ─────────────────────────────────────────────────────────

    /** Returns true if the IP is currently locked out. */
    public function isLockedOut(string $ip): bool
    {
        $maxAttempts    = (int) ($this->config['security']['max_login_attempts'] ?? 5);
        $lockoutMinutes = (int) ($this->config['security']['lockout_minutes'] ?? 15);

        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS cnt
               FROM login_attempts
              WHERE ip = :ip
                AND success = 0
                AND attempted_at >= datetime('now', :window)",
            ['ip' => $ip, 'window' => "-{$lockoutMinutes} minutes"]
        );

        return ($row['cnt'] ?? 0) >= $maxAttempts;
    }

    /** Return the number of seconds remaining in a lockout (0 if not locked). */
    public function lockoutSecondsRemaining(string $ip): int
    {
        $maxAttempts    = (int) ($this->config['security']['max_login_attempts'] ?? 5);
        $lockoutMinutes = (int) ($this->config['security']['lockout_minutes'] ?? 15);

        $row = $this->db->selectOne(
            "SELECT attempted_at
               FROM login_attempts
              WHERE ip = :ip AND success = 0
                AND attempted_at >= datetime('now', :window)
              ORDER BY attempted_at DESC
              LIMIT 1 OFFSET :offset",
            ['ip' => $ip, 'window' => "-{$lockoutMinutes} minutes", 'offset' => $maxAttempts - 1]
        );

        if (!$row) {
            return 0;
        }

        $lockoutEnds = strtotime($row['attempted_at']) + ($lockoutMinutes * 60);
        $remaining   = $lockoutEnds - time();
        return max(0, $remaining);
    }

    // ── TOTP 2FA ──────────────────────────────────────────────────────────────

    public function isTotpEnabled(): bool
    {
        return $this->db->getSetting('totp_enabled', '0') === '1';
    }

    public function isTotpPending(): bool
    {
        return !empty($_SESSION['totp_pending']);
    }

    public function completeTotpLogin(): void
    {
        $user = $_SESSION['totp_pending_user'] ?? '';
        unset($_SESSION['totp_pending'], $_SESSION['totp_pending_user']);
        $_SESSION['authenticated'] = true;
        $_SESSION['user']          = $user;
        $_SESSION['csrf_token']    = $this->generateToken();
    }

    public function generateTotpSecret(): string
    {
        $totp = \OTPHP\TOTP::generate();
        return $totp->getSecret();
    }

    public function verifyTotp(string $code): bool
    {
        $secret = $this->db->getSetting('totp_secret', '');
        if ($secret === '') {
            return false;
        }
        $totp = \OTPHP\TOTP::createFromSecret($secret);
        return $totp->verify($code, null, 1);
    }

    public function enableTotp(string $secret): void
    {
        $this->db->upsertSetting('totp_secret', $secret);
        $this->db->upsertSetting('totp_enabled', '1');
    }

    public function disableTotp(): void
    {
        $this->db->upsertSetting('totp_enabled', '0');
        $this->db->upsertSetting('totp_secret', '');
        $this->db->delete('totp_backup_codes', '1=1');
    }

    /**
     * Generate $count backup/recovery codes, store their bcrypt hashes,
     * and return the plaintext codes (shown once, never stored).
     *
     * @return string[]
     */
    public function generateBackupCodes(int $count = 8): array
    {
        $this->db->delete('totp_backup_codes', '1=1');
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $hex   = bin2hex(random_bytes(5));
            $plain = strtoupper(substr($hex, 0, 5) . '-' . substr($hex, 5));
            $codes[] = $plain;
            $this->db->insert('totp_backup_codes', [
                'code_hash' => password_hash($plain, PASSWORD_BCRYPT),
            ]);
        }
        return $codes;
    }

    public function verifyBackupCode(string $input): bool
    {
        $rows    = $this->db->select("SELECT id, code_hash FROM totp_backup_codes WHERE used_at IS NULL");
        $matched = null;
        foreach ($rows as $row) {
            if (password_verify($input, $row['code_hash'])) {
                $matched = $row['id'];
            }
        }
        if ($matched !== null) {
            $this->db->update(
                'totp_backup_codes',
                ['used_at' => date('Y-m-d H:i:s')],
                'id = :id',
                ['id' => $matched]
            );
            return true;
        }
        return false;
    }

    public function isTotpLockedOut(string $ip): bool
    {
        $maxAttempts    = (int) ($this->config['security']['max_login_attempts'] ?? 5);
        $lockoutMinutes = (int) ($this->config['security']['lockout_minutes'] ?? 15);

        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS cnt
               FROM login_attempts
              WHERE ip = :ip
                AND success = 0
                AND attempted_at >= datetime('now', :window)",
            ['ip' => 'totp:' . $ip, 'window' => "-{$lockoutMinutes} minutes"]
        );

        return ($row['cnt'] ?? 0) >= $maxAttempts;
    }

    public function recordTotpAttempt(string $ip, bool $success): void
    {
        $this->db->insert('login_attempts', [
            'ip'      => 'totp:' . $ip,
            'success' => $success ? 1 : 0,
        ]);
    }

    private function recordAttempt(string $ip, bool $success): void
    {
        $this->db->insert('login_attempts', [
            'ip'      => $ip,
            'success' => $success ? 1 : 0,
        ]);
    }

    // ── Flash messages ────────────────────────────────────────────────────────

    /**
     * Store a flash message in the session.
     * $type is one of: 'success' | 'error' | 'info'
     */
    public function flash(string $message, string $type = 'success'): void
    {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    }

    /**
     * Retrieve and clear the flash message.
     * Returns ['message' => string, 'type' => string] or null.
     *
     * @return array{message:string,type:string}|null
     */
    public function getFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $flash;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function clientIp(): string
    {
        // Trust X-Forwarded-For only if behind a known proxy; for simplicity use REMOTE_ADDR.
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
