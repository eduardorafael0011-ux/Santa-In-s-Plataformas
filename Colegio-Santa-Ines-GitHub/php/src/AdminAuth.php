<?php
declare(strict_types=1);

namespace SantaInes\Security;

use SantaInes\Infrastructure\DataStore;

/** Sessões da Equipe TE, hash de senha, limitação simples e recuperação. */
final class AdminAuth
{
    private const SESSION_SECONDS = 43200; // 12 horas

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $directory = DataStore::directory() . '/sessions';
        if (!is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }
        @chmod($directory, 0700);
        session_save_path($directory);
        // Cookie próprio para não colidir com a sessão do servidor Node local.
        session_name('school_php_session');
        ini_set('session.gc_maxlifetime', (string)self::SESSION_SECONDS);
        session_set_cookie_params([
            'lifetime' => self::SESSION_SECONDS,
            'path' => '/',
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function current(): ?array
    {
        self::start();
        $email = $_SESSION['admin_email'] ?? '';
        $version = $_SESSION['auth_version'] ?? '';
        if ($email === '' || $version === '') {
            return null;
        }
        foreach (DataStore::read('admins', []) as $admin) {
            $currentHash = (string)($admin['passwordHash'] ?? '');
            if (($admin['email'] ?? '') === $email && $currentHash !== '' && hash_equals($currentHash, $version)) {
                return ['email' => $email, 'name' => (string)($admin['name'] ?? '')];
            }
        }
        self::logout();
        return null;
    }

    public static function login(string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        foreach (DataStore::read('admins', []) as $admin) {
            if (strtolower((string)($admin['email'] ?? '')) !== $email) {
                continue;
            }
            $storedHash = (string)($admin['passwordHash'] ?? '');
            if ($storedHash === '' || !self::verifyPassword($password, $storedHash)) {
                return null;
            }
            // Regrava formatos PHP antigos no hash comum para manter compatibilidade com o modo Node.
            if (!str_starts_with($storedHash, 'pbkdf2_sha256$')) {
                $admins = DataStore::read('admins', []);
                foreach ($admins as &$storedAdmin) {
                    if (strtolower((string)($storedAdmin['email'] ?? '')) === $email) {
                        $storedAdmin['passwordHash'] = self::hashPassword($password);
                        unset($storedAdmin['hash'], $storedAdmin['salt']);
                        $storedHash = $storedAdmin['passwordHash'];
                        break;
                    }
                }
                unset($storedAdmin);
                DataStore::write('admins', $admins);
            }
            self::start();
            session_regenerate_id(true);
            $_SESSION['admin_email'] = $email;
            $_SESSION['admin_name'] = (string)($admin['name'] ?? '');
            $_SESSION['auth_version'] = $storedHash;
            return ['email' => $email, 'name' => (string)($admin['name'] ?? '')];
        }
        return null;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => (bool)$params['secure'],
                'httponly' => (bool)$params['httponly'],
                'samesite' => $params['samesite'] ?? 'Strict',
            ]);
        }
        session_destroy();
    }

    /** File-backed limiter survives PHP's request-per-process development server. */
    public static function allowAttempt(string $key, int $limit, int $windowSeconds): bool
    {
        $now = time();
        $limits = DataStore::read('rate_limits', []);
        $times = array_values(array_filter($limits[$key] ?? [], static fn($time) => is_int($time) && $now - $time < $windowSeconds));
        if (count($times) >= $limit) {
            $limits[$key] = $times;
            DataStore::write('rate_limits', $limits);
            return false;
        }
        $times[] = $now;
        $limits[$key] = $times;
        DataStore::write('rate_limits', $limits);
        return true;
    }

    public static function requestRecovery(string $email): bool
    {
        $config = self::mailConfig();
        if (in_array('', $config, true)) {
            throw new \RuntimeException('A recuperação por e-mail ainda não está configurada.');
        }
        $admins = DataStore::read('admins', []);
        $adminExists = false;
        foreach ($admins as $admin) {
            if (strtolower((string)($admin['email'] ?? '')) === strtolower($email)) {
                $adminExists = true;
                break;
            }
        }

        // Resposta idêntica para endereços cadastrados e não cadastrados.
        if (!$adminExists) {
            return false;
        }
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        self::sendRecoveryCode($config, $email, $code);
        $requests = DataStore::read('recovery', []);
        $requests[strtolower($email)] = [
            'hash' => hash('sha256', $code),
            'expires' => time() + 600,
            'attempts' => 0,
        ];
        DataStore::write('recovery', $requests);
        return true;
    }

    public static function completeRecovery(string $email, string $code, string $password): bool
    {
        $email = strtolower(trim($email));
        $requests = DataStore::read('recovery', []);
        $pending = $requests[$email] ?? null;
        if (!is_array($pending) || ($pending['expires'] ?? 0) < time() || ($pending['attempts'] ?? 0) >= 5) {
            return false;
        }
        $requests[$email]['attempts'] = (int)$pending['attempts'] + 1;
        DataStore::write('recovery', $requests);
        if (!preg_match('/^\d{6}$/', $code) || !hash_equals((string)$pending['hash'], hash('sha256', $code))) {
            return false;
        }

        $admins = DataStore::read('admins', []);
        $found = false;
        foreach ($admins as &$admin) {
            if (strtolower((string)($admin['email'] ?? '')) === $email) {
                $admin['passwordHash'] = self::hashPassword($password);
                unset($admin['hash'], $admin['salt']);
                $found = true;
                break;
            }
        }
        unset($admin);
        if (!$found) {
            return false;
        }
        DataStore::write('admins', $admins);
        unset($requests[$email]);
        DataStore::write('recovery', $requests);
        return true;
    }

    public static function allowLocalReset(): bool
    {
        $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '') ?? '');
        $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''), '[]');
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $originHost = $origin !== '' ? strtolower((string)parse_url($origin, PHP_URL_HOST)) : '';
        $localHosts = ['localhost', '127.0.0.1', '::1'];
        return (getenv('APP_ENV') ?: 'development') !== 'production'
            && in_array($host, $localHosts, true)
            && in_array($remote, ['127.0.0.1', '::1'], true)
            && ($origin === '' || $originHost === $host)
            && !is_file(DataStore::directory() . '/local-reset-used');
    }

    public static function completeLocalReset(string $password, string $confirmation): void
    {
        $admins = DataStore::read('admins', []);
        if (count($admins) !== 1) {
            throw new \RuntimeException('A redefinição local exige exatamente uma conta administrativa.');
        }
        if (strlen($password) < 12 || strlen($password) > 256 || !hash_equals($password, $confirmation)) {
            throw new \InvalidArgumentException('Use uma senha de pelo menos 12 caracteres e confirme o mesmo valor.');
        }
        $admins[0]['passwordHash'] = self::hashPassword($password);
        unset($admins[0]['hash'], $admins[0]['salt']);
        DataStore::write('admins', $admins);
        $marker = DataStore::directory() . '/local-reset-used';
        file_put_contents($marker, gmdate('c'), LOCK_EX);
        @chmod($marker, 0600);
    }

    private static function mailConfig(): array
    {
        $file = DataStore::directory() . '/m365-mail.json';
        $saved = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
        $saved = is_array($saved) ? $saved : [];
        return [
            'tenantId' => getenv('M365_TENANT_ID') ?: ($saved['tenantId'] ?? ''),
            'clientId' => getenv('M365_CLIENT_ID') ?: ($saved['clientId'] ?? ''),
            'clientSecret' => getenv('M365_CLIENT_SECRET') ?: ($saved['clientSecret'] ?? ''),
            'fromEmail' => getenv('RESET_FROM_EMAIL') ?: ($saved['fromEmail'] ?? ''),
        ];
    }

    /** Formato PBKDF2 comum ao PHP e Node, para as duas formas de executar o site. */
    private static function hashPassword(string $password): string
    {
        $salt = bin2hex(random_bytes(16));
        $iterations = 210000;
        $derived = bin2hex(hash_pbkdf2('sha256', $password, hex2bin($salt), $iterations, 64, true));
        return 'pbkdf2_sha256$' . $iterations . '$' . $salt . '$' . $derived;
    }

    /** Também reconhece hash bcrypt legado criado por versões preliminares do backend PHP. */
    private static function verifyPassword(string $password, string $stored): bool
    {
        $parts = explode('$', $stored);
        if (count($parts) !== 4 || $parts[0] !== 'pbkdf2_sha256') {
            return password_verify($password, $stored);
        }
        $iterations = filter_var($parts[1], FILTER_VALIDATE_INT);
        if (!$iterations || $iterations < 100000 || $iterations > 1000000
            || !preg_match('/^[a-f0-9]{32,128}$/i', $parts[2])
            || !preg_match('/^[a-f0-9]{128}$/i', $parts[3])) {
            return false;
        }
        $derived = bin2hex(hash_pbkdf2('sha256', $password, hex2bin($parts[2]), $iterations, 64, true));
        return hash_equals(strtolower($parts[3]), strtolower($derived));
    }

    /** Solicita token do Microsoft Entra e envia o código pelo Microsoft Graph. */
    private static function sendRecoveryCode(array $config, string $email, string $code): void
    {
        $tokenBody = http_build_query([
            'client_id' => $config['clientId'],
            'client_secret' => $config['clientSecret'],
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);
        $token = self::httpRequest('https://login.microsoftonline.com/' . rawurlencode($config['tenantId']) . '/oauth2/v2.0/token', $tokenBody, 'application/x-www-form-urlencoded');
        $accessToken = json_decode($token, true)['access_token'] ?? '';
        if ($accessToken === '') {
            throw new \RuntimeException('Não foi possível autenticar no Microsoft 365.');
        }
        $message = [
            'message' => [
                'subject' => 'Código para redefinir sua senha · Colégio Santa Inês',
                'body' => ['contentType' => 'HTML', 'content' => '<p>Use este código para redefinir sua senha da equipe TE:</p><p style="font-size:28px;font-weight:bold;letter-spacing:6px">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p><p>O código expira em 10 minutos.</p>'],
                'toRecipients' => [['emailAddress' => ['address' => $email]]],
            ],
            'saveToSentItems' => false,
        ];
        self::httpRequest('https://graph.microsoft.com/v1.0/users/' . rawurlencode($config['fromEmail']) . '/sendMail', json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json', $accessToken);
    }

    private static function httpRequest(string $url, string $body, string $contentType, string $bearer = ''): string
    {
        $headers = "Content-Type: {$contentType}\r\n";
        if ($bearer !== '') {
            $headers .= "Authorization: Bearer {$bearer}\r\n";
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => $headers,
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 15,
        ]]);
        $response = file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if (!is_string($response) || !preg_match('/\s2\d\d\s/', $statusLine)) {
            throw new \RuntimeException('O serviço de e-mail não concluiu o envio.');
        }
        return $response;
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }
}
