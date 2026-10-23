<?php
declare(strict_types=1);

namespace SantaInes\Security;

use SantaInes\Infrastructure\DataStore;
use SantaInes\Http\HttpException;

/** Contas e sessões dos professores, isoladas das sessões administrativas. */
final class TeacherAuth
{
    private const SESSION_SECONDS = 43200;
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $directory = DataStore::directory() . '/sessions';
        if (!is_dir($directory)) @mkdir($directory, 0700, true);
        @chmod($directory, 0700);
        session_save_path($directory);
        // Compartilha o cookie PHP, mas usa chaves separadas da sessão administrativa.
        session_name('school_php_session');
        ini_set('session.gc_maxlifetime', (string)self::SESSION_SECONDS);
        $https = self::isHttps();
        session_set_cookie_params(['lifetime'=>self::SESSION_SECONDS,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Strict']);
        session_start();
    }

    public static function current(): ?array
    {
        self::start();
        $email = strtolower((string)($_SESSION['teacher_email'] ?? ''));
        $version = (string)($_SESSION['teacher_auth_version'] ?? '');
        if ($email === '' || $version === '') return null;
        foreach (DataStore::read('teachers', []) as $teacher) {
            if (strtolower((string)($teacher['email'] ?? '')) !== $email) continue;
            $hash = (string)($teacher['passwordHash'] ?? '');
            if ($hash === '' || !hash_equals($hash, $version)) { self::logout(); return null; }
            return ['email'=>$email,'name'=>(string)($teacher['name'] ?? ''),'platformIds'=>array_values($teacher['platformIds'] ?? []),'mustChangePassword'=>(bool)($teacher['mustChangePassword'] ?? false)];
        }
        self::logout();
        return null;
    }

    public static function login(string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        foreach (DataStore::read('teachers', []) as $teacher) {
            if (strtolower((string)($teacher['email'] ?? '')) !== $email) continue;
            $encoded = explode('$', (string)($teacher['passwordHash'] ?? ''));
            if (count($encoded) !== 4 || $encoded[0] !== 'pbkdf2_sha256') return null;
            $candidate = hash_pbkdf2('sha256', $password, hex2bin($encoded[2]) ?: '', (int)$encoded[1], 128);
            if (!hash_equals($encoded[3], $candidate)) return null;
            self::start();
            session_regenerate_id(true);
            $_SESSION['teacher_email'] = $email;
            $_SESSION['teacher_auth_version'] = $teacher['passwordHash'];
            return self::current();
        }
        return null;
    }

    public static function list(): array
    {
        return array_map(static fn(array $teacher): array => [
            'email'=>(string)$teacher['email'], 'name'=>(string)$teacher['name'],
            'platformIds'=>array_values($teacher['platformIds'] ?? []),
            'mustChangePassword'=>(bool)($teacher['mustChangePassword'] ?? false),
        ], DataStore::read('teachers', []));
    }

    public static function saveTeacher(string $email, string $name, array $platformIds): array
    {
        $email = strtolower(trim($email));
        $name = trim((string)preg_replace('/\s+/u', ' ', $name));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with($email, '@santainesrs.com.br')) {
            throw new HttpException(400, 'Use o e-mail institucional do professor (@santainesrs.com.br).');
        }
        if ($name === '' || strlen($name) > 160) throw new HttpException(400, 'Informe o nome do professor.');
        if (!$platformIds) throw new HttpException(400, 'Selecione ao menos uma plataforma que o professor leciona.');
        $platformIds = array_values(array_unique(array_map('strval', $platformIds)));
        $teachers = DataStore::read('teachers', []);
        foreach ($teachers as &$teacher) {
            if (strtolower((string)($teacher['email'] ?? '')) !== $email) continue;
            $teacher['name'] = $name;
            $teacher['platformIds'] = $platformIds;
            $public = self::publicTeacher($teacher);
            unset($teacher);
            DataStore::write('teachers', $teachers);
            return ['created'=>false, 'teacher'=>$public];
        }
        unset($teacher);
        $initialPassword = self::initialPassword();
        $teacher = ['email'=>$email,'name'=>$name,'platformIds'=>$platformIds,'passwordHash'=>self::hashPassword($initialPassword),'mustChangePassword'=>true,'createdAt'=>gmdate('c')];
        $teachers[] = $teacher;
        DataStore::write('teachers', $teachers);
        return ['created'=>true,'teacher'=>self::publicTeacher($teacher),'initialPassword'=>$initialPassword];
    }

    public static function resetPassword(string $email): array
    {
        $email = strtolower(trim($email));
        $initialPassword = self::initialPassword();
        $teachers = DataStore::read('teachers', []);
        $found = false;
        foreach ($teachers as &$teacher) {
            if (strtolower((string)($teacher['email'] ?? '')) !== $email) continue;
            $teacher['passwordHash'] = self::hashPassword($initialPassword);
            $teacher['mustChangePassword'] = true;
            $found = true;
            break;
        }
        unset($teacher);
        if (!$found) throw new HttpException(404, 'Professor não encontrado.');
        DataStore::write('teachers', $teachers);
        return ['reset'=>true,'email'=>$email,'initialPassword'=>$initialPassword];
    }

    public static function changePassword(string $password, string $confirmation): array
    {
        if (strlen($password) < 4 || strlen($password) > 256 || !hash_equals($password, $confirmation)) {
            throw new HttpException(400, 'Use uma senha com pelo menos 4 caracteres e confirme a mesma senha.');
        }
        $current = self::current();
        if (!$current) throw new HttpException(401, 'Entre novamente para alterar sua senha.');
        $teachers = DataStore::read('teachers', []);
        foreach ($teachers as &$teacher) {
            if (strtolower((string)($teacher['email'] ?? '')) !== $current['email']) continue;
            $teacher['passwordHash'] = self::hashPassword($password);
            $teacher['mustChangePassword'] = false;
            $hash = $teacher['passwordHash'];
            break;
        }
        unset($teacher);
        DataStore::write('teachers', $teachers);
        $_SESSION['teacher_auth_version'] = $hash;
        return ['saved'=>true];
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', ['expires'=>time()-42000,'path'=>$params['path'],'domain'=>$params['domain'],'secure'=>(bool)$params['secure'],'httponly'=>(bool)$params['httponly'],'samesite'=>$params['samesite'] ?? 'Strict']);
        }
        session_destroy();
    }

    private static function publicTeacher(array $teacher): array
    {
        return ['email'=>$teacher['email'] ?? '', 'name'=>$teacher['name'] ?? '', 'platformIds'=>array_values($teacher['platformIds'] ?? []),'mustChangePassword'=>(bool)($teacher['mustChangePassword'] ?? false)];
    }

    /** Busca o segredo temporário no ambiente ou no arquivo privado fora do Git. */
    private static function initialPassword(): string
    {
        $password = getenv('SANTA_INES_TEACHER_INITIAL_PASSWORD');
        if (!is_string($password) || $password === '') {
            $config = DataStore::read('teacher_auth_config', []);
            $password = is_array($config) ? (string)($config['initialPassword'] ?? '') : '';
        }
        if (strlen($password) < 4 || strlen($password) > 256) {
            throw new HttpException(503, 'Configure a senha temporária docente no ambiente privado do servidor.');
        }
        return $password;
    }

    private static function hashPassword(string $password): string
    {
        $salt = random_bytes(16);
        return 'pbkdf2_sha256$210000$' . bin2hex($salt) . '$' . hash_pbkdf2('sha256', $password, $salt, 210000, 128);
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
