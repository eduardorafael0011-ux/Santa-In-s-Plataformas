<?php
declare(strict_types=1);

namespace SantaInes\Infrastructure;

/** Leitores simples de CSV e da primeira aba de arquivos Excel .xlsx. */
final class SpreadsheetReader
{
    public static function parse(string $bytes, string $format): array
    {
        return strtolower($format) === 'xlsx' ? self::xlsx($bytes) : self::csv($bytes);
    }

    private static function csv(string $bytes): array
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Não foi possível preparar a leitura do CSV.');
        }
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $bytes) ?? $bytes);
        rewind($stream);
        $firstLine = fgets($stream) ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
            $rows[] = array_slice(array_pad($row, 3, ''), 0, 3);
        }
        fclose($stream);
        return $rows;
    }

    private static function xlsx(string $bytes): array
    {
        if (!class_exists(\ZipArchive::class) || !class_exists(\DOMDocument::class)) {
            throw new \RuntimeException('A leitura de Excel exige as extensões PHP zip e dom.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'santa-xlsx-');
        if ($temporary === false || file_put_contents($temporary, $bytes) === false) {
            throw new \RuntimeException('Não foi possível preparar a planilha Excel.');
        }

        $zip = new \ZipArchive();
        try {
            if ($zip->open($temporary) !== true) {
                throw new \InvalidArgumentException('Planilha Excel inválida.');
            }
            $workbook = self::xml($zip->getFromName('xl/workbook.xml'));
            $relationships = self::xml($zip->getFromName('xl/_rels/workbook.xml.rels'));
            $sheetNodes = $workbook->getElementsByTagName('sheet');
            if ($sheetNodes->length === 0) {
                throw new \InvalidArgumentException('A planilha não contém uma aba de dados.');
            }
            $relationshipId = '';
            foreach ($sheetNodes->item(0)->attributes as $attribute) {
                if ($attribute->localName === 'id') {
                    $relationshipId = $attribute->nodeValue;
                    break;
                }
            }
            $target = '';
            foreach ($relationships->getElementsByTagName('Relationship') as $relationship) {
                if ($relationship->getAttribute('Id') === $relationshipId) {
                    $target = $relationship->getAttribute('Target');
                    break;
                }
            }
            if ($target === '' || str_contains($target, '..')) {
                throw new \InvalidArgumentException('Não foi possível localizar a primeira aba da planilha.');
            }
            $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . ltrim($target, './');
            $sheet = self::xml($zip->getFromName($sheetPath));
            $shared = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            if (is_string($sharedXml)) {
                $stringsDocument = self::xml($sharedXml);
                foreach ($stringsDocument->getElementsByTagName('si') as $stringNode) {
                    $shared[] = $stringNode->textContent;
                }
            }

            $byRow = [];
            foreach ($sheet->getElementsByTagName('c') as $cell) {
                $address = $cell->getAttribute('r');
                if (!preg_match('/^([A-Z]+)(\d+)$/', $address, $matches)) {
                    continue;
                }
                $column = self::columnNumber($matches[1]);
                if ($column > 2) {
                    continue;
                }
                $rowIndex = (int)$matches[2];
                $type = $cell->getAttribute('t');
                $valueNode = $cell->getElementsByTagName('v')->item(0);
                $value = $type === 'inlineStr'
                    ? $cell->textContent
                    : ($valueNode?->textContent ?? '');
                if ($type === 's') {
                    $value = $shared[(int)$value] ?? '';
                }
                $byRow[$rowIndex] ??= ['', '', ''];
                $byRow[$rowIndex][$column] = $value;
            }
            ksort($byRow);
            return array_values($byRow);
        } finally {
            $zip->close();
            @unlink($temporary);
        }
    }

    private static function xml(string|false $xml): \DOMDocument
    {
        if (!is_string($xml)) {
            throw new \InvalidArgumentException('Não foi possível ler os dados da planilha Excel.');
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new \InvalidArgumentException('A estrutura da planilha Excel é inválida.');
        }
        return $document;
    }

    private static function columnNumber(string $letters): int
    {
        $number = 0;
        foreach (str_split($letters) as $letter) {
            $number = $number * 26 + ord($letter) - 64;
        }
        return $number - 1;
    }
}
