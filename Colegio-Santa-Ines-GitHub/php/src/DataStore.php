<?php
declare(strict_types=1);

namespace SantaInes\Infrastructure;

/** Acesso aos arquivos JSON privados. Nenhuma tela deve ler esses arquivos diretamente. */
final class DataStore
{
    public static function directory(): string
    {
        $configured = getenv('SANTA_INES_DATA_DIR');
        if (is_string($configured) && $configured !== '') {
            if (!str_starts_with($configured, DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException('SANTA_INES_DATA_DIR precisa ser um caminho absoluto.');
            }
            return rtrim($configured, DIRECTORY_SEPARATOR);
        }
        return dirname(__DIR__, 2) . '/.private';
    }

    private static function path(string $name): string
    {
        $files = [
            'admins' => 'admins.json',
            'activities' => 'activities.json',
            'content' => 'site-content.json',
            'roster' => 'student-roster.json',
            'recovery' => 'password-recovery.json',
            'rate_limits' => 'rate-limits.json',
            'teachers' => 'teachers.json',
            'teacher_auth_config' => 'teacher-auth.json',
        ];
        if (!isset($files[$name])) {
            throw new \InvalidArgumentException('Arquivo de dados desconhecido.');
        }
        return self::directory() . '/' . $files[$name];
    }

    /** Lê JSON privado. Arquivos ausentes retornam o valor padrão. */
    public static function read(string $name, mixed $default): mixed
    {
        $path = self::path($name);
        if (!is_file($path)) {
            return $default;
        }
        $json = file_get_contents($path);
        $value = is_string($json) ? json_decode($json, true) : null;
        return json_last_error() === JSON_ERROR_NONE ? $value : $default;
    }

    /** Grava um arquivo temporário e o renomeia para evitar JSON parcialmente escrito. */
    public static function write(string $name, mixed $value): void
    {
        $directory = self::directory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Não foi possível criar a pasta privada de dados.');
        }
        @chmod($directory, 0700);

        $path = self::path($name);
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Não foi possível gravar os dados privados.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Não foi possível salvar os dados privados.');
        }
        @chmod($path, 0600);
    }

    /** Salva a lista ativa sem apagar metadados do ano letivo não informados. */
    public static function saveRoster(array $students, array $metadata = []): array
    {
        $previous = self::read('roster', ['students' => [], 'count' => 0]);
        if (!is_array($previous)) {
            $previous = ['students' => [], 'count' => 0];
        }
        $roster = array_merge($previous, $metadata, [
            'students' => array_values($students),
            'count' => count($students),
            'importedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        self::write('roster', $roster);
        return $roster;
    }
}
