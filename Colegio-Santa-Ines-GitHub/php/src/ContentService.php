<?php
declare(strict_types=1);

namespace SantaInes\Application;

use SantaInes\Infrastructure\DataStore;
use SantaInes\Http\HttpException;

/** Leitura e validação de dados compartilhados do catálogo escolar. */
final class ContentService
{
    public static function get(bool $includeTeacherMaterials = false): array
    {
        $content = DataStore::read('content', null);
        if (!is_array($content)) {
            return ['configured' => false, 'content' => null, 'revision' => null];
        }
        if (!$includeTeacherMaterials) {
            foreach ($content['platforms'] ?? [] as &$platform) unset($platform['teacherPdfs'], $platform['teacherVideos']);
            unset($platform);
        }
        $revision = hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        header('ETag: ' . $revision);
        if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $revision) {
            http_response_code(304);
            header('Cache-Control: no-store');
            exit;
        }
        return ['configured' => true, 'content' => $content, 'revision' => $revision];
    }

    public static function save(array $payload): array
    {
        $support = trim((string)($payload['support'] ?? ''));
        $platforms = $payload['platforms'] ?? null;
        $storeIcons = is_array($payload['storeIcons'] ?? null) ? $payload['storeIcons'] : ['play' => '', 'apple' => '', 'youtube' => ''];
        if (!filter_var($support, FILTER_VALIDATE_EMAIL) || !is_array($platforms) || count($platforms) > 100) {
            throw new HttpException(400, 'Confira o e-mail e as plataformas antes de salvar.');
        }
        foreach (['play', 'apple', 'youtube'] as $key) {
            $icon = $storeIcons[$key] ?? '';
            // O Base64 aumenta o tamanho do PNG em aproximadamente um terço.
            if ($icon !== '' && (!is_string($icon) || strlen($icon) > 8 * 1024 * 1024 || !preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/]+=*$/', $icon))) {
                throw new HttpException(400, 'Cada ícone deve ser PNG e ter até 6 MB.');
            }
        }
        $allowedStages = ['anos-iniciais', 'anos-finais', 'ensino-medio'];
        $allowedGrades = [];
        foreach ([1, 2, 3, 4, 5] as $grade) $allowedGrades[] = 'anos-iniciais-' . $grade;
        foreach ([6, 7, 8, 9] as $grade) $allowedGrades[] = 'anos-finais-' . $grade;
        foreach ([1, 2, 3] as $grade) $allowedGrades[] = 'ensino-medio-' . $grade;

        foreach ($platforms as $platform) {
            if (!is_array($platform) || trim((string)($platform['name'] ?? '')) === '' || !is_string($platform['summary'] ?? null) || !is_array($platform['pdfs'] ?? null) || !is_array($platform['videos'] ?? null)) {
                throw new HttpException(400, 'Há uma plataforma incompleta. Revise os campos e tente novamente.');
            }
            foreach ($platform['videos'] as $video) {
                if (!is_array($video) || !is_string($video['url'] ?? null) || !self::isYouTubeVideoUrl($video['url']) || array_key_exists('data', $video)) {
                    throw new HttpException(400, 'Os vídeos da plataforma ' . $platform['name'] . ' devem ser links HTTPS de vídeos do YouTube.');
                }
            }
            foreach (['teacherPdfs' => 'PDFs dos professores', 'teacherVideos' => 'vídeos dos professores'] as $field => $label) {
                if (!isset($platform[$field])) continue;
                if (!is_array($platform[$field]) || count($platform[$field]) > 300) throw new HttpException(400, 'Confira os ' . $label . ' da plataforma ' . $platform['name'] . '.');
                foreach ($platform[$field] as $material) {
                    if (!is_array($material) || !is_string($material['name'] ?? null) || !is_string($material['id'] ?? null)) throw new HttpException(400, 'Há um material de professor inválido na plataforma ' . $platform['name'] . '.');
                    if ($field === 'teacherPdfs' && !preg_match('/^data:application\/pdf;base64,[A-Za-z0-9+\/=]+$/', (string)($material['data'] ?? ''))) throw new HttpException(400, 'Os materiais exclusivos de professores devem ser arquivos PDF.');
                    if ($field === 'teacherVideos' && (!is_string($material['url'] ?? null) || !self::isYouTubeVideoUrl($material['url']))) throw new HttpException(400, 'Os materiais de vídeo para professores devem ser links do YouTube.');
                }
            }
            if (array_key_exists('stages', $platform) && (!is_array($platform['stages']) || !$platform['stages'] || array_diff($platform['stages'], $allowedStages))) {
                throw new HttpException(400, 'Selecione ao menos uma etapa válida para ' . $platform['name'] . '.');
            }
            if (array_key_exists('grades', $platform) && (!is_array($platform['grades']) || !$platform['grades'] || array_diff($platform['grades'], $allowedGrades))) {
                throw new HttpException(400, 'Selecione ao menos uma série válida para ' . $platform['name'] . '.');
            }
        }
        $content = [
            'support' => $support,
            'platforms' => array_values($platforms),
            'storeIcons' => $storeIcons,
            'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        DataStore::write('content', $content);
        return ['saved' => true, 'revision' => hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))];
    }

    /** Atualiza apenas os ícones globais, preservando plataformas e tutoriais. */
    public static function saveStoreIcons(array $payload): array
    {
        $content = DataStore::read('content', null);
        if (!is_array($content)) {
            throw new HttpException(409, 'Salve primeiro o conteúdo das plataformas antes de configurar os ícones.');
        }
        $icons = $payload['storeIcons'] ?? null;
        if (!is_array($icons)) {
            throw new HttpException(400, 'Não recebemos as imagens dos ícones.');
        }
        $normalized = [];
        foreach (['play', 'apple', 'youtube'] as $key) {
            $icon = $icons[$key] ?? '';
            if (!is_string($icon) || strlen($icon) > 8 * 1024 * 1024 || ($icon !== '' && !preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/]+=*$/', $icon))) {
                throw new HttpException(400, 'Cada ícone deve ser PNG e ter até 6 MB.');
            }
            $normalized[$key] = $icon;
        }
        $content['storeIcons'] = $normalized;
        $content['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        DataStore::write('content', $content);
        return ['saved' => true, 'revision' => hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))];
    }

    private static function isYouTubeVideoUrl(string $value): bool
    {
        $parts = parse_url($value);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');
        if ($host === 'youtu.be') return strlen($path) > 1;
        if (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
            parse_str((string)($parts['query'] ?? ''), $query);
            return ($path === '/watch' && !empty($query['v'])) || preg_match('~^/(shorts|embed|live)/[^/]+~', $path) === 1;
        }
        if (in_array($host, ['youtube-nocookie.com', 'www.youtube-nocookie.com'], true)) {
            return preg_match('~^/embed/[^/]+~', $path) === 1;
        }
        return false;
    }
}
