<?php
declare(strict_types=1);

namespace SantaInes\Domain;

/** Regras do domínio escolar compartilhadas pela importação e pela passagem de ano. */
final class SchoolRules
{
    public const STAGES = [
        'anos-iniciais' => 'Anos Iniciais',
        'anos-finais' => 'Anos Finais',
        'ensino-medio' => 'Ensino Médio',
    ];

    /** Converte o código da turma em série e etapa. Aceita 11–99 e 1A–3Z. */
    public static function classifyClass(mixed $value): array
    {
        $original = trim((string)$value);
        $code = strtoupper((string)preg_replace('/\s+/u', '', $original));
        if (preg_match('/^[1-9][1-9]$/', $code)) {
            $grade = (int)$code[0];
            $stage = $grade <= 5 ? 'anos-iniciais' : 'anos-finais';
        } elseif (preg_match('/^[1-3][A-Z]$/', $code)) {
            $grade = (int)$code[0];
            $stage = 'ensino-medio';
        } else {
            throw new \InvalidArgumentException('Turma inválida. Use códigos numéricos de 11 a 99 ou códigos de 1A a 3Z para o Ensino Médio.');
        }

        return [
            'className' => $code,
            'grade' => $grade,
            'gradeId' => $stage . '-' . $grade,
            'gradeLabel' => $stage === 'ensino-medio' ? $grade . 'º ano do Ensino Médio' : $grade . 'º ano',
            'educationStage' => $stage,
            'educationStageLabel' => self::STAGES[$stage],
        ];
    }

    /** Calcula a turma sugerida para o próximo ano. */
    public static function promotedClass(string $className): string
    {
        $code = self::classifyClass($className)['className'];
        if (preg_match('/^[1-8][1-9]$/', $code)) {
            return ((int)$code[0] + 1) . $code[1];
        }
        if (preg_match('/^9[1-9]$/', $code)) {
            return '1' . chr(64 + (int)$code[1]);
        }
        if (preg_match('/^[12][A-Z]$/', $code)) {
            return ((int)$code[0] + 1) . $code[1];
        }
        return $code;
    }

    /** Normaliza acentos e espaços para comparar nomes sem diferenciar caixa. */
    public static function normalizeName(mixed $value): string
    {
        $name = trim((string)preg_replace('/\s+/u', ' ', (string)$value));
        if (class_exists(\Normalizer::class)) {
            $name = \Normalizer::normalize($name, \Normalizer::FORM_D);
            $name = preg_replace('/\p{Mn}+/u', '', (string)$name) ?? (string)$name;
        } elseif (function_exists('iconv')) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if ($ascii !== false) {
                $name = $ascii;
            }
        }
        return strtolower($name);
    }

    /** Converte linhas A/B/C da planilha em registros validados. */
    public static function validateRosterRows(array $rows): array
    {
        if ($rows && str_contains(self::normalizeName($rows[0][0] ?? ''), 'matricula') && str_contains(self::normalizeName($rows[0][1] ?? ''), 'nome')) {
            array_shift($rows);
        }

        $students = [];
        $seen = [];
        foreach ($rows as $row) {
            $rawEnrollment = trim((string)($row[0] ?? ''));
            $rawEnrollment = preg_replace('/\.0$/', '', $rawEnrollment) ?? $rawEnrollment;
            $name = trim((string)preg_replace('/\s+/u', ' ', (string)($row[1] ?? '')));
            $rawClass = trim((string)($row[2] ?? ''));
            if ($rawEnrollment === '' && $name === '' && $rawClass === '') {
                continue;
            }
            if ($rawEnrollment === '' || !preg_match('/^\d+$/', $rawEnrollment)) {
                throw new \InvalidArgumentException('Cada estudante precisa ter uma matrícula numérica na coluna A.');
            }
            $enrollment = str_pad($rawEnrollment, 8, '0', STR_PAD_LEFT);
            if (!preg_match('/^\d{8}$/', $enrollment) || $name === '' || $rawClass === '') {
                throw new \InvalidArgumentException('Confira a planilha: use matrícula numérica com 8 dígitos, nome e turma nas colunas A, B e C.');
            }
            $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
            if ($nameLength > 160) {
                throw new \InvalidArgumentException('O nome do estudante não pode passar de 160 caracteres.');
            }
            if (isset($seen[$enrollment])) {
                throw new \InvalidArgumentException('A matrícula ' . $enrollment . ' aparece mais de uma vez na planilha.');
            }
            $seen[$enrollment] = true;
            $students[] = ['enrollment' => $enrollment, 'name' => $name] + self::classifyClass($rawClass);
            if (count($students) > 20000) {
                throw new \InvalidArgumentException('A planilha excede o limite de 20.000 estudantes.');
            }
        }
        if (!$students) {
            throw new \InvalidArgumentException('A planilha não contém estudantes para importar.');
        }
        return $students;
    }

    /** Ordenação usada na tela do plano anual. */
    public static function yearRank(array $student): int
    {
        $stageBonus = ($student['educationStage'] ?? '') === 'ensino-medio' ? 9 : 0;
        return $stageBonus + (int)($student['grade'] ?? 0);
    }
}
