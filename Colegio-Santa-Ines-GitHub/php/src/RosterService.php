<?php
declare(strict_types=1);

namespace SantaInes\Application;

use SantaInes\Domain\SchoolRules;
use SantaInes\Http\HttpException;
use SantaInes\Infrastructure\DataStore;
use SantaInes\Infrastructure\SpreadsheetReader;

/** Casos de uso da lista escolar e do processo anual de progressão. */
final class RosterService
{
    public static function current(): array
    {
        $roster = DataStore::read('roster', ['students' => [], 'count' => 0, 'importedAt' => null]);
        $roster['students'] = array_map(static function (array $student): array {
            try {
                return array_merge($student, SchoolRules::classifyClass($student['className'] ?? ''));
            } catch (\Throwable) {
                return $student;
            }
        }, is_array($roster['students'] ?? null) ? $roster['students'] : []);
        $roster['count'] = count($roster['students']);
        return $roster;
    }

    public static function login(array $payload): array
    {
        $enrollment = trim((string)($payload['enrollment'] ?? ''));
        $name = trim((string)preg_replace('/\s+/u', ' ', (string)($payload['name'] ?? '')));
        foreach (self::current()['students'] as $student) {
            if (($student['enrollment'] ?? '') === $enrollment && SchoolRules::normalizeName($student['name'] ?? '') === SchoolRules::normalizeName($name)) {
                return [
                    'authenticated' => true,
                    'student' => [
                        'name' => $student['name'],
                        'enrollment' => $student['enrollment'],
                        'className' => $student['className'],
                        'grade' => $student['grade'],
                        'gradeId' => $student['gradeId'],
                        'gradeLabel' => $student['gradeLabel'],
                        'educationStage' => $student['educationStage'],
                        'educationStageLabel' => $student['educationStageLabel'],
                    ],
                ];
            }
        }
        throw new HttpException(401, 'Matrícula e nome do estudante não conferem com a lista cadastrada. Confira os dados com a escola.');
    }

    public static function import(string $bytes, string $format): array
    {
        if (strlen($bytes) > 25 * 1024 * 1024) {
            throw new HttpException(413, 'A planilha é maior que o limite de 25 MB.');
        }
        if (!in_array(strtolower($format), ['xlsx', 'csv'], true)) {
            throw new HttpException(415, 'Envie uma planilha Excel .xlsx ou CSV exportado do Excel.');
        }
        $rows = SpreadsheetReader::parse($bytes, $format);
        $students = SchoolRules::validateRosterRows($rows);
        $roster = DataStore::saveRoster($students, [
            'academicYear' => (int)date('Y'),
            'availableClasses' => [],
            'availableClassesYear' => null,
            'lastRolloverYear' => null,
        ]);
        return ['imported' => true, 'count' => $roster['count'], 'importedAt' => $roster['importedAt']];
    }

    public static function page(array $query): array
    {
        $roster = self::current();
        $search = SchoolRules::normalizeName(substr((string)($query['q'] ?? ''), 0, 100));
        $students = $roster['students'];
        if ($search !== '') {
            $students = array_values(array_filter($students, static function (array $student) use ($search): bool {
                $haystack = implode(' ', [
                    $student['enrollment'] ?? '', $student['name'] ?? '', $student['className'] ?? '',
                    $student['educationStageLabel'] ?? '', $student['gradeLabel'] ?? '',
                ]);
                return str_contains(SchoolRules::normalizeName($haystack), $search);
            }));
        }
        $pageSize = 100;
        $pages = max(1, (int)ceil(count($students) / $pageSize));
        $page = min($pages, max(1, (int)($query['page'] ?? 1)));
        return [
            'students' => array_slice($students, ($page - 1) * $pageSize, $pageSize),
            'total' => count($students), 'page' => $page, 'pages' => $pages, 'pageSize' => $pageSize,
        ];
    }

    public static function saveStudent(array $payload): array
    {
        $enrollment = trim((string)($payload['enrollment'] ?? ''));
        $original = trim((string)($payload['originalEnrollment'] ?? ''));
        $name = trim((string)preg_replace('/\s+/u', ' ', (string)($payload['name'] ?? '')));
        if (!preg_match('/^\d{8}$/', $enrollment)) {
            throw new HttpException(400, 'A matrícula deve conter exatamente 8 números.');
        }
        if ($name === '' || (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name)) > 160) {
            throw new HttpException(400, 'Informe o nome do estudante (até 160 caracteres).');
        }
        try {
            $classification = SchoolRules::classifyClass($payload['className'] ?? '');
        } catch (\InvalidArgumentException $error) {
            throw new HttpException(400, $error->getMessage());
        }
        $roster = self::current();
        $index = null;
        foreach ($roster['students'] as $position => $student) {
            if ($student['enrollment'] === $enrollment && $student['enrollment'] !== $original) {
                throw new HttpException(409, 'Essa matrícula já está cadastrada.');
            }
            if ($original !== '' && $student['enrollment'] === $original) {
                $index = $position;
            }
        }
        if ($original !== '' && $index === null) {
            throw new HttpException(404, 'O estudante que você tentou editar não foi encontrado. Atualize a lista e tente novamente.');
        }
        if ($index === null && count($roster['students']) >= 20000) {
            throw new HttpException(400, 'A lista já atingiu o limite de 20.000 estudantes.');
        }
        $student = ['enrollment' => $enrollment, 'name' => $name] + $classification;
        if ($index === null) {
            $roster['students'][] = $student;
        } else {
            $roster['students'][$index] = $student;
        }
        $saved = DataStore::saveRoster($roster['students'], [
            'academicYear' => $roster['academicYear'] ?? (int)date('Y'),
            'availableClasses' => $roster['availableClasses'] ?? [],
            'availableClassesYear' => $roster['availableClassesYear'] ?? null,
            'lastRolloverYear' => $roster['lastRolloverYear'] ?? null,
        ]);
        return ['saved' => true, 'student' => $student, 'count' => $saved['count'], 'importedAt' => $saved['importedAt']];
    }

    public static function removeAll(string $confirmation): array
    {
        if (!hash_equals('CONFIRMAR', $confirmation)) {
            throw new HttpException(400, 'Digite CONFIRMAR para remover os estudantes.');
        }
        $roster = self::current();
        $deleted = count($roster['students']);
        $saved = DataStore::saveRoster([], [
            'academicYear' => $roster['academicYear'] ?? (int)date('Y'),
            'availableClasses' => $roster['availableClasses'] ?? [],
            'availableClassesYear' => $roster['availableClassesYear'] ?? null,
            'lastRolloverYear' => $roster['lastRolloverYear'] ?? null,
        ]);
        return ['deleted' => $deleted, 'count' => $saved['count'], 'importedAt' => $saved['importedAt']];
    }

    public static function yearPlan(): array
    {
        $roster = self::current();
        $currentYear = (int)($roster['academicYear'] ?? date('Y')) ?: (int)date('Y');
        $targetYear = $currentYear + 1;
        $suggestions = array_values(array_unique(array_map(static fn(array $student) => SchoolRules::promotedClass((string)$student['className']), $roster['students'])));
        usort($suggestions, static function (string $a, string $b): int {
            $left = SchoolRules::classifyClass($a);
            $right = SchoolRules::classifyClass($b);
            return $left['grade'] <=> $right['grade'] ?: strnatcasecmp($a, $b);
        });
        $classes = (int)($roster['availableClassesYear'] ?? 0) === $targetYear && is_array($roster['availableClasses'] ?? null)
            ? $roster['availableClasses'] : $suggestions;
        $students = $roster['students'];
        usort($students, static fn(array $a, array $b) => SchoolRules::yearRank($a) <=> SchoolRules::yearRank($b) ?: strcasecmp($a['name'], $b['name']));
        foreach ($students as &$student) {
            $student['suggestedClass'] = SchoolRules::promotedClass((string)$student['className']);
        }
        unset($student);
        return [
            'currentYear' => $currentYear, 'targetYear' => $targetYear,
            'importedAt' => $roster['importedAt'] ?? null,
            'lastRolloverYear' => $roster['lastRolloverYear'] ?? null,
            'availableClasses' => $classes, 'suggestedClasses' => $suggestions, 'students' => $students,
        ];
    }

    public static function saveYearClasses(array $payload): array
    {
        $roster = self::current();
        $currentYear = (int)($roster['academicYear'] ?? date('Y')) ?: (int)date('Y');
        $targetYear = $currentYear + 1;
        if ((int)($payload['targetYear'] ?? 0) !== $targetYear) {
            throw new HttpException(409, 'O ano letivo mudou. Atualize a página e revise as turmas.');
        }
        if (!empty($payload['expectedImportedAt']) && $payload['expectedImportedAt'] !== ($roster['importedAt'] ?? null)) {
            throw new HttpException(409, 'A lista de estudantes foi alterada. Atualize a página e revise a configuração.');
        }
        $input = $payload['availableClasses'] ?? null;
        if (!is_array($input) || count($input) > 200) {
            throw new HttpException(400, 'Informe até 200 turmas disponíveis.');
        }
        $classes = [];
        foreach ($input as $value) {
            $classes[] = SchoolRules::classifyClass($value)['className'];
        }
        $classes = array_values(array_unique($classes));
        if (!$classes && count($roster['students'])) {
            throw new HttpException(400, 'Cadastre pelo menos uma turma para o próximo ano.');
        }
        $saved = DataStore::saveRoster($roster['students'], [
            'academicYear' => $currentYear, 'availableClasses' => $classes,
            'availableClassesYear' => $targetYear, 'lastRolloverYear' => $roster['lastRolloverYear'] ?? null,
        ]);
        return ['saved' => true, 'targetYear' => $targetYear, 'availableClasses' => $classes, 'importedAt' => $saved['importedAt']];
    }

    public static function applyYearTransition(array $payload): array
    {
        $roster = self::current();
        $currentYear = (int)($roster['academicYear'] ?? date('Y')) ?: (int)date('Y');
        $targetYear = $currentYear + 1;
        if ((int)($payload['targetYear'] ?? 0) !== $targetYear) {
            throw new HttpException(409, 'O ano letivo mudou. Atualize a página antes de confirmar.');
        }
        if ((int)($roster['lastRolloverYear'] ?? 0) === $targetYear) {
            throw new HttpException(409, 'A passagem para ' . $targetYear . ' já foi aplicada.');
        }
        if (empty($payload['expectedImportedAt']) || $payload['expectedImportedAt'] !== ($roster['importedAt'] ?? null)) {
            throw new HttpException(409, 'A lista de estudantes foi alterada. Atualize e confira as alterações antes de confirmar.');
        }
        if ((int)($roster['availableClassesYear'] ?? 0) !== $targetYear || !is_array($roster['availableClasses'] ?? null)) {
            throw new HttpException(400, 'Salve primeiro as turmas existentes para o próximo ano.');
        }
        $selectedList = $payload['promoteEnrollments'] ?? null;
        if (!is_array($selectedList) || count($selectedList) > 20000 || count(array_unique($selectedList)) !== count($selectedList)) {
            throw new HttpException(400, 'A seleção de estudantes é inválida ou contém matrículas repetidas.');
        }
        $studentByEnrollment = [];
        foreach ($roster['students'] as $student) {
            $studentByEnrollment[(string)$student['enrollment']] = $student;
        }
        if (count($studentByEnrollment) !== count($roster['students'])) {
            throw new HttpException(409, 'A lista contém matrículas repetidas. Corrija a lista antes da passagem de ano.');
        }
        foreach ($selectedList as $enrollment) {
            if (!isset($studentByEnrollment[(string)$enrollment])) {
                throw new HttpException(409, 'A seleção contém estudante que não está mais na lista atual.');
            }
        }
        $available = [];
        foreach ($roster['availableClasses'] as $class) {
            $available[SchoolRules::classifyClass($class)['className']] = true;
        }
        $placements = is_array($payload['placements'] ?? null) ? $payload['placements'] : [];
        $selected = array_fill_keys(array_map('strval', $selectedList), true);
        $students = [];
        foreach ($roster['students'] as $student) {
            $enrollment = (string)$student['enrollment'];
            if (!isset($selected[$enrollment])) {
                $students[] = $student;
                continue;
            }
            $destination = SchoolRules::classifyClass($placements[$enrollment] ?? SchoolRules::promotedClass((string)$student['className']))['className'];
            if (!isset($available[$destination])) {
                throw new HttpException(400, 'A turma ' . $destination . ' não está cadastrada como existente em ' . $targetYear . '.');
            }
            $students[] = ['enrollment' => $student['enrollment'], 'name' => $student['name']] + SchoolRules::classifyClass($destination);
        }
        $saved = DataStore::saveRoster($students, [
            'academicYear' => $targetYear,
            'availableClasses' => array_keys($available),
            'availableClassesYear' => $targetYear,
            'lastRolloverYear' => $targetYear,
        ]);
        return [
            'applied' => true, 'count' => $saved['count'], 'promoted' => count($selected),
            'unchanged' => $saved['count'] - count($selected), 'academicYear' => $targetYear,
            'importedAt' => $saved['importedAt'],
        ];
    }
}
