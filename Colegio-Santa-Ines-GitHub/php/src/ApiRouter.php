<?php
declare(strict_types=1);

namespace SantaInes\Http;

use SantaInes\Application\ActivityService;
use SantaInes\Application\ContentService;
use SantaInes\Application\RosterService;
use SantaInes\Security\AdminAuth;
use SantaInes\Security\TeacherAuth;
use SantaInes\Infrastructure\DataStore;
use SantaInes\Infrastructure\SpreadsheetReader;

/** Tabela central de rotas HTTP. Cada rota delega regras de negócio aos serviços. */
final class ApiRouter
{
    public static function dispatch(): never
    {
        $method = Http::method();
        $path = Http::path();
        try {
            if ($method === 'POST' && $path === '/api/student/login') {
                self::rateLimit('student', 6, 60);
                Http::json(200, RosterService::login(Http::jsonBody()));
            }

            if ($method === 'POST' && $path === '/api/teacher/login') {
                self::rateLimit('teacher-login', 6, 60);
                $payload = Http::jsonBody();
                $email = strtolower(trim((string)($payload['email'] ?? '')));
                $password = (string)($payload['password'] ?? '');
                $teacher = filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($password) <= 256 ? TeacherAuth::login($email, $password) : null;
                if (!$teacher) Http::fail(401, 'E-mail institucional ou senha incorretos.');
                Http::json(200, ['authenticated'=>true,'teacher'=>$teacher]);
            }
            if ($method === 'POST' && $path === '/api/teacher/password') {
                $payload = Http::jsonBody();
                Http::json(200, TeacherAuth::changePassword((string)($payload['password'] ?? ''), (string)($payload['confirmation'] ?? '')));
            }
            if ($method === 'POST' && $path === '/api/teacher/logout') {
                TeacherAuth::logout();
                Http::json(200, ['authenticated'=>false]);
            }
            if ($method === 'GET' && $path === '/api/teacher/content') {
                $teacher = TeacherAuth::current();
                if (!$teacher) Http::fail(401, 'Entre na Área Equipe Professores.');
                if ($teacher['mustChangePassword']) Http::fail(403, 'Crie sua nova senha para acessar os materiais.');
                $full = DataStore::read('content', null);
                if (!is_array($full)) Http::json(200, ['configured'=>false,'content'=>null]);
                $assigned = array_flip($teacher['platformIds']);
                $full['platforms'] = array_values(array_map(static function(array $platform): array {
                    $platform['teacherPdfs'] = array_values($platform['teacherPdfs'] ?? []);
                    $platform['teacherVideos'] = array_values($platform['teacherVideos'] ?? []);
                    return $platform;
                }, array_filter($full['platforms'] ?? [], static fn(array $platform): bool => isset($assigned[$platform['id'] ?? '']))));
                Http::json(200, ['configured'=>true,'content'=>$full,'teacher'=>['email'=>$teacher['email'],'name'=>$teacher['name']]]);
            }
            if ($method === 'GET' && $path === '/api/admin/teachers') {
                self::requireAdmin();
                Http::json(200, ['teachers'=>TeacherAuth::list()]);
            }
            if ($method === 'POST' && $path === '/api/admin/teachers') {
                self::requireAdmin();
                $payload = Http::jsonBody();
                $platformIds = $payload['platformIds'] ?? [];
                $known = array_column(DataStore::read('content', [])['platforms'] ?? [], 'id');
                if (!is_array($platformIds) || array_diff($platformIds, $known)) Http::fail(400, 'Selecione plataformas cadastradas.');
                Http::json(200, TeacherAuth::saveTeacher((string)($payload['email'] ?? ''), (string)($payload['name'] ?? ''), $platformIds));
            }
            if ($method === 'POST' && $path === '/api/admin/teachers/reset') {
                self::requireAdmin();
                $payload = Http::jsonBody();
                Http::json(200, TeacherAuth::resetPassword((string)($payload['email'] ?? '')));
            }

            if ($method === 'GET' && $path === '/api/content') {
                Http::json(200, ContentService::get(AdminAuth::current() !== null));
            }
            if ($method === 'PUT' && $path === '/api/admin/content') {
                self::requireAdmin();
                Http::json(200, ContentService::save(Http::jsonBody(100 * 1024 * 1024)));
            }
            if ($method === 'PUT' && $path === '/api/admin/store-icons') {
                self::requireAdmin();
                Http::json(200, ContentService::saveStoreIcons(Http::jsonBody(26 * 1024 * 1024)));
            }

            if ($method === 'GET' && $path === '/api/session') {
                $admin = AdminAuth::current();
                $teacher = TeacherAuth::current();
                Http::json(200, ['authenticated' => $admin !== null, 'name' => $admin['name'] ?? null, 'teacherAuthenticated'=>$teacher !== null,'teacher'=>$teacher]);
            }
            if ($method === 'POST' && $path === '/api/login') {
                self::rateLimit('admin-login', 6, 60);
                $payload = Http::jsonBody();
                $email = strtolower(trim((string)($payload['email'] ?? '')));
                $password = (string)($payload['password'] ?? '');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) > 256 || !AdminAuth::login($email, $password)) {
                    Http::fail(401, 'E-mail ou senha incorretos. Se a conta ainda usa a senha da versão Node.js, faça a migração indicada no README.');
                }
                $admin = AdminAuth::current();
                Http::json(200, ['authenticated' => true, 'name' => $admin['name'] ?? '']);
            }
            if ($method === 'POST' && $path === '/api/logout') {
                AdminAuth::logout();
                Http::json(200, ['authenticated' => false]);
            }

            if ($method === 'POST' && $path === '/api/password-reset/request') {
                self::rateLimit('reset-request', 3, 900);
                $email = strtolower(trim((string)(Http::jsonBody()['email'] ?? '')));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    Http::fail(400, 'Informe um e-mail válido.');
                }
                try {
                    AdminAuth::requestRecovery($email);
                } catch (\Throwable) {
                    Http::fail(503, 'O envio de recuperação ainda não está disponível. Avise a equipe responsável pelo sistema.');
                }
                Http::json(200, ['sent' => true, 'message' => 'Se o e-mail estiver cadastrado, você receberá um código de recuperação.']);
            }
            if ($path === '/api/password-reset/local' && $method === 'GET') {
                if (!AdminAuth::allowLocalReset()) {
                    Http::fail(404, 'Recuperação local indisponível.');
                }
                Http::json(200, ['available' => true]);
            }
            if ($path === '/api/password-reset/local' && $method === 'POST') {
                if (!AdminAuth::allowLocalReset() || !self::sameOrigin()) {
                    Http::fail(404, 'Recuperação local indisponível.');
                }
                $payload = Http::jsonBody();
                AdminAuth::completeLocalReset((string)($payload['password'] ?? ''), (string)($payload['confirmation'] ?? ''));
                Http::json(200, ['reset' => true, 'message' => 'Senha atualizada. Entre com sua nova senha.']);
            }
            if ($method === 'POST' && $path === '/api/password-reset/complete') {
                self::rateLimit('reset-complete', 8, 900);
                $payload = Http::jsonBody();
                $email = strtolower(trim((string)($payload['email'] ?? '')));
                $code = trim((string)($payload['code'] ?? ''));
                $password = (string)($payload['password'] ?? '');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || strlen($password) > 256) {
                    Http::fail(400, 'Código inválido ou expirado. Solicite um novo código e tente novamente.');
                }
                if (!AdminAuth::completeRecovery($email, $code, $password)) {
                    Http::fail(400, 'Código inválido ou expirado. Solicite um novo código e tente novamente.');
                }
                Http::json(200, ['reset' => true, 'message' => 'Senha atualizada. Entre com sua nova senha.']);
            }

            if ($method === 'POST' && $path === '/api/admin/roster/import') {
                self::requireAdmin();
                $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
                if ($length > 25 * 1024 * 1024) {
                    Http::fail(413, 'A planilha é maior que o limite de 25 MB.');
                }
                $bytes = file_get_contents('php://input');
                $format = strtolower((string)($_SERVER['HTTP_X_ROSTER_FORMAT'] ?? ''));
                Http::json(200, RosterService::import(is_string($bytes) ? $bytes : '', $format));
            }
            if ($method === 'GET' && $path === '/api/admin/roster') {
                self::requireAdmin();
                $roster = RosterService::current();
                Http::json(200, ['count' => $roster['count'], 'importedAt' => $roster['importedAt'] ?? null]);
            }
            if ($path === '/api/admin/roster/students' && $method === 'GET') {
                self::requireAdmin();
                Http::json(200, RosterService::page(Http::query()));
            }
            if ($path === '/api/admin/roster/students' && $method === 'DELETE') {
                self::requireAdmin();
                $payload = Http::jsonBody();
                Http::json(200, RosterService::removeAll((string)($payload['confirmation'] ?? '')));
            }
            if ($method === 'POST' && $path === '/api/admin/roster/student') {
                self::requireAdmin();
                Http::json(200, RosterService::saveStudent(Http::jsonBody()));
            }
            if ($method === 'GET' && $path === '/api/admin/roster/year-plan') {
                self::requireAdmin();
                Http::json(200, RosterService::yearPlan());
            }
            if ($method === 'PUT' && $path === '/api/admin/roster/year-plan/classes') {
                self::requireAdmin();
                Http::json(200, RosterService::saveYearClasses(Http::jsonBody(1024 * 1024)));
            }
            if ($method === 'POST' && $path === '/api/admin/roster/year-plan/apply') {
                self::requireAdmin();
                Http::json(200, RosterService::applyYearTransition(Http::jsonBody(5 * 1024 * 1024)));
            }

            if ($method === 'POST' && $path === '/api/activities') {
                Http::json(201, ActivityService::create(Http::jsonBody(64 * 1024)));
            }
            if ($method === 'PATCH' && preg_match('#^/api/activities/([^/]+)/exit$#', $path, $matches)) {
                Http::json(200, ActivityService::finish($matches[1]));
            }
            if ($method === 'GET' && $path === '/api/admin/activities') {
                self::requireAdmin();
                Http::json(200, ActivityService::all());
            }
            if ($method === 'DELETE' && $path === '/api/admin/activities') {
                self::requireAdmin();
                $payload = Http::jsonBody();
                Http::json(200, ActivityService::deleteAll((string)($payload['confirmation'] ?? '')));
            }

            Http::fail(404, 'Rota não encontrada.');
        } catch (HttpException $error) {
            Http::fail($error->status, $error->getMessage());
        } catch (\InvalidArgumentException $error) {
            Http::fail(400, $error->getMessage());
        } catch (\Throwable) {
            Http::fail(500, 'O servidor não conseguiu concluir a solicitação. Consulte os registros do servidor.');
        }
    }

    private static function requireAdmin(): void
    {
        if (AdminAuth::current() === null) {
            Http::adminRequired();
        }
    }

    private static function rateLimit(string $action, int $limit, int $window): void
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $key = $action . ':' . hash('sha256', $ip);
        if (!AdminAuth::allowAttempt($key, $limit, $window)) {
            Http::fail(429, 'Muitas tentativas. Aguarde e tente novamente.');
        }
    }

    private static function sameOrigin(): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        return $origin !== '' && strtolower((string)parse_url($origin, PHP_URL_HOST)) === strtolower(preg_replace('/:\d+$/', '', $host) ?? $host);
    }
}
