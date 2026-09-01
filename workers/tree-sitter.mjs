/**
 * 🎓 BEGINNER NOTE: WebAssembly Tree-Sitter AST Static Analysis Worker
 * 
 * How Multi-Language AST Parsing Works:
 * 1. Tree-Sitter & WebAssembly (WASM):
 *    Tree-Sitter compiles formal language grammars (C, Python, JS, PHP, Go, Rust) into
 *    high-speed WebAssembly binaries (`.wasm`).
 * 2. Abstract Syntax Tree (AST):
 *    Parses source code text into a concrete syntax tree representing classes, functions,
 *    method invocations, imports, and variables with exact line/column byte offsets.
 * 3. IPC Streaming (Inter-Process Communication):
 *    The PHP backend spawns this Node.js worker subprocess, pipes project source code via
 *    `stdin` JSON, and receives structured symbol relationship graphs via `stdout`.
 */

import { createHash } from 'node:crypto';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import Parser from 'web-tree-sitter';

const here = dirname(fileURLToPath(import.meta.url));
await Parser.init();
const input = JSON.parse(await readStdin());
const files = Array.isArray(input.files) ? input.files : [];
const symbols = [];
const relationships = [];
const routes = [];
const MAX_SYMBOLS = 2000;
const MAX_RELATIONSHIPS = 4000;
let symbolLimitReached = false;
let relationshipLimitReached = false;
const loaded = new Map();
const errors = [];

for (const file of files) {
  const grammar = grammarFor(file.path, file.language);
  if (!grammar) continue;
  try {
    let language = loaded.get(grammar);
    if (!language) {
      language = await Parser.Language.load(join(here, '..', 'node_modules', 'tree-sitter-wasms', 'out', `tree-sitter-${grammar}.wasm`));
      loaded.set(grammar, language);
    }
    const parser = new Parser();
    parser.setLanguage(language);
    const tree = parser.parse(String(file.content ?? ''));
    const path = normalize(file.path);
    const moduleKey = addSymbol({ path, language: String(file.language ?? grammar), type: 'module', name: basename(path), qualified_name: path, start_line: 1, end_line: Number(file.lines ?? 1), confidence: 'high', metadata: { grammar } });
    walk(tree.rootNode, path, String(file.language ?? grammar), moduleKey, []);
    const hasError = typeof tree.rootNode.hasError === 'function' ? tree.rootNode.hasError() : tree.rootNode.hasError;
    if (hasError) errors.push({ path, line: firstErrorLine(tree.rootNode) });
    tree.delete();
    parser.delete();
  } catch (error) {
    errors.push({ path: normalize(file.path), line: 1, error: error?.name ?? 'TreeSitterError', message: String(error?.message ?? '').slice(0, 300) });
  }
}

process.stdout.write(JSON.stringify({ symbols, relationships, routes, stats: { symbols: symbols.length, relationships: relationships.length, routes: 0, parse_errors: errors.length, symbol_limit_reached: Number(symbolLimitReached), relationship_limit_reached: Number(relationshipLimitReached) }, errors }));

function walk(node, path, languageName, moduleKey, scope) {
  let nextScope = scope;
  const descriptor = declaration(node);
  if (descriptor) {
    const nameNode = node.childForFieldName('name') ?? findNameNode(node);
    const name = safeIdentifier(nameNode?.text);
    if (name) {
      const parent = scope.at(-1);
      const qualified = parent ? `${parent.name}.${name}` : name;
      const key = addSymbol({ path, language: languageName, type: descriptor, name, qualified_name: qualified, parent_key: parent?.key ?? moduleKey, start_line: node.startPosition.row + 1, end_line: node.endPosition.row + 1, confidence: 'high', metadata: { grammar_node: node.type } });
      nextScope = [...scope, { key, name }];
    }
  }
  if (isCall(node.type)) {
    const functionNode = node.childForFieldName('function') ?? node.childForFieldName('name') ?? node.namedChild(0);
    const target = safeCallName(functionNode?.text);
    if (target) addRelationship(nextScope.at(-1)?.key ?? moduleKey, null, target, 'calls', path, node.startPosition.row + 1, { grammar_node: node.type });
  }
  if (isImport(node.type)) {
    const target = safeImport(node.text);
    if (target) addRelationship(moduleKey, null, target, 'imports', path, node.startPosition.row + 1, { grammar_node: node.type });
  }
  for (const child of node.namedChildren) walk(child, path, languageName, moduleKey, nextScope);
}

function declaration(node) {
  const map = {
    class_declaration: 'class', class_definition: 'class', interface_declaration: 'interface', trait_declaration: 'trait',
    enum_declaration: 'enum', enum_item: 'enum', struct_item: 'class', trait_item: 'interface', module: 'module',
    function_declaration: 'function', function_definition: 'function', function_item: 'function', method_declaration: 'method',
    method_definition: 'method', constructor_declaration: 'method', singleton_method: 'method', type_alias_declaration: 'type_alias',
  };
  return map[node.type] ?? null;
}

function findNameNode(node) {
  return node.namedChildren.find((child) => ['identifier', 'name', 'type_identifier', 'constant'].includes(child.type)) ?? null;
}
function isCall(type) { return ['call_expression', 'function_call_expression', 'invocation_expression', 'call'].includes(type); }
function isImport(type) { return ['import_statement', 'import_from_statement', 'import_declaration', 'use_declaration', 'require_clause'].includes(type); }
function safeIdentifier(value) { const text = String(value ?? ''); return /^[A-Za-z_$][A-Za-z0-9_$]*$/.test(text) ? text : null; }
function safeCallName(value) { const text = String(value ?? '').trim(); return /^[A-Za-z_$][A-Za-z0-9_.$:#-]{0,299}$/.test(text) ? text : '<dynamic>'; }
function safeImport(value) {
  const match = String(value ?? '').match(/["']([^"']{1,300})["']/);
  if (match) return match[1];
  const compact = String(value ?? '').replace(/\s+/g, ' ').trim();
  return compact.length <= 300 && /^[A-Za-z0-9_.$:/@*{}, -]+$/.test(compact) ? compact : null;
}
function firstErrorLine(root) {
  const stack = [root];
  while (stack.length) { const node = stack.shift(); const missing = typeof node.isMissing === 'function' ? node.isMissing() : node.isMissing; if (node.type === 'ERROR' || missing) return node.startPosition.row + 1; stack.push(...node.namedChildren); }
  return 1;
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
  relationships.push({ source_key: sourceKey, target_key: targetKey, external_name: externalName, target_name: externalName ?? '', type, confidence: 'medium', evidence_path: path, line_start: line, line_end: line, excerpt: null, metadata });
}
function grammarFor(path, language) {
  const lower = String(path ?? '').toLowerCase();
  if (lower.endsWith('.tsx')) return 'tsx';
  if (/\.(ts|mts|cts)$/.test(lower)) return 'typescript';
  const map = { JavaScript: 'javascript', Python: 'python', PHP: 'php', Go: 'go', Rust: 'rust', Java: 'java', 'C#': 'c_sharp', Ruby: 'ruby', HTML: 'html', CSS: 'css', JSON: 'json', YAML: 'yaml' };
  return map[language] ?? null;
}
function normalize(path) { return String(path).replaceAll('\\', '/').replace(/^\/+/, ''); }
function basename(path) { return path.split('/').pop() ?? path; }
function hash(value) { return createHash('sha256').update(value).digest('hex'); }
async function readStdin() { const chunks = []; for await (const chunk of process.stdin) chunks.push(chunk); return Buffer.concat(chunks).toString('utf8'); }
