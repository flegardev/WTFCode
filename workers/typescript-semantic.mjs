import { createHash } from 'node:crypto';
import tsMorph from 'ts-morph';

const { Node, Project, SyntaxKind, ts } = tsMorph;

const input = JSON.parse(await readStdin());
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
const nodeKeys = new Map();
const moduleKeys = new Map();
const modulePathKeys = new Map();

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
      confidence: 'high',
      exported: isExported(declaration),
      visibility: visibility(declaration),
      metadata: {
        semantic: true,
        syntax_kind: declaration.getKindName(),
        type: safeType(declaration),
      },
    });
    nodeKeys.set(declaration, key);

    if (Node.isClassDeclaration(declaration) || Node.isInterfaceDeclaration(declaration)) {
      for (const heritage of [...(declaration.getExtends?.() ?? []), ...(declaration.getImplements?.() ?? [])]) {
        addRelationship(key, null, safeName(heritage.getExpression?.().getText?.() ?? heritage.getText()), 'inherits', path, heritage.getStartLineNumber(), { semantic: true });
      }
    }
  }

  for (const importDeclaration of source.getImportDeclarations()) {
    addRelationship(moduleKey, null, importDeclaration.getModuleSpecifierValue(), 'imports', path, importDeclaration.getStartLineNumber(), { semantic: true });
  }
  for (const exportDeclaration of source.getExportDeclarations()) {
    const target = exportDeclaration.getModuleSpecifierValue();
    if (target) addRelationship(moduleKey, null, target, 're_exports', path, exportDeclaration.getStartLineNumber(), { semantic: true });
  }

  for (const call of source.getDescendantsOfKind(SyntaxKind.CallExpression)) {
    const expression = call.getExpression();
    const sourceDeclaration = call.getFirstAncestor((ancestor) => nodeKeys.has(ancestor));
    const sourceKey = sourceDeclaration ? nodeKeys.get(sourceDeclaration) : moduleKey;
    let targetKey = null;
    let targetSymbol = expression.getSymbol?.() ?? (Node.isPropertyAccessExpression(expression) ? expression.getNameNode().getSymbol?.() : null);
    if (targetSymbol?.isAlias?.()) targetSymbol = targetSymbol.getAliasedSymbol?.() ?? targetSymbol;
    for (const declaration of targetSymbol?.getDeclarations?.() ?? []) {
      if (nodeKeys.has(declaration)) { targetKey = nodeKeys.get(declaration); break; }
      const owner = declaration.getFirstAncestor?.((ancestor) => nodeKeys.has(ancestor));
      if (owner) { targetKey = nodeKeys.get(owner); break; }
    }
    addRelationship(sourceKey, targetKey, targetKey ? null : safeName(expression.getText()), 'calls', path, call.getStartLineNumber(), { semantic: targetKey !== null });
  }

  const jsxNodes = [
    ...source.getDescendantsOfKind(SyntaxKind.JsxOpeningElement),
    ...source.getDescendantsOfKind(SyntaxKind.JsxSelfClosingElement),
  ];
  for (const jsx of jsxNodes) {
    const tag = jsx.getTagNameNode().getText();
    if (!/^[A-Z]/.test(tag)) continue;
    const sourceDeclaration = jsx.getFirstAncestor((ancestor) => nodeKeys.has(ancestor));
    addRelationship(sourceDeclaration ? nodeKeys.get(sourceDeclaration) : moduleKey, null, tag, 'renders', path, jsx.getStartLineNumber(), { semantic: false });
  }
}

resolveRelationships();
process.stdout.write(JSON.stringify({ symbols, relationships, routes, stats: { symbols: symbols.length, relationships: relationships.length, routes: 0 } }));

function addSymbol(symbol) {
  const key = hash([symbol.path, symbol.type, symbol.qualified_name, symbol.start_line].join('|'));
  symbols.push({ key, signature: null, exported: false, visibility: 'unknown', parent_key: null, metadata: {}, ...symbol });
  return key;
}

function addRelationship(sourceKey, targetKey, externalName, type, path, line, metadata) {
  if (!targetKey && !externalName) return;
  relationships.push({ source_key: sourceKey, target_key: targetKey, external_name: externalName, target_name: externalName ?? '', type, confidence: targetKey ? 'high' : 'medium', evidence_path: path, line_start: line, line_end: line, excerpt: null, metadata });
}

function resolveRelationships() {
  const byName = new Map();
  for (const symbol of symbols) {
    if (symbol.type === 'module') continue;
    const list = byName.get(symbol.name) ?? [];
    list.push(symbol.key);
    byName.set(symbol.name, list);
  }
  for (const edge of relationships) {
    if (edge.target_key || !edge.external_name) continue;
    if (edge.type === 'imports' || edge.type === 're_exports') {
      const target = resolveModule(edge.evidence_path, edge.external_name);
      if (target) {
        edge.target_key = target;
        edge.external_name = null;
        edge.confidence = 'high';
        edge.metadata.semantic = true;
      }
      continue;
    }
    if (edge.type !== 'calls' && edge.type !== 'renders') continue;
    const shortName = edge.external_name.split(/[.:#]/).pop();
    const candidates = byName.get(shortName) ?? [];
    if (candidates.length === 1) {
      edge.target_key = candidates[0];
      edge.external_name = null;
      edge.target_name = shortName;
      edge.confidence = 'high';
      edge.metadata.semantic = true;
      edge.metadata.resolution = 'unique-project-symbol';
    }
  }
}

function resolveModule(sourcePath, specifier) {
  if (!specifier.startsWith('.')) return null;
  const parts = sourcePath.split('/');
  parts.pop();
  for (const part of specifier.split('/')) {
    if (!part || part === '.') continue;
    if (part === '..') parts.pop(); else parts.push(part);
  }
  const base = parts.join('/');
  for (const candidate of [base, `${base}.ts`, `${base}.tsx`, `${base}.js`, `${base}.jsx`, `${base}/index.ts`, `${base}/index.tsx`, `${base}/index.js`]) {
    if (modulePathKeys.has(candidate)) return modulePathKeys.get(candidate);
  }
  return null;
}

function declarationType(node) {
  if (Node.isClassDeclaration(node)) return 'class';
  if (Node.isInterfaceDeclaration(node)) return 'interface';
  if (Node.isEnumDeclaration(node)) return 'enum';
  if (Node.isTypeAliasDeclaration(node)) return 'type_alias';
  if (Node.isFunctionDeclaration(node)) return 'function';
  if (Node.isMethodDeclaration(node) || Node.isConstructorDeclaration(node)) return 'method';
  if (Node.isPropertyDeclaration(node)) return 'property';
  if (Node.isVariableDeclaration(node) && (Node.isArrowFunction(node.getInitializer()) || Node.isFunctionExpression(node.getInitializer()))) return 'function';
  return null;
}

function declarationName(node) {
  if (Node.isConstructorDeclaration(node)) return 'constructor';
  return node.getName?.() ?? null;
}

function classify(type, name) {
  if (type === 'function' && /^use[A-Z0-9]/.test(name)) return 'hook';
  if (type === 'function' && /^[A-Z]/.test(name)) return 'component';
  return type;
}

function isExported(node) { return Boolean(node.isExported?.() || node.isDefaultExport?.()); }
function visibility(node) {
  const scope = node.getScope?.();
  return ['public', 'protected', 'private'].includes(scope) ? scope : 'unknown';
}
function safeType(node) {
  const text = node.getTypeNode?.()?.getText?.();
  return text ? text.slice(0, 300) : null;
}
function safeName(value) {
  const name = String(value ?? '').trim();
  return /^[A-Za-z_$][A-Za-z0-9_.$#:/@-]{0,299}$/.test(name) ? name : '<dynamic>';
}
function language(path) { return /\.(?:tsx?|mts|cts)$/i.test(path) ? 'TypeScript' : 'JavaScript'; }
function normalize(path) { return String(path).replaceAll('\\', '/').replace(/^\/+/, ''); }
function basename(path) { return path.split('/').pop() ?? path; }
function hash(value) { return createHash('sha256').update(value).digest('hex'); }
async function readStdin() {
  const chunks = [];
  for await (const chunk of process.stdin) chunks.push(chunk);
  return Buffer.concat(chunks).toString('utf8');
}
