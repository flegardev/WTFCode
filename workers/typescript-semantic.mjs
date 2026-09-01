import { createHash } from 'node:crypto';
import tsMorph from 'ts-morph';

const { Node, Project, SyntaxKind, ts } = tsMorph;

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

const files = Array.isArray(input.files) ? input.files.filter((file) => /\.(?:[cm]?[jt]sx?)$/i.test(file.path ?? '')) : [];
const project = new Project({
  useInMemoryFileSystem: true,
  skipAddingFilesFromTsConfig: true,
  compilerOptions: {
    allowJs: true,
    checkJs: true,
    jsx: ts.JsxEmit.ReactJSX,
    module: ts.ModuleKind.ESNext,
    moduleResolution: ts.ModuleResolutionKind.Bundler,
    target: ts.ScriptTarget.ESNext,
    noEmit: true,
    skipLibCheck: true,
  },
});

for (const file of files) project.createSourceFile('/repo/' + normalize(file.path), String(file.content ?? ''), { overwrite: true });

const symbols = [];
const relationships = [];
const routes = [];
const MAX_SYMBOLS = 2000;
const MAX_RELATIONSHIPS = 4000;
let symbolLimitReached = false;
let relationshipLimitReached = false;
const nodeKeys = new Map();
const moduleKeys = new Map();
const modulePathKeys = new Map();

// Pass 1: Extract all modules and declarations into nodeKeys
for (const source of project.getSourceFiles()) {
  const path = normalize(source.getFilePath().replace(/^\/repo\//, ''));
  const moduleKey = addSymbol({ path, language: language(path), type: 'module', name: basename(path), qualified_name: path, start_line: 1, end_line: source.getEndLineNumber(), confidence: 'high', metadata: { semantic: true } });
  moduleKeys.set(source, moduleKey);
  modulePathKeys.set(path, moduleKey);

  const declarations = source.getDescendants().filter((node) => declarationType(node) !== null);
  for (const declaration of declarations) {
    const descriptor = declarationType(declaration);
    if (descriptor === null) continue;
    const name = declarationName(declaration);
    if (!name) continue;
    const parentDeclaration = declaration.getFirstAncestor((ancestor) => nodeKeys.has(ancestor));
    const parentKey = parentDeclaration ? nodeKeys.get(parentDeclaration) : moduleKey;
    const parentName = parentDeclaration ? declarationName(parentDeclaration) : null;
    const type = classify(descriptor, name);
    const qualified = parentName ? `${parentName}.${name}` : name;
    const key = addSymbol({
      path,
      language: language(path),
      type,
      name,
      qualified_name: qualified,
      parent_key: parentKey,
      start_line: declaration.getStartLineNumber(),
      end_line: declaration.getEndLineNumber(),
      signature: signature(declaration),
      exported: isExported(declaration),
      confidence: 'high',
      metadata: { semantic: true, kind: declaration.getKindName() },
    });
    if (key) nodeKeys.set(declaration, key);
  }
}

// Pass 2: Resolve all calls, imports, and cross-file relationships with complete nodeKeys index
const typeChecker = project.getTypeChecker();

for (const source of project.getSourceFiles()) {
  const path = normalize(source.getFilePath().replace(/^\/repo\//, ''));
  const moduleKey = moduleKeys.get(source) ?? null;

  for (const call of source.getDescendantsOfKind(SyntaxKind.CallExpression)) {
    const callerDeclaration = call.getFirstAncestor((ancestor) => nodeKeys.has(ancestor));
    const callerKey = callerDeclaration ? nodeKeys.get(callerDeclaration) : moduleKey;
    const text = call.getExpression().getText();
    const targetName = safeCallName(text);
    if (!targetName) continue;
    let targetKey = null;
    try {
      const expr = call.getExpression();
      const symbol = expr.getSymbol();
      if (symbol) {
        const decls = symbol.getDeclarations() ?? [];
        for (const decl of decls) {
          if (nodeKeys.has(decl)) {
            targetKey = nodeKeys.get(decl);
            break;
          }
          if (Node.isImportSpecifier(decl) || Node.isImportClause(decl)) {
            try {
              const aliased = typeChecker.getAliasedSymbol(symbol);
              const aliasedDecls = aliased?.getDeclarations() ?? [];
              for (const aDecl of aliasedDecls) {
                if (nodeKeys.has(aDecl)) {
                  targetKey = nodeKeys.get(aDecl);
                  break;
                }
              }
            } catch {}
          }
        }
      }
    } catch {}
    addRelationship(callerKey, targetKey, targetKey ? null : targetName, 'calls', path, call.getStartLineNumber(), { semantic: true });
  }

  for (const imp of source.getImportDeclarations()) {
    const target = imp.getModuleSpecifierValue();
    if (!target) continue;
    addRelationship(moduleKey, null, target, 'imports', path, imp.getStartLineNumber(), { semantic: true });
  }
}

for (const source of project.getSourceFiles()) {
  const path = normalize(source.getFilePath().replace(/^\/repo\//, ''));
  const nextAppRoute = routeFromNextAppPath(path);
  if (nextAppRoute) {
    const moduleKey = modulePathKeys.get(path) ?? null;
    for (const exp of source.getExportedDeclarations().keys()) {
      const verb = String(exp).toUpperCase();
      if (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'].includes(verb)) {
        routes.push({
          path: nextAppRoute,
          method: verb,
          framework: 'nextjs',
          handler_symbol_key: moduleKey,
          confidence: 'high',
          evidence_path: path,
          line: 1,
          metadata: { app_router: true },
        });
      }
    }
  }
}

process.stdout.write(JSON.stringify({ symbols, relationships, routes, findings: [], stats: { symbols: symbols.length, relationships: relationships.length, routes: routes.length, findings: 0, symbol_limit_reached: Number(symbolLimitReached), relationship_limit_reached: Number(relationshipLimitReached) } }));

function declarationType(node) {
  if (Node.isClassDeclaration(node)) return 'class';
  if (Node.isInterfaceDeclaration(node)) return 'interface';
  if (Node.isEnumDeclaration(node)) return 'enum';
  if (Node.isTypeAliasDeclaration(node)) return 'type_alias';
  if (Node.isFunctionDeclaration(node)) return 'function';
  if (Node.isMethodDeclaration(node)) return 'method';
  if (Node.isConstructorDeclaration(node)) return 'method';
  if (Node.isVariableDeclaration(node)) {
    const init = node.getInitializer();
    if (init && (Node.isArrowFunction(init) || Node.isFunctionExpression(init))) return 'function';
  }
  return null;
}

function declarationName(node) {
  if (Node.isVariableDeclaration(node) || Node.isClassDeclaration(node) || Node.isInterfaceDeclaration(node) || Node.isEnumDeclaration(node) || Node.isTypeAliasDeclaration(node) || Node.isFunctionDeclaration(node) || Node.isMethodDeclaration(node)) {
    const text = node.getName?.() ?? node.getNameNode?.()?.getText();
    return safeIdentifier(text);
  }
  if (Node.isConstructorDeclaration(node)) return 'constructor';
  return null;
}

function isExported(node) {
  if (Node.isExportable(node)) return node.isExported();
  const parent = node.getParent();
  return parent && Node.isExportable(parent) ? parent.isExported() : false;
}

function classify(descriptor, name) {
  if (descriptor === 'function' && /^use[A-Z0-9]/.test(name)) return 'hook';
  if (descriptor === 'function' && /^[A-Z][A-Za-z0-9]*$/.test(name)) return 'component';
  return descriptor;
}

function signature(node) {
  const text = node.getText().split('\n')[0]?.trim() ?? '';
  return text.length <= 200 ? text.replace(/\s*\{.*$/, '') : null;
}

function addSymbol(symbol) {
  const key = hash([symbol.path, symbol.type, symbol.qualified_name, symbol.start_line].join('|'));
  if (symbols.length >= MAX_SYMBOLS) { symbolLimitReached = true; return null; }
  symbols.push({ key, signature: null, exported: false, visibility: 'unknown', parent_key: null, metadata: {}, ...symbol });
  return key;
}

function addRelationship(sourceKey, targetKey, externalName, type, path, line, metadata) {
  if (!sourceKey) return;
  if (relationships.length >= MAX_RELATIONSHIPS) { relationshipLimitReached = true; return; }
  relationships.push({ source_key: sourceKey, target_key: targetKey, external_name: externalName, target_name: externalName ?? '', type, confidence: 'high', evidence_path: path, line_start: line, line_end: line, excerpt: null, metadata });
}

function routeFromNextAppPath(path) {
  const match = path.match(/^app\/(.+)\/route\.(?:[jt]sx?)$/i);
  if (!match) return null;
  const raw = '/' + match[1].replace(/\/page$/, '').replace(/\/route$/, '');
  return raw.replace(/\[\.\.\.([^\]]+)\]/g, '*$1').replace(/\[([^\]]+)\]/g, ':$1');
}

function language(path) {
  const lower = String(path ?? '').toLowerCase();
  if (lower.endsWith('.tsx')) return 'TSX';
  if (lower.endsWith('.jsx')) return 'JSX';
  if (/\.(ts|mts|cts)$/.test(lower)) return 'TypeScript';
  return 'JavaScript';
}

function safeIdentifier(value) { const text = String(value ?? '').trim(); return /^[A-Za-z_$][A-Za-z0-9_$]*$/.test(text) ? text : null; }
function safeCallName(value) { const text = String(value ?? '').trim(); return /^[A-Za-z_$][A-Za-z0-9_.$:#-]{0,299}$/.test(text) ? text : null; }
function normalize(path) { return String(path).replaceAll('\\', '/').replace(/^\/+/, ''); }
function basename(path) { return path.split('/').pop() ?? path; }
function hash(value) { return createHash('sha256').update(value).digest('hex'); }
async function readStdin() { const chunks = []; for await (const chunk of process.stdin) chunks.push(chunk); return Buffer.concat(chunks).toString('utf8'); }
