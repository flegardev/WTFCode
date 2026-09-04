/**
 * Tree-Sitter AST Static Analysis Worker for WTFCode.
 * 
 * BEGINNER NOTE:
 * Tree-sitter is a fast, incremental parsing library that generates Concrete Syntax Trees (CSTs)
 * for many programming languages (JavaScript, TypeScript, Python, PHP, Go, Rust, etc.).
 * 
 * This worker runs in Node.js, accepts source files via STDIN in JSON format,
 * compiles the Abstract Syntax Tree using WebAssembly (`.wasm`) grammars,
 * extracts symbols (functions, classes, methods, modules), and detects relationships
 * (calls, imports, inheritance) without executing the untrusted code.
 */

import { createHash } from 'node:crypto';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import Parser from 'web-tree-sitter';

const here = dirname(fileURLToPath(import.meta.url));

// 1. Initialize WebAssembly runtime for Tree-Sitter
await Parser.init();

// 2. Read incoming JSON payload from standard input (passed from PHP parent process)
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
const modulePathKeys = new Map();
const errors = [];

// 3. Process each source file through its respective language grammar
for (const file of files) {
  const grammar = grammarFor(file.path, file.language);
  if (!grammar) continue;
  try {
    let language = loaded.get(grammar);
    if (!language) {
      // Load language-specific WebAssembly parser module
      language = await Parser.Language.load(join(here, '..', 'node_modules', 'tree-sitter-wasms', 'out', `tree-sitter-${grammar}.wasm`));
      loaded.set(grammar, language);
    }
    const parser = new Parser();
    parser.setLanguage(language);
    
    // Parse source code string into AST
    const tree = parser.parse(String(file.content ?? ''));
    const path = normalize(file.path);
    
    // Record top-level file module symbol
    const moduleKey = addSymbol({ path, language: String(file.language ?? grammar), type: 'module', name: basename(path), qualified_name: path, start_line: 1, end_line: Number(file.lines ?? 1), confidence: 'high', metadata: { grammar } });
    modulePathKeys.set(path, moduleKey);

    // Import relationships are derived from parsed statement nodes only. The
    // worker records module specifiers as evidence, but never loads or executes
    // the imported code.
    extractModuleRelationships(tree.rootNode, path, grammar, moduleKey);
    
    // Recursively walk AST nodes to discover functions, classes, and calls
    walk(tree.rootNode, path, String(file.language ?? grammar), moduleKey, []);
    
    const hasError = typeof tree.rootNode.hasError === 'function' ? tree.rootNode.hasError() : tree.rootNode.hasError;
    if (hasError) errors.push({ path, line: firstErrorLine(tree.rootNode) });
    
    // Free WebAssembly memory allocations
    tree.delete();
    parser.delete();
  } catch (error) {
    errors.push({ path: normalize(file.path), line: 1, error: error?.name ?? 'TreeSitterError', message: String(error?.message ?? '').slice(0, 300) });
  }
}

// 4. Output final extracted symbols and relationship graphs to STDOUT for PHP ingestion
resolveRelationships();
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
    const target = safeIdentifier(functionNode?.text);
    if (target) {
      addRelationship({
        source_key: scope.at(-1)?.key ?? moduleKey,
        target_key: null,
        external_name: target,
        target_name: target,
        type: 'calls',
        confidence: 'medium',
        evidence_path: path,
        line_start: node.startPosition.row + 1,
        line_end: node.startPosition.row + 1,
        excerpt: null,
        metadata: { syntax: true, grammar_node: node.type },
      });
    }
  }
  for (let index = 0; index < node.namedChildCount; index += 1) {
    const child = node.namedChild(index);
    if (child) walk(child, path, languageName, moduleKey, nextScope);
  }
}

function addSymbol(symbol) {
  if (symbols.length >= MAX_SYMBOLS) {
    symbolLimitReached = true;
    return symbolKey(symbol.path, symbol.type, symbol.name, symbol.start_line);
  }
  const key = symbolKey(symbol.path, symbol.type, symbol.name, symbol.start_line);
  symbols.push({ ...symbol, key });
  return key;
}

function addRelationship(rel) {
  if (!rel.source_key || (!rel.target_key && !rel.external_name)) return;
  if (relationships.length >= MAX_RELATIONSHIPS) {
    relationshipLimitReached = true;
    return;
  }
  relationships.push(rel);
}

function extractModuleRelationships(root, path, grammar, moduleKey) {
  const visit = (node) => {
    const relationship = moduleRelationship(node, grammar);
    if (relationship) {
      addRelationship({
        source_key: moduleKey,
        target_key: null,
        external_name: relationship.specifier,
        target_name: relationship.specifier,
        type: relationship.type,
        confidence: 'medium',
        evidence_path: path,
        line_start: node.startPosition.row + 1,
        line_end: node.endPosition.row + 1,
        excerpt: null,
        metadata: { syntax: true, grammar_node: node.type },
      });
    }
    for (let index = 0; index < node.namedChildCount; index += 1) {
      const child = node.namedChild(index);
      if (child) visit(child);
    }
  };
  visit(root);
}

function moduleRelationship(node, grammar) {
  if (grammar === 'typescript' || grammar === 'javascript') {
    if (node.type !== 'import_statement' && node.type !== 'export_statement') return null;
    const source = node.childForFieldName('source');
    const specifier = staticModuleSpecifier(source?.text ?? moduleSpecifierFromStatement(node.text));
    if (!specifier) return null;
    return { type: node.type === 'export_statement' ? 're_exports' : 'imports', specifier };
  }

  if (grammar === 'python') {
    if (node.type === 'import_from_statement') {
      const moduleNode = node.childForFieldName('module_name') ?? node.childForFieldName('module');
      const specifier = bareModuleSpecifier(moduleNode?.text ?? node.text.match(/^\s*from\s+([^\s]+)\s+import\b/)?.[1]);
      return specifier ? { type: 'imports', specifier } : null;
    }
    if (node.type === 'import_statement') {
      const specifier = bareModuleSpecifier(node.text.match(/^\s*import\s+([^,\s]+)/)?.[1]);
      return specifier ? { type: 'imports', specifier } : null;
    }
  }

  if (grammar === 'php' && /^(?:include|include_once|require|require_once)_expression$/.test(node.type)) {
    const specifier = staticModuleSpecifier(node.text.match(/[('"`]([^'"`]+)['"`]/)?.[0]);
    return specifier ? { type: 'imports', specifier } : null;
  }

  return null;
}

function moduleSpecifierFromStatement(text) {
  return String(text ?? '').match(/(?:\bfrom\s*|^\s*import\s*)['"`]([^'"`]+)['"`]/)?.[1] ?? null;
}

function staticModuleSpecifier(value) {
  const text = String(value ?? '').trim();
  if (!text || text.length > 302 || text.includes('\0')) return null;
  const quote = text[0];
  if ((quote === "'" || quote === '"' || quote === '`') && text.at(-1) === quote) {
    const content = text.slice(1, -1);
    if (quote === '`' && content.includes('${')) return null;
    return bareModuleSpecifier(content);
  }
  return bareModuleSpecifier(text);
}

function bareModuleSpecifier(value) {
  const text = String(value ?? '').trim();
  if (!text || text.length > 300 || /[\r\n\0]/.test(text)) return null;
  return text;
}

function resolveRelationships() {
  for (const edge of relationships) {
    if (edge.target_key || !edge.external_name) continue;
    if (edge.type !== 'imports' && edge.type !== 're_exports') continue;
    const targetKey = resolveModule(edge.evidence_path, edge.external_name);
    if (!targetKey) continue;
    edge.target_key = targetKey;
    edge.external_name = null;
    edge.confidence = 'high';
    edge.metadata.resolution = 'relative-project-module';
  }
}

function resolveModule(sourcePath, specifier) {
  if (!specifier.startsWith('.')) return null;
  const parts = normalize(sourcePath).split('/');
  parts.pop();
  for (const part of specifier.split('/')) {
    if (!part || part === '.') continue;
    if (part === '..') {
      if (parts.length === 0) return null;
      parts.pop();
    } else {
      parts.push(part);
    }
  }
  const base = parts.join('/');
  const extensions = ['', '.ts', '.tsx', '.mts', '.cts', '.js', '.jsx', '.mjs', '.cjs', '.py', '.php'];
  for (const extension of extensions) {
    const candidate = `${base}${extension}`;
    if (modulePathKeys.has(candidate)) return modulePathKeys.get(candidate);
  }
  for (const extension of extensions.slice(1)) {
    const candidate = `${base}/index${extension}`;
    if (modulePathKeys.has(candidate)) return modulePathKeys.get(candidate);
  }
  return null;
}

function symbolKey(path, type, name, line) {
  return createHash('sha1').update(`${path}:${type}:${name}:${line}`).digest('hex').slice(0, 16);
}

function grammarFor(path, language) {
  const ext = (path.split('.').pop() ?? '').toLowerCase();
  if (language === 'typescript' || ext === 'ts' || ext === 'tsx') return 'typescript';
  if (language === 'javascript' || ext === 'js' || ext === 'jsx' || ext === 'mjs') return 'javascript';
  if (language === 'python' || ext === 'py') return 'python';
  if (language === 'php' || ext === 'php') return 'php';
  return null;
}

function declaration(node) {
  const type = node.type;
  if (/function_declaration|function_definition|method_declaration|method_definition/.test(type)) return 'function';
  if (/class_declaration|class_definition/.test(type)) return 'class';
  if (/interface_declaration/.test(type)) return 'interface';
  return null;
}

function isCall(type) {
  return /call_expression|call/.test(type);
}

function findNameNode(node) {
  for (let i = 0; i < node.namedChildCount; i += 1) {
    const child = node.namedChild(i);
    if (child?.type === 'identifier' || child?.type === 'name') return child;
  }
  return null;
}

function safeIdentifier(text) {
  if (!text) return null;
  const cleaned = text.trim().replace(/[^\w$.-]/g, '');
  return cleaned ? cleaned.slice(0, 100) : null;
}

function basename(path) {
  return path.split(/[\/\\]/).pop() ?? path;
}

function normalize(path) {
  return path.replace(/\\/g, '/');
}

function firstErrorLine(node) {
  if (node.type === 'ERROR' || node.isMissing?.()) return node.startPosition.row + 1;
  for (let i = 0; i < node.namedChildCount; i += 1) {
    const child = node.namedChild(i);
    if (child) {
      const line = firstErrorLine(child);
      if (line) return line;
    }
  }
  return 1;
}

async function readStdin() {
  const chunks = [];
  for await (const chunk of process.stdin) chunks.push(chunk);
  return Buffer.concat(chunks).toString('utf-8');
}
