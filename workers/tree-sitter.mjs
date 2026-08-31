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
        from_key: scope.at(-1)?.key ?? moduleKey,
        type: 'calls',
        to_name: target,
        path,
        line: node.startPosition.row + 1,
        confidence: 'medium',
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
  if (relationships.length >= MAX_RELATIONSHIPS) {
    relationshipLimitReached = true;
    return;
  }
  relationships.push(rel);
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
