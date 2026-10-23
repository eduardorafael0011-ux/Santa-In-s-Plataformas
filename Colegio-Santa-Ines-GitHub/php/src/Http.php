<?php
declare(strict_types=1);

namespace SantaInes\Http;

/** Funções HTTP comuns: leitura segura de JSON, respostas JSON e erros controlados. */
final class Http
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        return is_string($path) ? rawurldecode($path) : '/';
    }

    public static function query(): array
    {
        return $_GET;
    }

    /** Lê JSON do corpo respeitando um limite para evitar uploads excessivos. */
    public static function jsonBody(int $limit = 8192): array
    {
        $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > $limit) {
            throw new HttpException(413, 'O conteúdo enviado excede o limite permitido.');
        }
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || strlen($raw) > $limit) {
            throw new HttpException(413, 'O conteúdo enviado excede o limite permitido.');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new HttpException(400, 'O corpo da solicitação não contém JSON válido.');
        }
        return $data;
    }

    public static function json(int $status, array $data): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function fail(int $status, string $message): never
    {
        self::json($status, ['error' => $message]);
    }

    public static function adminRequired(): never
    {
        self::fail(401, 'Acesso restrito. Entre na área da Equipe TE.');
    }
}

final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
