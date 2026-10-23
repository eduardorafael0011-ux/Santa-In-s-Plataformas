'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const pbkdf2 = require('node:util').promisify(crypto.pbkdf2);

if (!process.stdin.isTTY || typeof process.stdin.setRawMode !== 'function') {
  console.error('Abra este processo em um terminal interativo para redefinir a senha.');
  process.exit(1);
}

const authFile = path.join(__dirname, '.private', 'admins.json');
const targetEmail = String(process.argv[2] || process.env.ADMIN_EMAIL || '').trim().toLowerCase();
if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(targetEmail)) {
  console.error('Uso: node reset-admin.js email@escola.edu.br');
  process.exit(2);
}
function askSecret(label) {
  return new Promise(resolve => {
    let value = '';
    process.stdout.write(label);
    process.stdin.setEncoding('utf8');
    process.stdin.setRawMode(true);
    process.stdin.resume();
    const onData = key => {
      if (key === '\u0003') {
        process.stdin.setRawMode(false);
        process.stdin.off('data', onData);
        process.stdout.write('\nRedefinição cancelada.\n');
        process.exit(130);
      }
      if (key === '\r' || key === '\n') {
        process.stdin.setRawMode(false);
        process.stdin.off('data', onData);
        process.stdout.write('\n');
        resolve(value);
        return;
      }
      if (key === '\u007f' || key === '\b') {
        if (value.length) {
          value = value.slice(0, -1);
          process.stdout.write('\b \b');
        }
        return;
      }
      if (key >= ' ' && key !== '\u007f') {
        value += key;
        process.stdout.write('•');
      }
    };
    process.stdin.on('data', onData);
  });
}

(async () => {
  let admins;
  try { admins = JSON.parse(fs.readFileSync(authFile, 'utf8')); }
  catch { console.error('Não foi possível abrir o cadastro privado da equipe.'); process.exit(1); }
  const admin = admins.find(item => String(item.email || '').toLowerCase() === targetEmail);
  if (!admin) { console.error('A conta da equipe não foi encontrada.'); process.exit(1); }

  console.log('Redefinição segura da senha da equipe TE. A digitação ficará oculta.');
  let first = await askSecret('Nova senha (mínimo 12 caracteres): ');
  while (first.length < 12) {
    first = '';
    console.log('Use uma senha com pelo menos 12 caracteres.');
    first = await askSecret('Nova senha: ');
  }
  const second = await askSecret('Confirme a nova senha: ');
  if (first !== second) {
    first = '';
    console.error('As senhas não coincidem. Nenhuma alteração foi feita.');
    process.exit(1);
  }

  const salt = crypto.randomBytes(16);
  const iterations = 210000;
  const derived = await pbkdf2(first, salt, iterations, 64, 'sha256');
  admin.passwordHash = `pbkdf2_sha256$${iterations}$${salt.toString('hex')}$${derived.toString('hex')}`;
  delete admin.hash;
  delete admin.salt;
  first = '';
  const temporary = authFile + '.tmp';
  fs.writeFileSync(temporary, JSON.stringify(admins), { mode: 0o600 });
  fs.chmodSync(temporary, 0o600);
  fs.renameSync(temporary, authFile);
  fs.chmodSync(authFile, 0o600);
  console.log('Senha redefinida. Entre novamente na central usando sua nova senha.');
  process.exit(0);
})().catch(() => {
  console.error('Não foi possível concluir a redefinição. Nenhuma senha foi exibida.');
  process.exit(1);
});
