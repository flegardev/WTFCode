import astGrep from '@ast-grep/napi';

let input;
try {
  const raw = await readStdin();
  input = raw ? JSON.parse(raw) : { files: [] };
} catch (parseError) {
  process.stdout.write(JSON.stringify({
    symbols: [],
    relationships: [],
    routes: [],
    findings: [],
    stats: { symbols: 0, relationships: 0, routes: 0, findings: 0, parse_errors: 1 },
    errors: [{ path: '<stdin>', line: 1, error: parseError?.name ?? 'JSONError', message: String(parseError?.message ?? '') }],
  }));
  process.exit(0);
}

const files = Array.isArray(input.files) ? input.files : [];
const findings = [];
const seen = new Set();
const rules = [
  { id: 'wtfcode.http.fetch', pattern: 'fetch($$$ARGS)', capture: 'fetch', severity: 'info', type: 'http_call', title: 'Browser or server HTTP request', explanation: 'This code sends an HTTP request. Trace the URL and response handling before changing the surrounding flow.' },
  { id: 'wtfcode.http.axios', pattern: 'axios.$METHOD($$$ARGS)', capture: 'METHOD', severity: 'info', type: 'http_call', title: 'Axios HTTP request', explanation: 'This code sends an HTTP request through Axios. Trace its URL, payload, and response handling.' },
  { id: 'wtfcode.http.ky', pattern: 'ky.$METHOD($$$ARGS)', capture: 'METHOD', severity: 'info', type: 'http_call', title: 'Ky HTTP request', explanation: 'This code sends an HTTP request through Ky. Trace its URL, payload, and response handling.' },
  { id: 'wtfcode.http.superagent', pattern: 'superagent.$METHOD($$$ARGS)', capture: 'METHOD', severity: 'info', type: 'http_call', title: 'SuperAgent HTTP request', explanation: 'This code sends an HTTP request through SuperAgent. Trace its URL, payload, and response handling.' },
  { id: 'wtfcode.code.eval', pattern: 'eval($$$ARGS)', severity: 'risk', type: 'dynamic_code_execution', title: 'Dynamic code execution', explanation: 'This code evaluates text as JavaScript. If outside data can reach it, application behavior may be changed unexpectedly.' },
  { id: 'wtfcode.process.exec', pattern: 'exec($$$ARGS)', severity: 'risk', type: 'process_execution', title: 'System process execution', explanation: 'This function can start a system command. Confirm that untrusted input cannot influence its arguments.' },
  { id: 'wtfcode.process.spawn', pattern: 'spawn($$$ARGS)', severity: 'risk', type: 'process_execution', title: 'System process execution', explanation: 'This function starts another process. Review argument construction and trust boundaries.' },
  { id: 'wtfcode.process.child-process', pattern: 'child_process.$METHOD($$$ARGS)', capture: 'METHOD', severity: 'risk', type: 'process_execution', title: 'Node process execution', explanation: 'This code invokes the Node child-process API. Review argument construction and trust boundaries.' },
  { id: 'wtfcode.auth.supabase-login', pattern: '$CLIENT.auth.signInWithPassword($$$ARGS)', severity: 'attention', type: 'authentication_boundary', title: 'Supabase sign-in boundary', explanation: 'This call signs a user in through Supabase and belongs to a critical authentication flow.' },
  { id: 'wtfcode.auth.supabase', pattern: '$CLIENT.auth.$METHOD($$$ARGS)', capture: 'METHOD', severity: 'attention', type: 'authentication_boundary', title: 'Supabase authentication boundary', explanation: 'This call crosses a Supabase authentication boundary. Review session, identity, and authorization handling.' },
  { id: 'wtfcode.auth.firebase', pattern: '$AUTH.$METHOD($$$ARGS)', capture: 'METHOD', captureAllow: ['signInWithEmailAndPassword', 'createUserWithEmailAndPassword', 'verifyIdToken', 'signOut'], severity: 'attention', type: 'authentication_boundary', title: 'Firebase authentication boundary', explanation: 'This call appears to authenticate a user or verify a Firebase identity token.' },
  { id: 'wtfcode.auth.jwt-verify', pattern: 'jwt.verify($$$ARGS)', capture: 'verify', severity: 'attention', type: 'authentication_boundary', title: 'JWT verification boundary', explanation: 'This call verifies a JSON Web Token. Review accepted algorithms, issuer, audience, and error handling.' },
  { id: 'wtfcode.navigation.redirect', pattern: 'redirect($$$ARGS)', severity: 'info', type: 'navigation', title: 'Application redirect', explanation: 'This code redirects the current request or user to another location.' },
  { id: 'wtfcode.database.prisma-create', pattern: '$DB.$MODEL.create($$$ARGS)', severity: 'attention', type: 'database_write', title: 'Database create operation', explanation: 'This call appears to create a database record through an ORM client.' },
  { id: 'wtfcode.database.prisma-update', pattern: '$DB.$MODEL.update($$$ARGS)', severity: 'attention', type: 'database_write', title: 'Database update operation', explanation: 'This call appears to update a database record through an ORM client.' },
  { id: 'wtfcode.database.prisma-delete', pattern: '$DB.$MODEL.delete($$$ARGS)', severity: 'attention', type: 'destructive_database_operation', title: 'Database delete operation', explanation: 'This call appears to delete a database record. Review authorization and affected data.' },
  { id: 'wtfcode.database.supabase', pattern: '$CLIENT.from($TABLE).$METHOD($$$ARGS)', capture: 'METHOD', captureAllow: ['select', 'insert', 'update', 'upsert', 'delete'], severity: 'attention', type: 'database_operation', title: 'Supabase data operation', explanation: 'This code accesses application data through Supabase. Review the table, operation, and authorization policy.' },
  { id: 'wtfcode.database.drizzle', pattern: '$DB.$METHOD($$$ARGS)', capture: 'METHOD', captureAllow: ['select', 'insert', 'update', 'delete'], severity: 'attention', type: 'database_operation', title: 'Database operation', explanation: 'This code performs a structured database operation. Trace the selected table and affected records.' },
  { id: 'wtfcode.filesystem.write', pattern: '$FS.$METHOD($$$ARGS)', capture: 'METHOD', captureAllow: ['writeFile', 'writeFileSync', 'appendFile', 'appendFileSync', 'rename', 'renameSync', 'unlink', 'unlinkSync'], severity: 'attention', type: 'file_operation', title: 'Filesystem write operation', explanation: 'This code writes, renames, or removes a file. Review path construction and authorization.' },
  { id: 'wtfcode.environment.process', pattern: 'process.env.$NAME', capture: 'NAME', severity: 'info', type: 'environment_access', title: 'Environment configuration access', explanation: 'This code reads an environment variable. Its value is intentionally not collected.' },
  { id: 'wtfcode.environment.import-meta', pattern: 'import.meta.env.$NAME', capture: 'NAME', severity: 'info', type: 'environment_access', title: 'Build-time environment access', explanation: 'This code reads build-time environment configuration. Its value is intentionally not collected.' },
  { id: 'wtfcode.ui.onclick', pattern: '<$ELEMENT onClick={$HANDLER} $$$ATTRS>$$$CHILDREN</$ELEMENT>', capture: 'HANDLER', severity: 'info', type: 'ui_handler', title: 'User click handler', explanation: 'This UI element invokes a handler when clicked. Trace the handler to understand its effects.' },
  { id: 'wtfcode.ui.onsubmit', pattern: '<$ELEMENT onSubmit={$HANDLER} $$$ATTRS>$$$CHILDREN</$ELEMENT>', capture: 'HANDLER', severity: 'info', type: 'ui_handler', title: 'Form submission handler', explanation: 'This form invokes a submission handler. Trace it through validation, network, and data effects.' },
];

for (const file of files) {
  const lang = language(file.path);
  if (!lang) continue;
  let root;
  try { root = astGrep.parse(lang, String(file.content ?? '')).root(); } catch { continue; }
  for (const rule of rules) {
    let matches = [];
    try { matches = root.findAll(rule.pattern); } catch { continue; }
    for (const match of matches) {
      const captured = rule.capture && rule.capture !== rule.id.split('.').at(-1) ? match.getMatch(rule.capture)?.text() : rule.capture;
      if (rule.captureAllow && !rule.captureAllow.includes(captured)) continue;
      const range = match.range();
      const key = `${rule.id}|${file.path}|${range.start.line}`;
      if (seen.has(key)) continue;
      seen.add(key);
      findings.push({
        severity: rule.severity,
        type: rule.type,
        title: rule.title,
        explanation: rule.explanation,
        confidence: 'medium',
        path: normalize(file.path),
        evidence: {
          rule_id: rule.id,
          line: range.start.line + 1,
          line_end: range.end.line + 1,
          column: range.start.column + 1,
          column_end: range.end.column + 1,
          language: String(file.language ?? lang),
          matched_symbol: captured ? String(captured).slice(0, 120) : null,
          raw_evidence_type: match.kind(),
          context: 'runtime',
        },
      });
      if (findings.length >= 2000) break;
    }
    if (findings.length >= 2000) break;
  }
  if (findings.length >= 2000) break;
}

process.stdout.write(JSON.stringify({ symbols: [], relationships: [], routes: [], findings, stats: { symbols: 0, relationships: 0, routes: 0, findings: findings.length } }));

function language(path) {
  const lower = String(path ?? '').toLowerCase();
  if (lower.endsWith('.tsx')) return 'Tsx';
  if (/\.(ts|mts|cts)$/.test(lower)) return 'TypeScript';
  if (/\.(js|jsx|mjs|cjs)$/.test(lower)) return 'JavaScript';
  return null;
}
function normalize(path) { return String(path).replaceAll('\\', '/').replace(/^\/+/, ''); }
async function readStdin() { const chunks = []; for await (const chunk of process.stdin) chunks.push(chunk); return Buffer.concat(chunks).toString('utf8'); }
