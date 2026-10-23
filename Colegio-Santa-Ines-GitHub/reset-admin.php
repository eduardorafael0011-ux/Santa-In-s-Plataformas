<?php
declare(strict_types=1);

/** Redefinição local da senha administrativa para migrar a conta ao formato PHP. */
require_once __DIR__ . '/php/src/DataStore.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$email = strtolower(trim($argv[1] ?? (getenv('ADMIN_EMAIL') ?: '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php reset-admin.php email@escola.edu.br\n");
    exit(2);
}

$admins = \SantaInes\Infrastructure\DataStore::read('admins', []);
$index = null;
foreach ($admins as $position => $admin) {
    if (strtolower((string)($admin['email'] ?? '')) === $email) {
        $index = $position;
        break;
    }
}
if ($index === null) {
    fwrite(STDERR, "Conta administrativa não encontrada. Nenhuma alteração foi feita.\n");
    exit(1);
}

/** Lê uma senha sem exibir caracteres no Terminal quando stty estiver disponível. */
function readHidden(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $canHide = PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec');
    if ($canHide) {
        shell_exec('stty -echo');
    }
    try {
        $value = fgets(STDIN);
    } finally {
        if ($canHide) {
            shell_exec('stty echo');
            fwrite(STDOUT, "\n");
        }
    }
    return rtrim((string)$value, "\r\n");
}

fwrite(STDOUT, "Redefinição local para {$email}. A senha será convertida para o formato PHP.\n");
$password = readHidden('Nova senha (mínimo 12 caracteres): ');
$confirmation = readHidden('Confirme a nova senha: ');
if (strlen($password) < 12 || strlen($password) > 256 || !hash_equals($password, $confirmation)) {
    fwrite(STDERR, "Senhas não coincidem ou não atendem ao tamanho mínimo. Nenhuma alteração foi feita.\n");
    exit(1);
}

$salt = bin2hex(random_bytes(16));
$iterations = 210000;
$admins[$index]['passwordHash'] = 'pbkdf2_sha256$' . $iterations . '$' . $salt . '$' . bin2hex(hash_pbkdf2('sha256', $password, hex2bin($salt), $iterations, 64, true));
unset($admins[$index]['hash'], $admins[$index]['salt']);
\SantaInes\Infrastructure\DataStore::write('admins', $admins);
$password = $confirmation = '';
fwrite(STDOUT, "Senha atualizada. A conta agora usa o hash de senha do PHP.\n");
