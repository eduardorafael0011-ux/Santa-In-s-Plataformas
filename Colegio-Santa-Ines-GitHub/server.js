'use strict';
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const zlib = require('node:zlib');
const { promisify } = require('node:util');
const scrypt = promisify(crypto.scrypt);
const pbkdf2 = promisify(crypto.pbkdf2);

const ROOT = __dirname;
const PRIVATE_DIR = process.env.SANTA_INES_DATA_DIR
  ? path.resolve(process.env.SANTA_INES_DATA_DIR)
  : path.join(ROOT, '.private');
const AUTH_FILE = path.join(PRIVATE_DIR, 'admins.json');
const ACTIVITY_FILE = path.join(PRIVATE_DIR, 'activities.json');
const CONTENT_FILE = path.join(PRIVATE_DIR, 'site-content.json');
const ROSTER_FILE = path.join(PRIVATE_DIR, 'student-roster.json');
const TEACHERS_FILE = path.join(PRIVATE_DIR, 'teachers.json');
const LOCAL_RESET_USED_FILE = path.join(PRIVATE_DIR, 'local-reset-used');
const PORT = Number(process.env.PORT || 3003);
const sessions = new Map();
const teacherSessions = new Map();
const attempts = new Map();
const recoveryRequests = new Map();
const recoveryAttempts = new Map();
let cachedContent;
let cachedRevision = null;
let localResetBusy = false;
const SESSION_MS = 12 * 60 * 60 * 1000;

function loadAdmins() {
  try { return JSON.parse(fs.readFileSync(AUTH_FILE, 'utf8')); }
  catch { return []; }
}
function loadTeachers() {
  try { return JSON.parse(fs.readFileSync(TEACHERS_FILE, 'utf8')); }
  catch { return []; }
}
function teacherInitialPassword() {
  let password = process.env.SANTA_INES_TEACHER_INITIAL_PASSWORD || '';
  if (!password) {
    try { password = JSON.parse(fs.readFileSync(path.join(PRIVATE_DIR, 'teacher-auth.json'), 'utf8')).initialPassword || ''; }
    catch {}
  }
  if (typeof password !== 'string' || password.length < 4 || password.length > 256) {
    const error = new Error('Configure a senha temporária docente no ambiente privado do servidor.');
    error.statusCode = 503;
    throw error;
  }
  return password;
}
function saveTeachers(teachers) {
  fs.mkdirSync(PRIVATE_DIR, { recursive: true, mode: 0o700 });
  const temporary = TEACHERS_FILE + '.tmp';
  fs.writeFileSync(temporary, JSON.stringify(teachers), { mode: 0o600 });
  fs.chmodSync(temporary, 0o600);
  fs.renameSync(temporary, TEACHERS_FILE);
  fs.chmodSync(TEACHERS_FILE, 0o600);
}
function mailConfig() {
  let fileConfig = {};
  try { fileConfig = JSON.parse(fs.readFileSync(path.join(PRIVATE_DIR, 'm365-mail.json'), 'utf8')); }
  catch {}
  return {
    tenantId: process.env.M365_TENANT_ID || fileConfig.tenantId || '',
    clientId: process.env.M365_CLIENT_ID || fileConfig.clientId || '',
    clientSecret: process.env.M365_CLIENT_SECRET || fileConfig.clientSecret || '',
    fromEmail: process.env.RESET_FROM_EMAIL || fileConfig.fromEmail || ''
  };
}
function saveAdmins(admins) {
  fs.mkdirSync(PRIVATE_DIR, { recursive: true, mode: 0o700 });
  const temporary = AUTH_FILE + '.tmp';
  fs.writeFileSync(temporary, JSON.stringify(admins), { mode: 0o600 });
  fs.chmodSync(temporary, 0o600);
  fs.renameSync(temporary, AUTH_FILE);
  fs.chmodSync(AUTH_FILE, 0o600);
}
// Formato compartilhado com o backend PHP para permitir trocar de modo sem trocar a senha.
async function createPasswordHash(password) {
  const salt = crypto.randomBytes(16);
  const iterations = 210000;
  const derived = await pbkdf2(password, salt, iterations, 64, 'sha256');
  return `pbkdf2_sha256$${iterations}$${salt.toString('hex')}$${derived.toString('hex')}`;
}
async function verifyPassword(password, admin) {
  const encoded = String(admin.passwordHash || '');
  const parts = encoded.split('$');
  if (parts.length === 4 && parts[0] === 'pbkdf2_sha256') {
    const iterations = Number(parts[1]);
    if (!Number.isInteger(iterations) || iterations < 100000 || iterations > 1000000 || !/^[a-f0-9]{32,128}$/i.test(parts[2]) || !/^[a-f0-9]{128}$/i.test(parts[3])) return false;
    const candidate = await pbkdf2(password, Buffer.from(parts[2], 'hex'), iterations, 64, 'sha256');
    const expected = Buffer.from(parts[3], 'hex');
    return candidate.length === expected.length && crypto.timingSafeEqual(candidate, expected);
  }
  // Compatibilidade: a conta antiga migra automaticamente após um login válido.
  if (!admin.salt || !admin.hash || !/^[a-f0-9]+$/i.test(admin.salt) || !/^[a-f0-9]+$/i.test(admin.hash)) return false;
  const candidate = await scrypt(password, Buffer.from(admin.salt, 'hex'), 64);
  const expected = Buffer.from(admin.hash, 'hex');
  return candidate.length === expected.length && crypto.timingSafeEqual(candidate, expected);
}
function loadContent() {
  if (cachedContent !== undefined) return cachedContent;
  try { cachedContent = JSON.parse(fs.readFileSync(CONTENT_FILE, 'utf8')); }
  catch { cachedContent = null; }
  return cachedContent;
}
function saveContent(content) {
  fs.mkdirSync(PRIVATE_DIR, { recursive: true, mode: 0o700 });
  const temporary = CONTENT_FILE + '.tmp';
  fs.writeFileSync(temporary, JSON.stringify(content), { mode: 0o600 });
  fs.chmodSync(temporary, 0o600);
  fs.renameSync(temporary, CONTENT_FILE);
  cachedContent = content;
  cachedRevision = crypto.createHash('sha256').update(JSON.stringify(content)).digest('hex');
}
function isYouTubeVideoUrl(value) {
  try {
    const url = new URL(value);
    if (url.protocol !== 'https:' || url.username || url.password) return false;
    const host = url.hostname.toLowerCase(), path = url.pathname;
    if (host === 'youtu.be') return path.length > 1;
    if (['youtube.com', 'www.youtube.com', 'm.youtube.com'].includes(host)) return (path === '/watch' && Boolean(url.searchParams.get('v'))) || /^\/(shorts|embed|live)\/[^/]+/.test(path);
    if (['youtube-nocookie.com', 'www.youtube-nocookie.com'].includes(host)) return /^\/embed\/[^/]+/.test(path);
    return false;
  } catch { return false; }
}
function contentRevision(content) {
  if (content === cachedContent && cachedRevision) return cachedRevision;
  return crypto.createHash('sha256').update(JSON.stringify(content)).digest('hex');
}
function loadActivities() {
  try { return JSON.parse(fs.readFileSync(ACTIVITY_FILE, 'utf8')); }
  catch { return []; }
}
function saveActivities(items) {
  fs.mkdirSync(PRIVATE_DIR, { recursive: true, mode: 0o700 });
  const temporary = ACTIVITY_FILE + '.tmp';
  fs.writeFileSync(temporary, JSON.stringify(items), { mode: 0o600 });
  fs.chmodSync(temporary, 0o600);
  fs.renameSync(temporary, ACTIVITY_FILE);
}
function loadRoster() {
  try {
    const roster = JSON.parse(fs.readFileSync(ROSTER_FILE, 'utf8'));
    const students = Array.isArray(roster.students) ? roster.students.map(student => {
      try { return { ...student, ...classifyClass(student.className) }; }
      catch { return student; }
    }) : [];
    return { ...roster, students, count: students.length };
  }
  catch { return { students: [], importedAt: null, count: 0 }; }
}
function saveRoster(students, metadata = {}) {
  fs.mkdirSync(PRIVATE_DIR, { recursive: true, mode: 0o700 });
  const previous = loadRoster();
  const roster = { ...previous, ...metadata, students, count: students.length, importedAt: new Date().toISOString() };
  const temporary = ROSTER_FILE + '.tmp';
  fs.writeFileSync(temporary, JSON.stringify(roster), { mode: 0o600 });
  fs.chmodSync(temporary, 0o600);
  fs.renameSync(temporary, ROSTER_FILE);
  fs.chmodSync(ROSTER_FILE, 0o600);
  return roster;
}
function decodeXml(value) {
  return String(value || '').replace(/&#x([\da-f]+);/gi, (_, n) => String.fromCodePoint(parseInt(n, 16)))
    .replace(/&#(\d+);/g, (_, n) => String.fromCodePoint(Number(n)))
    .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&apos;/g, "'").replace(/&amp;/g, '&');
}
function unzipEntries(buffer) {
  let eocd = -1;
  for (let i = buffer.length - 22; i >= Math.max(0, buffer.length - 65557); i--) {
    if (buffer.readUInt32LE(i) === 0x06054b50) { eocd = i; break; }
  }
  if (eocd < 0) throw new Error('Planilha Excel inválida.');
  const count = buffer.readUInt16LE(eocd + 10);
  let offset = buffer.readUInt32LE(eocd + 16);
  const files = new Map();
  for (let n = 0; n < count; n++) {
    if (buffer.readUInt32LE(offset) !== 0x02014b50) throw new Error('Planilha Excel inválida.');
    const method = buffer.readUInt16LE(offset + 10);
    const compressedSize = buffer.readUInt32LE(offset + 20);
    const nameLength = buffer.readUInt16LE(offset + 28);
    const extraLength = buffer.readUInt16LE(offset + 30);
    const commentLength = buffer.readUInt16LE(offset + 32);
    const localOffset = buffer.readUInt32LE(offset + 42);
    const name = buffer.toString('utf8', offset + 46, offset + 46 + nameLength);
    const localNameLength = buffer.readUInt16LE(localOffset + 26);
    const localExtraLength = buffer.readUInt16LE(localOffset + 28);
    const dataStart = localOffset + 30 + localNameLength + localExtraLength;
    const compressed = buffer.subarray(dataStart, dataStart + compressedSize);
    if (method === 0) files.set(name, Buffer.from(compressed));
    else if (method === 8) files.set(name, zlib.inflateRawSync(compressed));
    else throw new Error('Formato de compactação da planilha não reconhecido.');
    offset += 46 + nameLength + extraLength + commentLength;
  }
  return files;
}
function parseXlsx(buffer) {
  const files = unzipEntries(buffer);
  const workbook = files.get('xl/workbook.xml')?.toString('utf8');
  const rels = files.get('xl/_rels/workbook.xml.rels')?.toString('utf8');
  if (!workbook || !rels) throw new Error('Não foi possível ler a planilha Excel.');
  const firstSheet = workbook.match(/<sheet\b[^>]*\br:id="([^"]+)"/);
  if (!firstSheet) throw new Error('A planilha não contém uma aba de dados.');
  const rel = [...rels.matchAll(/<Relationship\b([^>]*)\/?\s*>/g)].find(match => new RegExp(`\\bId="${firstSheet[1]}"`).test(match[1]));
  if (!rel) throw new Error('Não foi possível localizar a primeira aba da planilha.');
  const target = rel[1].match(/\bTarget="([^"]+)"/)?.[1];
  if (!target) throw new Error('A primeira aba da planilha não pode ser lida.');
  const sheetPath = target.startsWith('/') ? target.slice(1) : `xl/${target.replace(/^\.\//, '')}`;
  const sheet = files.get(sheetPath)?.toString('utf8');
  if (!sheet) throw new Error('Não foi possível abrir a primeira aba da planilha.');
  const shared = files.get('xl/sharedStrings.xml')?.toString('utf8') || '';
  const strings = [...shared.matchAll(/<si\b[^>]*>([\s\S]*?)<\/si>/g)].map(match => [...match[1].matchAll(/<t\b[^>]*>([\s\S]*?)<\/t>/g)].map(item => decodeXml(item[1])).join(''));
  const rows = new Map();
  for (const match of sheet.matchAll(/<c\b([^>]*)>([\s\S]*?)<\/c>/g)) {
    const address = match[1].match(/\br="([A-Z]+)(\d+)"/);
    if (!address) continue;
    const column = [...address[1]].reduce((number, letter) => number * 26 + letter.charCodeAt(0) - 64, 0) - 1;
    if (column > 2) continue;
    const type = match[1].match(/\bt="([^"]+)"/)?.[1] || '';
    const value = type === 'inlineStr' ? [...match[2].matchAll(/<t\b[^>]*>([\s\S]*?)<\/t>/g)].map(item => decodeXml(item[1])).join('') : decodeXml(match[2].match(/<v\b[^>]*>([\s\S]*?)<\/v>/)?.[1] || '');
    const row = Number(address[2]);
    if (!rows.has(row)) rows.set(row, ['', '', '']);
    rows.get(row)[column] = type === 's' ? (strings[Number(value)] || '') : value;
  }
  return [...rows.entries()].sort((a, b) => a[0] - b[0]).map(([, row]) => row);
}
function parseCsv(buffer) {
  const text = buffer.toString('utf8').replace(/^\uFEFF/, '');
  const delimiter = (text.split(/\r?\n/, 1)[0].match(/;/g) || []).length > (text.split(/\r?\n/, 1)[0].match(/,/g) || []).length ? ';' : ',';
  const rows = []; let row = [], cell = '', quoted = false;
  for (let i = 0; i < text.length; i++) {
    const char = text[i];
    if (char === '"' && quoted && text[i + 1] === '"') { cell += '"'; i++; }
    else if (char === '"') quoted = !quoted;
    else if (char === delimiter && !quoted) { row.push(cell); cell = ''; }
    else if ((char === '\n' || char === '\r') && !quoted) { if (char === '\r' && text[i + 1] === '\n') i++; row.push(cell); rows.push(row); row = []; cell = ''; }
    else cell += char;
  }
  if (cell || row.length) { row.push(cell); rows.push(row); }
  return rows;
}
const EDUCATION_STAGES = {
  anosIniciais: { id: 'anos-iniciais', label: 'Anos Iniciais' },
  anosFinais: { id: 'anos-finais', label: 'Anos Finais' },
  ensinoMedio: { id: 'ensino-medio', label: 'Ensino Médio' }
};
function classifyClass(value) {
  const original = String(value || '').trim();
  const classCode = original.replace(/\s+/g, '').toUpperCase();
  let grade, stage;
  if (/^[1-9][1-9]$/.test(classCode)) {
    grade = Number(classCode[0]);
    stage = grade <= 5 ? EDUCATION_STAGES.anosIniciais : EDUCATION_STAGES.anosFinais;
  } else if (/^[1-3][A-Z]$/.test(classCode)) {
    grade = Number(classCode[0]);
    stage = EDUCATION_STAGES.ensinoMedio;
  } else {
    throw new Error(`A turma "${original || 'vazia'}" não é válida. Use códigos de 11 a 99 (sem zero na turma) para os anos escolares ou 1A a 3Z para o Ensino Médio.`);
  }
  const gradeId = `${stage.id}-${grade}`;
  const gradeLabel = stage.id === EDUCATION_STAGES.ensinoMedio.id ? `${grade}º ano do Ensino Médio` : `${grade}º ano`;
  return { className: classCode, grade, gradeId, gradeLabel, educationStage: stage.id, educationStageLabel: stage.label };
}
function promotedClass(value) {
  const info = classifyClass(value);
  const code = info.className;
  if (/^[1-8][1-9]$/.test(code)) return `${Number(code[0]) + 1}${code[1]}`;
  if (/^9[1-9]$/.test(code)) return `1${String.fromCharCode(64 + Number(code[1]))}`;
  if (/^[12][A-Z]$/.test(code)) return `${Number(code[0]) + 1}${code[1]}`;
  return code;
}
const EDUCATION_GRADES = new Set([
  ...[1, 2, 3, 4, 5].map(grade => `anos-iniciais-${grade}`),
  ...[6, 7, 8, 9].map(grade => `anos-finais-${grade}`),
  ...[1, 2, 3].map(grade => `ensino-medio-${grade}`)
]);
function normalizeName(value) { return String(value || '').trim().replace(/\s+/g, ' ').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); }
function validateRosterRows(rows) {
  if (rows.length && normalizeName(rows[0][0]).includes('matricula') && normalizeName(rows[0][1]).includes('nome')) rows = rows.slice(1);
  const students = [], seen = new Set();
  for (const row of rows) {
    const rawEnrollment = String(row[0] || '').trim().replace(/\.0$/, '');
    const name = String(row[1] || '').trim().replace(/\s+/g, ' ');
    const rawClass = String(row[2] || '').trim().replace(/\s+/g, ' ');
    if (!rawEnrollment && !name && !rawClass) continue;
    const enrollment = rawEnrollment.padStart(8, '0');
    if (!/^\d{8}$/.test(enrollment) || !name || !rawClass) throw new Error('Confira a planilha: use matrícula numérica com 8 dígitos, nome e turma nas colunas A, B e C.');
    const classification = classifyClass(rawClass);
    if (seen.has(enrollment)) throw new Error(`A matrícula ${enrollment} aparece mais de uma vez na planilha.`);
    seen.add(enrollment); students.push({ enrollment, name, ...classification });
    if (students.length > 20000) throw new Error('A planilha excede o limite de 20.000 estudantes.');
  }
  if (!students.length) throw new Error('A planilha não contém estudantes para importar.');
  return students;
}
function readRaw(req, limit = 25 * 1024 * 1024) {
  return new Promise((resolve, reject) => {
    const chunks = []; let size = 0; let exceeded = false;
    req.on('data', chunk => { size += chunk.length; if (size > limit) { exceeded = true; chunks.length = 0; return; } if (!exceeded) chunks.push(chunk); });
    req.on('end', () => exceeded ? reject(new Error('too_large')) : resolve(Buffer.concat(chunks)));
    req.on('error', reject);
  });
}
function setCookie(res, token, maxAge, cookieName = 'school_node_session', secure = false) {
  res.setHeader('Set-Cookie', `${cookieName}=${token}; HttpOnly; SameSite=Strict; Path=/; Max-Age=${maxAge}${secure ? '; Secure' : ''}`);
}
function sessionFor(req) {
  const raw = req.headers.cookie || '';
  const token = raw.split(';').map(x => x.trim()).find(x => x.startsWith('school_node_session='))?.slice('school_node_session='.length);
  if (!token) return null;
  const session = sessions.get(token);
  if (!session || session.expires < Date.now()) { sessions.delete(token); return null; }
  const admin = loadAdmins().find(item => item.email === session.email);
  if (!admin || (admin.passwordHash || admin.hash) !== session.authVersion) { sessions.delete(token); return null; }
  return session;
}
function teacherSessionFor(req) {
  const raw = req.headers.cookie || '';
  const token = raw.split(';').map(x => x.trim()).find(x => x.startsWith('school_teacher_session='))?.slice('school_teacher_session='.length);
  if (!token) return null;
  const session = teacherSessions.get(token);
  if (!session || session.expires < Date.now()) { teacherSessions.delete(token); return null; }
  const teacher = loadTeachers().find(item => String(item.email || '').toLowerCase() === session.email);
  if (!teacher || teacher.passwordHash !== session.authVersion) { teacherSessions.delete(token); return null; }
  return { ...session, name: teacher.name, platformIds: teacher.platformIds || [], mustChangePassword: Boolean(teacher.mustChangePassword) };
}
function json(res, status, body) {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' });
  res.end(JSON.stringify(body));
}
function body(req, limit = 8192) {
  return new Promise((resolve, reject) => {
    let raw = '';
    let exceeded = false;
    req.on('data', chunk => { if (exceeded) return; raw += chunk; if (raw.length > limit) { exceeded = true; raw = ''; reject(new Error('too_large')); } });
    req.on('end', () => { if (exceeded) return; try { resolve(JSON.parse(raw || '{}')); } catch { reject(new Error('bad_json')); } });
    req.on('error', reject);
  });
}
function allowedAttempt(ip) {
  const now = Date.now();
  const recent = (attempts.get(ip) || []).filter(t => now - t < 60_000);
  attempts.set(ip, recent);
  if (recent.length >= 6) return false;
  recent.push(now);
  return true;
}
function allowedRecoveryAttempt(map, ip, max, windowMs) {
  const now = Date.now();
  const recent = (map.get(ip) || []).filter(t => now - t < windowMs);
  map.set(ip, recent);
  if (recent.length >= max) return false;
  recent.push(now);
  return true;
}
function localResetAllowed(req) {
  const host = String(req.headers.host || '').toLowerCase().replace(/:\d+$/, '').replace(/^\[|\]$/g, '');
  const ip = String(req.socket.remoteAddress || '').replace(/^::ffff:/, '');
  let sameOrigin = req.method === 'GET';
  try { if (req.method !== 'GET') sameOrigin = Boolean(req.headers.origin) && new URL(req.headers.origin).host.toLowerCase() === String(req.headers.host || '').toLowerCase(); }
  catch { sameOrigin = false; }
  return process.env.NODE_ENV !== 'production' && ['localhost', '127.0.0.1', '::1'].includes(host) && ['127.0.0.1', '::1'].includes(ip) && sameOrigin && !fs.existsSync(LOCAL_RESET_USED_FILE);
}
async function sendRecoveryCode(email, code) {
  const { tenantId, clientId, clientSecret, fromEmail: from } = mailConfig();
  if (!tenantId || !clientId || !clientSecret || !from) throw new Error('recovery_email_not_configured');
  const tokenResponse = await fetch(`https://login.microsoftonline.com/${encodeURIComponent(tenantId)}/oauth2/v2.0/token`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ client_id: clientId, client_secret: clientSecret, scope: 'https://graph.microsoft.com/.default', grant_type: 'client_credentials' })
  });
  if (!tokenResponse.ok) throw new Error('m365_token_failed');
  const token = await tokenResponse.json();
  if (!token.access_token) throw new Error('m365_token_missing');
  const response = await fetch(`https://graph.microsoft.com/v1.0/users/${encodeURIComponent(from)}/sendMail`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${token.access_token}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({
      message: {
        subject: 'Código para redefinir sua senha · Colégio Santa Inês',
        body: { contentType: 'HTML', content: `<p>Use o código abaixo para redefinir sua senha da equipe TE:</p><p style="font-size:28px;font-weight:bold;letter-spacing:6px">${code}</p><p>O código expira em 10 minutos. Se você não pediu a redefinição, ignore esta mensagem.</p>` },
        toRecipients: [{ emailAddress: { address: email } }]
      },
      saveToSentItems: false
    })
  });
  if (!response.ok) throw new Error('m365_mail_failed');
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  if (url.pathname.startsWith('/api/')) {
    if (req.method === 'POST' && url.pathname === '/api/student/login') {
      const ip = req.socket.remoteAddress || 'unknown';
      if (!allowedAttempt(`student:${ip}`)) return json(res, 429, { error: 'Muitas tentativas. Aguarde um minuto e tente novamente.' });
      try {
        const payload = await body(req);
        const enrollment = String(payload.enrollment || '').trim();
        const name = String(payload.name || '').trim().replace(/\s+/g, ' ');
        const student = loadRoster().students.find(item => item.enrollment === enrollment && normalizeName(item.name) === normalizeName(name));
        if (!/^\d{8}$/.test(enrollment) || !student) return json(res, 401, { error: 'Matrícula e nome do estudante não conferem com a lista cadastrada. Confira os dados com a escola.' });
        return json(res, 200, { authenticated: true, student: { name: student.name, enrollment: student.enrollment, className: student.className, grade: student.grade, gradeId: student.gradeId, gradeLabel: student.gradeLabel, educationStage: student.educationStage, educationStageLabel: student.educationStageLabel } });
      } catch { return json(res, 400, { error: 'Não foi possível validar os dados de acesso.' }); }
    }
    if (req.method === 'POST' && url.pathname === '/api/teacher/login') {
      const ip = req.socket.remoteAddress || 'unknown';
      if (!allowedAttempt(`teacher-login:${ip}`)) return json(res, 429, { error: 'Muitas tentativas. Aguarde um minuto e tente novamente.' });
      try {
        const payload = await body(req), email = String(payload.email || '').trim().toLowerCase(), password = String(payload.password || '');
        const teacher = loadTeachers().find(item => String(item.email || '').toLowerCase() === email);
        if (!teacher || password.length > 256 || !await verifyPassword(password, teacher)) return json(res, 401, { error: 'E-mail institucional ou senha incorretos.' });
        const token = crypto.randomBytes(32).toString('hex');
        teacherSessions.set(token, { email, authVersion: teacher.passwordHash, expires: Date.now() + SESSION_MS });
        const secure = String(req.headers['x-forwarded-proto'] || '').split(',')[0] === 'https';
        setCookie(res, token, SESSION_MS / 1000, 'school_teacher_session', secure);
        return json(res, 200, { authenticated: true, teacher: { email, name: teacher.name, mustChangePassword: Boolean(teacher.mustChangePassword) } });
      } catch { return json(res, 400, { error: 'Não foi possível processar o acesso docente.' }); }
    }
    if (req.method === 'POST' && url.pathname === '/api/teacher/password') {
      const session = teacherSessionFor(req);
      if (!session) return json(res, 401, { error: 'Entre novamente para alterar sua senha.' });
      try {
        const payload = await body(req), password = String(payload.password || ''), confirmation = String(payload.confirmation || '');
        if (password.length < 4 || password.length > 256 || password !== confirmation) return json(res, 400, { error: 'Use uma senha com pelo menos 4 caracteres e confirme a mesma senha.' });
        const teachers = loadTeachers(), teacher = teachers.find(item => String(item.email || '').toLowerCase() === session.email);
        if (!teacher) return json(res, 401, { error: 'Conta docente não encontrada.' });
        teacher.passwordHash = await createPasswordHash(password);
        teacher.mustChangePassword = false;
        saveTeachers(teachers);
        const active = teacherSessions.get(String((req.headers.cookie || '').split(';').map(x => x.trim()).find(x => x.startsWith('school_teacher_session='))?.split('=').slice(1).join('=') || ''));
        if (active) active.authVersion = teacher.passwordHash;
        return json(res, 200, { saved: true });
      } catch { return json(res, 400, { error: 'Não foi possível salvar a nova senha.' }); }
    }
    if (req.method === 'POST' && url.pathname === '/api/teacher/logout') {
      const token = (req.headers.cookie || '').split(';').map(x => x.trim()).find(x => x.startsWith('school_teacher_session='))?.slice('school_teacher_session='.length);
      if (token) teacherSessions.delete(token);
      res.setHeader('Set-Cookie', 'school_teacher_session=; HttpOnly; SameSite=Strict; Path=/; Max-Age=0');
      return json(res, 200, { authenticated: false });
    }
    if (req.method === 'GET' && url.pathname === '/api/teacher/content') {
      const teacher = teacherSessionFor(req);
      if (!teacher) return json(res, 401, { error: 'Entre na Área Equipe Professores.' });
      if (teacher.mustChangePassword) return json(res, 403, { error: 'Crie sua nova senha para acessar os materiais.' });
      const content = loadContent();
      if (!content) return json(res, 200, { configured: false, content: null });
      const allowed = new Set(teacher.platformIds);
      const visible = { ...content, platforms: (content.platforms || []).filter(platform => allowed.has(String(platform.id))).map(platform => ({ ...platform, teacherPdfs: platform.teacherPdfs || [], teacherVideos: platform.teacherVideos || [] })) };
      return json(res, 200, { configured: true, content: visible, teacher: { email: teacher.email, name: teacher.name } });
    }
    if (req.method === 'GET' && url.pathname === '/api/admin/teachers') {
      if (!sessionFor(req)) return json(res, 401, { error: 'Acesso restrito.' });
      const teachers = loadTeachers().map(({ email, name, platformIds, mustChangePassword }) => ({ email, name, platformIds: Array.isArray(platformIds) ? platformIds : [], mustChangePassword: Boolean(mustChangePassword) }));
      return json(res, 200, { teachers });
    }
    if (req.method === 'POST' && url.pathname === '/api/admin/teachers') {
      if (!sessionFor(req)) return json(res, 401, { error: 'Acesso restrito.' });
      try {
        const payload = await body(req), email = String(payload.email || '').trim().toLowerCase(), name = String(payload.name || '').trim().replace(/\s+/g, ' '), platformIds = payload.platformIds;
        if (!/^[^\s@]+@santainesrs\.com\.br$/.test(email)) return json(res, 400, { error: 'Use o e-mail institucional do professor (@santainesrs.com.br).' });
        if (!name || name.length > 160 || !Array.isArray(platformIds) || !platformIds.length) return json(res, 400, { error: 'Informe nome, e-mail e ao menos uma plataforma.' });
        const known = new Set((loadContent()?.platforms || []).map(platform => String(platform.id)));
        if (platformIds.some(id => !known.has(String(id)))) return json(res, 400, { error: 'Selecione apenas plataformas cadastradas.' });
        const teachers = loadTeachers(), existing = teachers.find(item => String(item.email).toLowerCase() === email);
        if (existing) { existing.name = name; existing.platformIds = [...new Set(platformIds.map(String))]; saveTeachers(teachers); return json(res, 200, { created: false, teacher: { email, name, platformIds: existing.platformIds, mustChangePassword: Boolean(existing.mustChangePassword) } }); }
        const initialPassword = teacherInitialPassword();
        const teacher = { email, name, platformIds: [...new Set(platformIds.map(String))], passwordHash: await createPasswordHash(initialPassword), mustChangePassword: true, createdAt: new Date().toISOString() };
        teachers.push(teacher); saveTeachers(teachers);
        return json(res, 201, { created: true, teacher: { email, name, platformIds: teacher.platformIds, mustChangePassword: true }, initialPassword });
      } catch (error) { return json(res, error.statusCode || 400, { error: error.statusCode === 503 ? error.message : 'Não foi possível salvar o cadastro docente.' }); }
    }
    if (req.method === 'POST' && url.pathname === '/api/admin/teachers/reset') {
      if (!sessionFor(req)) return json(res, 401, { error: 'Acesso restrito.' });
      try {
        const email = String((await body(req)).email || '').trim().toLowerCase(), teachers = loadTeachers(), teacher = teachers.find(item => String(item.email).toLowerCase() === email);
        if (!teacher) return json(res, 404, { error: 'Professor não encontrado.' });
        const initialPassword = teacherInitialPassword();
        teacher.passwordHash = await createPasswordHash(initialPassword); teacher.mustChangePassword = true; saveTeachers(teachers);
        return json(res, 200, { reset: true, email, initialPassword });
      } catch (error) { return json(res, error.statusCode || 400, { error: error.statusCode === 503 ? error.message : 'Não foi possível redefinir a senha docente.' }); }
    }
    if (req.method === 'POST' && url.pathname === '/api/admin/roster/import') {
      if (!sessionFor(req)) return json(res, 401, { error: 'Acesso restrito.' });
      try {
        const buffer = await readRaw(req);
        const format = String(req.headers['x-roster-format'] || '').toLowerCase();
        if (!['xlsx', 'csv'].includes(format)) return json(res, 415, { error: 'Envie uma planilha Excel .xlsx ou CSV exportado do Excel.' });
        const rows = format === 'xlsx' ? parseXlsx(buffer) : parseCsv(buffer);
        const roster = saveRoster(validateRosterRows(rows), { academicYear: new Date().getFullYear(), availableClasses: [], availableClassesYear: null, lastRolloverYear: null });
        return json(res, 200, { imported: true, count: roster.count, importedAt: roster.importedAt });
      } catch (error) {
        const tooLarge = error.message === 'too_large';
        return json(res, tooLarge ? 413 : 400, { error: tooLarge ? 'A planilha é maior que o limite de 25 MB.' : error.message || 'Não foi possível importar a planilha.' });
      }
    }
    if (req.method === 'GET' && url.pathname === '/api/admin/roster') {
      if (!sessionFor(req)) return json(res, 401, { error: 'Acesso restrito.' });
      const roster = loadRoster();
      return json(res, 200, { count: roster.students.length, importedAt: roster.importedAt });
    }
    if (req.method === 'GET' && url.pathname === '/api/admin/roster/students') {
      if (!sessionFor(req)) return json(res, 401, { error: 'Acesso restrito.' });
      const roster = loadRoster();
      const query = normalizeName(url.searchParams.get('q') || '').slice(0, 100);
      const filtered = query ? roster.students.filter(student => normalizeName(`${student.enrollment} ${student.name} ${student.className} ${student.educationStageLabel || ''} ${student.gradeLabel || ''}`).includes(query)) : roster.students;
      const pageSize = 100;
      const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
      const requestedPage = Math.max(1, Number.parseInt(url.searchParams.get('page') || '1', 10) || 1);
      const page = Math.min(requestedPage, pages);
      const start = (page - 1) * pageSize;
      return json(res, 200, { students: filtered.slice(start, start + pageSize), total: filtered.length, page, pages, pageSize });
    }
    if (req.method === 'DELETE' && url.pathname === '/api/admin/roster/students') {
      if (!sessionFor(req)) return json(res, 401, { error: 'Acesso restrito.' });
      const confirmation = await body(req);
      if (confirmation.confirmation !== 'CONFIRMAR') return json(res, 400, { error: 'Digite CONFIRMAR para remover os estudantes.' });
      const roster = loadRoster();
      const deleted = roster.students.length;
      const saved = saveRoster([], {
        academicYear: roster.academicYear || new Date().getFullYear(),
        availableClasses: roster.availableClasses || [],
        availableClassesYear: roster.availableClassesYear || null,
        lastRolloverYear: roster.lastRolloverYear || null
      });
      return json(res, 200, { deleted, count: saved.count, importedAt: saved.importedAt });
    }
    if (req.method === 'POST' && url.pathname === '/api/admin/roster/student') {
      if (!sessionFor(req)) return json(res, 401, { error: 'Acesso restrito.' });
      try {
        const payload = await body(req);
        const enrollment = String(payload.enrollment || '').trim();
        const originalEnrollment = String(payload.originalEnrollment || '').trim();
        const name = String(payload.name || '').trim().replace(/\s+/g, ' ');
        const classification = classifyClass(payload.className);
        const roster = loadRoster();
        if (!/^\d{8}$/.test(enrollment)) return json(res, 400, { error: 'A matrícula deve conter exatamente 8 números.' });
        if (!name || name.length > 160) return json(res, 400, { error: 'Informe o nome do estudante (até 160 caracteres).' });
        const duplicate = roster.students.find(student => student.enrollment === enrollment && student.enrollment !== originalEnrollment);
        if (duplicate) return json(res, 409, { error: 'Essa matrícula já está cadastrada.' });
        const index = originalEnrollment ? roster.students.findIndex(student => student.enrollment === originalEnrollment) : -1;
        if (originalEnrollment && index < 0) return json(res, 404, { error: 'O estudante que você tentou editar não foi encontrado. Atualize a lista e tente novamente.' });
        if (index < 0 && roster.students.length >= 20000) return json(res, 400, { error: 'A lista já atingiu o limite de 20.000 estudantes.' });
        const student = { enrollment, name, ...classification };
        if (index >= 0) roster.students[index] = student; else roster.students.push(student);
        const saved = saveRoster(roster.students, { academicYear: roster.academicYear || new Date().getFullYear(), availableClasses: roster.availableClasses || [], availableClassesYear: roster.availableClassesYear || null, lastRolloverYear: roster.lastRolloverYear || null });
        return json(res, 200, { saved: true, student, count: saved.count, importedAt: saved.importedAt });
      } catch (error) { return json(res, 400, { error: error.message || 'Não foi possível salvar o estudante.' }); }
    }
    if (req.method === 'GET' && url.pathname === '/api/admin/roster/year-plan') {
      if (!sessionFor(req)) return json(res, 401, { error: 'Acesso restrito.' });
      const roster = loadRoster();
      const currentYear = Number(roster.academicYear) || new Date().getFullYear();
      const targetYear = currentYear + 1;
      const suggestions = [...new Set(roster.students.map(student => promotedClass(student.className)))].sort((a, b) => {
        const x = classifyClass(a), y = classifyClass(b); return x.grade - y.grade || a.localeCompare(b, 'pt-BR', { numeric: true });
      });
      const availableClasses = roster.availableClassesYear === targetYear && Array.isArray(roster.availableClasses) ? roster.availableClasses : suggestions;
      const students = [...roster.students].sort((a, b) => {
        const rank = student => student.educationStage === EDUCATION_STAGES.ensinoMedio.id ? 9 + student.grade : student.grade;
        return rank(a) - rank(b) || a.name.localeCompare(b.name, 'pt-BR', { sensitivity: 'base' });
      });
      return json(res, 200, { currentYear, targetYear, importedAt: roster.importedAt, lastRolloverYear: roster.lastRolloverYear || null, availableClasses, suggestedClasses: suggestions, students: students.map(student => ({ ...student, suggestedClass: promotedClass(student.className) })) });
    }
    if (req.method === 'PUT' && url.pathname === '/api/admin/roster/year-plan/classes') {
      if (!sessionFor(req)) return json(res, 401, { error: 'Acesso restrito.' });
      try {
        const payload = await body(req, 1024 * 1024), roster = loadRoster();
        const currentYear = Number(roster.academicYear) || new Date().getFullYear(), targetYear = currentYear + 1;
        if (Number(payload.targetYear) !== targetYear) return json(res, 409, { error: 'O ano letivo mudou. Atualize a página e revise as turmas.' });
        if (payload.expectedImportedAt && payload.expectedImportedAt !== roster.importedAt) return json(res, 409, { error: 'A lista de estudantes foi alterada. Atualize a página e revise a configuração.' });
        if (!Array.isArray(payload.availableClasses) || payload.availableClasses.length > 200) return json(res, 400, { error: 'Informe até 200 turmas disponíveis.' });
        const classes = [...new Set(payload.availableClasses.map(value => classifyClass(value).className))];
        if (!classes.length && roster.students.length) return json(res, 400, { error: 'Cadastre pelo menos uma turma para o próximo ano.' });
        const saved = saveRoster(roster.students, { academicYear: currentYear, availableClasses: classes, availableClassesYear: targetYear, lastRolloverYear: roster.lastRolloverYear || null });
        return json(res, 200, { saved: true, targetYear, availableClasses: classes, importedAt: saved.importedAt });
      } catch (error) { return json(res, 400, { error: error.message || 'Não foi possível salvar as turmas.' }); }
    }
    if (req.method === 'POST' && url.pathname === '/api/admin/roster/year-plan/apply') {
      if (!sessionFor(req)) return json(res, 401, { error: 'Acesso restrito.' });
      try {
        const payload = await body(req, 5 * 1024 * 1024), roster = loadRoster();
        const currentYear = Number(roster.academicYear) || new Date().getFullYear(), targetYear = currentYear + 1;
        if (Number(payload.targetYear) !== targetYear) return json(res, 409, { error: 'O ano letivo mudou. Atualize a página antes de confirmar.' });
        if (roster.lastRolloverYear === targetYear) return json(res, 409, { error: `A passagem para ${targetYear} já foi aplicada.` });
        if (!payload.expectedImportedAt || payload.expectedImportedAt !== roster.importedAt) return json(res, 409, { error: 'A lista de estudantes foi alterada. Atualize e confira as alterações antes de confirmar.' });
        if (roster.availableClassesYear !== targetYear || !Array.isArray(roster.availableClasses)) return json(res, 400, { error: 'Salve primeiro as turmas existentes para o próximo ano.' });
        if (!Array.isArray(payload.promoteEnrollments) || payload.promoteEnrollments.length > 20000) return json(res, 400, { error: 'A seleção de estudantes é inválida.' });
        const selected = new Set(payload.promoteEnrollments.map(value => String(value)));
        if (selected.size !== payload.promoteEnrollments.length) return json(res, 400, { error: 'Há estudantes repetidos na seleção.' });
        const placements = payload.placements && typeof payload.placements === 'object' ? payload.placements : {};
        const studentIds = new Set(roster.students.map(student => student.enrollment));
        if (studentIds.size !== roster.students.length) return json(res, 409, { error: 'A lista atual contém matrículas repetidas. Corrija a lista antes de aplicar o novo ano; a passagem nunca cria cadastros duplicados.' });
        for (const enrollment of selected) if (!studentIds.has(enrollment)) return json(res, 409, { error: 'A seleção contém estudante que não está mais na lista atual.' });
        const classSet = new Set(roster.availableClasses.map(value => classifyClass(value).className));
        const students = roster.students.map(student => {
          if (!selected.has(student.enrollment)) return student;
          const nextClass = classifyClass(placements[student.enrollment] || promotedClass(student.className)).className;
          if (!classSet.has(nextClass)) throw new Error(`A turma ${nextClass} não está cadastrada como existente em ${targetYear}.`);
          return { enrollment: student.enrollment, name: student.name, ...classifyClass(nextClass) };
        });
        const saved = saveRoster(students, { academicYear: targetYear, availableClasses: [...classSet], availableClassesYear: targetYear, lastRolloverYear: targetYear });
        return json(res, 200, { applied: true, count: saved.count, promoted: selected.size, unchanged: saved.count - selected.size, academicYear: targetYear, importedAt: saved.importedAt });
      } catch (error) { return json(res, 400, { error: error.message || 'Não foi possível aplicar a passagem de ano.' }); }
    }
    if (req.method === 'GET' && url.pathname === '/api/content') {
      const storedContent = loadContent();
      if (!storedContent) return json(res, 200, { configured: false, content: null, revision: null });
      const content = sessionFor(req) ? storedContent : { ...storedContent, platforms: (storedContent.platforms || []).map(({ teacherPdfs, teacherVideos, ...platform }) => platform) };
      const revision = contentRevision(content);
      res.setHeader('ETag', revision);
      if (req.headers['if-none-match'] === revision) { res.writeHead(304, { 'Cache-Control': 'no-store' }); return res.end(); }
      return json(res, 200, { configured: true, content, revision });
    }
    if (req.method === 'PUT' && url.pathname === '/api/admin/store-icons') {
      if (!sessionFor(req)) return json(res, 401, { error: 'Acesso restrito.' });
      try {
        const payload = await body(req, 26 * 1024 * 1024);
        const current = loadContent();
        if (!current) return json(res, 409, { error: 'Salve primeiro o conteúdo das plataformas antes de configurar os ícones.' });
        const icons = payload.storeIcons;
        if (!icons || typeof icons !== 'object' || Array.isArray(icons)) return json(res, 400, { error: 'Não recebemos as imagens dos ícones.' });
        for (const key of ['play', 'apple', 'youtube']) {
          const icon = icons[key] || '';
          if (typeof icon !== 'string' || icon.length > 8 * 1024 * 1024 || (icon && !/^data:image\/png;base64,[A-Za-z0-9+/]+=*$/.test(icon))) return json(res, 400, { error: 'Cada ícone deve ser PNG e ter até 6 MB.' });
        }
        const content = { ...current, storeIcons: { play: icons.play || '', apple: icons.apple || '', youtube: icons.youtube || '' }, updatedAt: new Date().toISOString() };
        saveContent(content);
        return json(res, 200, { saved: true, revision: contentRevision(content) });
      } catch (error) {
        if (error.message === 'too_large') return json(res, 413, { error: 'Os ícones enviados excedem o limite permitido.' });
        if (['EACCES', 'EPERM', 'EROFS'].includes(error.code)) {
          console.error('Sem permissão para gravar os ícones no armazenamento privado:', error.code);
          return json(res, 500, { error: 'O servidor está sem permissão para gravar os ícones. Verifique o acesso à pasta privada de dados.' });
        }
        console.error('Falha ao salvar ícones:', error.message);
        return json(res, 400, { error: 'Não foi possível salvar os ícones. Verifique os arquivos e tente novamente.' });
      }
    }
    if (req.method === 'PUT' && url.pathname === '/api/admin/content') {
      if (!sessionFor(req)) return json(res, 401, { error: 'Acesso restrito.' });
      try {
        const payload = await body(req, 100 * 1024 * 1024);
        const support = String(payload.support || '').trim();
        const platforms = Array.isArray(payload.platforms) ? payload.platforms : null;
        const storeIcons = payload.storeIcons && typeof payload.storeIcons === 'object' ? payload.storeIcons : { play: '', apple: '', youtube: '' };
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(support) || !platforms || platforms.length > 100) return json(res, 400, { error: 'Confira o e-mail e as plataformas antes de salvar.' });
        // PNGs em data URL crescem cerca de 33% por causa do Base64. O limite
        // considera a string enviada, não apenas o arquivo original.
        if (['play', 'apple', 'youtube'].some(key => storeIcons[key] && (typeof storeIcons[key] !== 'string' || storeIcons[key].length > 8 * 1024 * 1024 || !/^data:image\/png;base64,[A-Za-z0-9+/]+=*$/.test(storeIcons[key])))) return json(res, 400, { error: 'Cada ícone deve ser PNG e ter até 6 MB.' });
        for (const item of platforms) {
          if (!item || typeof item.name !== 'string' || !item.name.trim() || typeof item.summary !== 'string' || !Array.isArray(item.pdfs) || !Array.isArray(item.videos)) return json(res, 400, { error: 'Há uma plataforma incompleta. Revise os campos e tente novamente.' });
          if (item.videos.some(video => !video || typeof video !== 'object' || typeof video.url !== 'string' || !isYouTubeVideoUrl(video.url) || Object.hasOwn(video, 'data'))) return json(res, 400, { error: `Os vídeos da plataforma ${item.name} devem ser links HTTPS de vídeos do YouTube.` });
          if (item.teacherPdfs !== undefined && (!Array.isArray(item.teacherPdfs) || item.teacherPdfs.length > 300 || item.teacherPdfs.some(file => !file || typeof file.name !== 'string' || typeof file.data !== 'string' || file.data.length > 70 * 1024 * 1024 || !/^data:application\/pdf;base64,[A-Za-z0-9+/]+=*$/.test(file.data)))) return json(res, 400, { error: `Os PDFs exclusivos para professores da plataforma ${item.name} são inválidos.` });
          if (item.teacherVideos !== undefined && (!Array.isArray(item.teacherVideos) || item.teacherVideos.length > 300 || item.teacherVideos.some(video => !video || typeof video.name !== 'string' || typeof video.url !== 'string' || !isYouTubeVideoUrl(video.url) || Object.hasOwn(video, 'data')))) return json(res, 400, { error: `Os links de vídeo exclusivos para professores da plataforma ${item.name} são inválidos.` });
          if (item.stages !== undefined && (!Array.isArray(item.stages) || !item.stages.length || item.stages.some(stage => !Object.values(EDUCATION_STAGES).some(allowed => allowed.id === stage)))) return json(res, 400, { error: `Selecione ao menos uma etapa de ensino para a plataforma ${item.name}.` });
          if (item.grades !== undefined && (!Array.isArray(item.grades) || !item.grades.length || item.grades.some(grade => !EDUCATION_GRADES.has(grade)))) return json(res, 400, { error: `Selecione ao menos uma série válida para a plataforma ${item.name}.` });
        }
        const content = { support, platforms, storeIcons, updatedAt: new Date().toISOString() };
        saveContent(content);
        return json(res, 200, { saved: true, revision: contentRevision(content) });
      } catch (error) {
        return json(res, error.message === 'too_large' ? 413 : 400, { error: error.message === 'too_large' ? 'O conjunto de arquivos é grande demais para salvar de uma vez.' : 'Não foi possível salvar as alterações.' });
      }
    }
    if (req.method === 'GET' && url.pathname === '/api/session') {
      const session = sessionFor(req);
      const teacher = teacherSessionFor(req);
      return json(res, 200, { authenticated: Boolean(session), name: session?.name || null, teacherAuthenticated: Boolean(teacher), teacher: teacher ? { email: teacher.email, name: teacher.name, mustChangePassword: teacher.mustChangePassword } : null });
    }
    if (req.method === 'POST' && url.pathname === '/api/login') {
      const ip = req.socket.remoteAddress || 'unknown';
      if (!allowedAttempt(ip)) return json(res, 429, { error: 'Muitas tentativas. Aguarde um minuto e tente novamente.' });
      try {
        const credentials = await body(req);
        const email = String(credentials.email || '').trim().toLowerCase();
        const password = String(credentials.password || '');
        const admins = loadAdmins();
        const admin = admins.find(item => String(item.email || '').toLowerCase() === email);
        if (!admin || password.length > 256) return json(res, 401, { error: 'E-mail ou senha incorretos.' });
        if (!await verifyPassword(password, admin)) return json(res, 401, { error: 'E-mail ou senha incorretos.' });
        if (!admin.passwordHash) {
          admin.passwordHash = await createPasswordHash(password);
          delete admin.hash;
          delete admin.salt;
          saveAdmins(admins);
        }
        const token = crypto.randomBytes(32).toString('hex');
        sessions.set(token, { email: admin.email, name: admin.name, authVersion: admin.passwordHash, expires: Date.now() + SESSION_MS });
        setCookie(res, token, SESSION_MS / 1000);
        return json(res, 200, { authenticated: true, name: admin.name });
      } catch { return json(res, 400, { error: 'Não foi possível processar o acesso.' }); }
    }
    if (req.method === 'POST' && url.pathname === '/api/password-reset/request') {
      const ip = req.socket.remoteAddress || 'unknown';
      if (!allowedRecoveryAttempt(recoveryAttempts, ip, 3, 15 * 60_000)) return json(res, 429, { error: 'Aguarde antes de pedir outro código.' });
      try {
        const payload = await body(req);
        const email = String(payload.email || '').trim().toLowerCase();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return json(res, 400, { error: 'Informe um e-mail válido.' });
        const mail = mailConfig();
        if (!mail.tenantId || !mail.clientId || !mail.clientSecret || !mail.fromEmail) return json(res, 503, { error: 'A recuperação por e-mail ainda não foi configurada. Peça à equipe responsável para concluir a configuração.' });
        const admin = loadAdmins().find(item => item.email === email);
        if (admin) {
          const code = String(crypto.randomInt(0, 1_000_000)).padStart(6, '0');
          try {
            await sendRecoveryCode(email, code);
            recoveryRequests.set(email, { hash: crypto.createHash('sha256').update(code).digest('hex'), expires: Date.now() + 10 * 60_000, attempts: 0 });
          } catch {
            return json(res, 503, { error: 'O envio de recuperação ainda não está disponível. Avise a equipe responsável pelo sistema.' });
          }
        }
        return json(res, 200, { sent: true, message: 'Se o e-mail estiver cadastrado, você receberá um código de recuperação.' });
      } catch { return json(res, 400, { error: 'Não foi possível solicitar a recuperação.' }); }
    }
    if ((req.method === 'GET' || req.method === 'POST') && url.pathname === '/api/password-reset/local') {
      if (!localResetAllowed(req)) return json(res, 404, { error: 'Recuperação local indisponível.' });
      if (req.method === 'GET') return json(res, 200, { available: true });
      if (localResetBusy) return json(res, 409, { error: 'A recuperação já está em andamento.' });
      localResetBusy = true;
      try {
        const payload = await body(req);
        const password = String(payload.password || '');
        const confirmation = String(payload.confirmation || '');
        if (password.length < 12 || password.length > 256 || password !== confirmation) return json(res, 400, { error: 'Confira a confirmação e use uma senha com pelo menos 12 caracteres.' });
        const admins = loadAdmins();
        if (admins.length !== 1) return json(res, 409, { error: 'A recuperação local exige exatamente uma conta cadastrada. Peça ajuda à equipe responsável.' });
        const admin = admins[0];
        admin.passwordHash = await createPasswordHash(password);
        delete admin.hash;
        delete admin.salt;
        fs.mkdirSync(PRIVATE_DIR, { recursive: true, mode: 0o700 });
        fs.writeFileSync(LOCAL_RESET_USED_FILE, new Date().toISOString(), { flag: 'wx', mode: 0o600 });
        saveAdmins(admins);
        return json(res, 200, { reset: true, message: 'Senha atualizada. Entre com sua nova senha.' });
      } catch { return json(res, 400, { error: 'Não foi possível concluir a recuperação local.' }); }
      finally { localResetBusy = false; }
    }
    if (req.method === 'POST' && url.pathname === '/api/password-reset/complete') {
      const ip = req.socket.remoteAddress || 'unknown';
      if (!allowedRecoveryAttempt(recoveryAttempts, `complete:${ip}`, 8, 15 * 60_000)) return json(res, 429, { error: 'Muitas tentativas. Aguarde e solicite outro código.' });
      try {
        const payload = await body(req);
        const email = String(payload.email || '').trim().toLowerCase();
        const code = String(payload.code || '').trim();
        const password = String(payload.password || '');
        const pending = recoveryRequests.get(email);
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || !/^\d{6}$/.test(code) || password.length < 12 || password.length > 256 || !pending || pending.expires < Date.now() || pending.attempts >= 5) {
          return json(res, 400, { error: 'Código inválido ou expirado. Solicite um novo código e tente novamente.' });
        }
        pending.attempts++;
        const candidate = crypto.createHash('sha256').update(code).digest();
        const expected = Buffer.from(pending.hash, 'hex');
        if (!crypto.timingSafeEqual(candidate, expected)) return json(res, 400, { error: 'Código inválido ou expirado. Solicite um novo código e tente novamente.' });
        const admins = loadAdmins();
        const admin = admins.find(item => item.email === email);
        if (!admin) { recoveryRequests.delete(email); return json(res, 400, { error: 'Não foi possível concluir a redefinição.' }); }
        admin.passwordHash = await createPasswordHash(password);
        delete admin.hash;
        delete admin.salt;
        saveAdmins(admins);
        recoveryRequests.delete(email);
        return json(res, 200, { reset: true, message: 'Senha atualizada. Entre com sua nova senha.' });
      } catch { return json(res, 400, { error: 'Não foi possível concluir a redefinição.' }); }
    }
    if (req.method === 'POST' && url.pathname === '/api/activities') {
      try {
        const entry = await body(req);
        const name = String(entry.name || '').trim().slice(0, 120);
        const enrollment = String(entry.enrollment || '').trim();
        const platform = String(entry.platform || '').trim().slice(0, 160);
        const kind = String(entry.kind || '');
        const openedAt = new Date(entry.openedAt || Date.now());
        const student = loadRoster().students.find(item => item.enrollment === enrollment && normalizeName(item.name) === normalizeName(name));
        if (!student || !platform || !['Página', 'PDF', 'Vídeo'].includes(kind) || Number.isNaN(openedAt.getTime())) return json(res, 400, { error: 'Registro inválido.' });
        const items = loadActivities();
        const id = String(entry.id || crypto.randomBytes(16).toString('hex')).slice(0, 100);
        const existing = items.find(item => item.id === id);
        if (!existing) items.push({ id, name: student.name, enrollment: student.enrollment, className: student.className, platform, kind, openedAt: openedAt.toISOString(), exitedAt: null, durationSeconds: null });
        saveActivities(items);
        return json(res, 201, { id });
      } catch { return json(res, 400, { error: 'Não foi possível registrar a atividade.' }); }
    }
    const exitMatch = url.pathname.match(/^\/api\/activities\/([^/]+)\/exit$/);
    if (req.method === 'PATCH' && exitMatch) {
      const id = decodeURIComponent(exitMatch[1]);
      const items = loadActivities();
      const entry = items.find(item => item.id === id);
      if (!entry) return json(res, 404, { error: 'Registro não encontrado.' });
      if (!entry.exitedAt) {
        entry.exitedAt = new Date().toISOString();
        entry.durationSeconds = Math.max(0, Math.round((Date.parse(entry.exitedAt) - Date.parse(entry.openedAt)) / 1000));
        saveActivities(items);
      }
      return json(res, 200, { saved: true });
    }
    if (req.method === 'GET' && url.pathname === '/api/admin/activities') {
      if (!sessionFor(req)) return json(res, 401, { error: 'Acesso restrito.' });
      return json(res, 200, { activities: loadActivities() });
    }
    if (req.method === 'DELETE' && url.pathname === '/api/admin/activities') {
      if (!sessionFor(req)) return json(res, 401, { error: 'Acesso restrito.' });
      const confirmation = await body(req);
      if (confirmation.confirmation !== 'CONFIRMAR') return json(res, 400, { error: 'Digite CONFIRMAR para excluir os registros de atividade.' });
      saveActivities([]);
      return json(res, 200, { deleted: true });
    }
    if (req.method === 'POST' && url.pathname === '/api/logout') {
      const raw = req.headers.cookie || '';
      const token = raw.split(';').map(x => x.trim()).find(x => x.startsWith('school_node_session='))?.slice('school_node_session='.length);
      if (token) sessions.delete(token);
      setCookie(res, '', 0);
      return json(res, 200, { authenticated: false });
    }
    return json(res, 404, { error: 'Rota não encontrada.' });
  }

  const requested = url.pathname === '/' || url.pathname === '/index.html' ? '/index.php' : decodeURIComponent(url.pathname);
  const filename = path.resolve(ROOT, `.${requested}`);
  const publicFiles = new Set([
    'index.php',
    path.join('assets', 'colegio-santa-ines.png'),
    path.join('assets', 'site.css'),
    path.join('assets', 'app.js')
  ]);
  if (!filename.startsWith(ROOT + path.sep) || !publicFiles.has(path.relative(ROOT, filename))) {
    res.writeHead(404); return res.end('Não encontrado');
  }
  fs.readFile(filename, (error, data) => {
    if (error) { res.writeHead(404); return res.end('Não encontrado'); }
    const type = filename.endsWith('.png') ? 'image/png'
      : filename.endsWith('.css') ? 'text/css; charset=utf-8'
      : filename.endsWith('.js') ? 'application/javascript; charset=utf-8'
      : 'text/html; charset=utf-8';
    res.writeHead(200, { 'Content-Type': type, 'X-Content-Type-Options': 'nosniff', 'Referrer-Policy': 'strict-origin-when-cross-origin', 'Cache-Control': 'no-cache' });
    res.end(data);
  });
});

server.listen(PORT, '127.0.0.1', () => console.log(`Colégio Santa Inês disponível em http://127.0.0.1:${PORT}`));
