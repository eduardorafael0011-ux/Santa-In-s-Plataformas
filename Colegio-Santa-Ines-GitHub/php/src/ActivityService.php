<?php
declare(strict_types=1);

namespace SantaInes\Application;

use SantaInes\Domain\SchoolRules;
use SantaInes\Http\HttpException;
use SantaInes\Infrastructure\DataStore;

/** Registra abertura e saída de páginas, PDFs e vídeos. */
final class ActivityService
{
    public static function create(array $payload): array
    {
        $name = self::limitText(trim((string)($payload['name'] ?? '')), 120);
        $enrollment = trim((string)($payload['enrollment'] ?? ''));
        $platform = self::limitText(trim((string)($payload['platform'] ?? '')), 160);
        $kind = (string)($payload['kind'] ?? '');
        $openedInput = (string)($payload['openedAt'] ?? 'now');
        try {
            $opened = new \DateTimeImmutable($openedInput);
        } catch (\Throwable) {
            throw new HttpException(400, 'Registro inválido.');
        }

        $student = null;
        foreach (RosterService::current()['students'] as $candidate) {
            if (($candidate['enrollment'] ?? '') === $enrollment && SchoolRules::normalizeName($candidate['name'] ?? '') === SchoolRules::normalizeName($name)) {
                $student = $candidate;
                break;
            }
        }
        if (!$student || $platform === '' || !in_array($kind, ['Página', 'PDF', 'Vídeo'], true)) {
            throw new HttpException(400, 'Registro inválido.');
        }
        $items = DataStore::read('activities', []);
        $id = substr((string)($payload['id'] ?? bin2hex(random_bytes(16))), 0, 100);
        foreach ($items as $item) {
            if (($item['id'] ?? '') === $id) {
                return ['id' => $id];
            }
        }
        $items[] = [
            'id' => $id,
            'name' => $student['name'],
            'enrollment' => $student['enrollment'],
            'className' => $student['className'],
            'role' => trim((string)($payload['role'] ?? 'Responsável')),
            'platform' => $platform,
            'kind' => $kind,
            'openedAt' => $opened->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'exitedAt' => null,
            'durationSeconds' => null,
        ];
        DataStore::write('activities', $items);
        return ['id' => $id];
    }

    public static function finish(string $id): array
    {
        $items = DataStore::read('activities', []);
        foreach ($items as &$item) {
            if (($item['id'] ?? '') !== $id) {
                continue;
            }
            if (empty($item['exitedAt'])) {
                $exit = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $item['exitedAt'] = $exit->format('Y-m-d\TH:i:s\Z');
                try {
                    $start = new \DateTimeImmutable((string)$item['openedAt']);
                    $item['durationSeconds'] = max(0, $exit->getTimestamp() - $start->getTimestamp());
                } catch (\Throwable) {
                    $item['durationSeconds'] = null;
                }
                DataStore::write('activities', $items);
            }
            unset($item);
            return ['saved' => true];
        }
        unset($item);
        throw new HttpException(404, 'Registro não encontrado.');
    }

    public static function all(): array
    {
        return ['activities' => DataStore::read('activities', [])];
    }

    public static function deleteAll(string $confirmation): array
    {
        if (!hash_equals('CONFIRMAR', $confirmation)) {
            throw new HttpException(400, 'Digite CONFIRMAR para excluir os registros de atividade.');
        }
        DataStore::write('activities', []);
        return ['deleted' => true];
    }

    private static function limitText(string $value, int $limit): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
    }
}
