# 🎓 WTFCode Codebase Archaeologist — Beginner's Learning Guide

## What This Project Does
**WTFCode** is a multi-engine static code analysis platform that allows developers to explore symbol dependency graphs, calculate blast radius impact of code modifications, and detect security vulnerabilities across large multi-language software repositories.

---

## 🗺️ Architecture Map

```
ENTRY POINT
  │  (public/index.php / bootstrap.php)
  ▼
ROUTING & MIDDLEWARE
  │  (HTTP Request Router, Auth.php, LoginRateLimiter.php)
  ▼
STATIC ANALYSIS COORDINATOR
  │  (RepoScanner.php, AnalysisCoordinator.php, SafeProcessRunner.php)
  ▼
ANALYSIS ENGINES & WASM WORKERS
  │  ├── Tree-Sitter WASM Worker (workers/tree-sitter.mjs)
  │  ├── ast-grep Pattern Matcher (workers/ast-grep.mjs)
  │  └── Security Scanners (Gitleaks, Grype, OSV)
  ▼
EVIDENCE FUSION & GRAPH ENGINE
  │  (EvidenceFusion.php, SymbolGraph.php, BlastRadiusService.php)
  ▼
PERSISTENCE LAYER
  │  (Database.php / PostgreSQL / SQLite / SymbolRepository.php)
  ▼
UI & VISUALIZATION
     (public/project.php, Cytoscape.js Symbol Graph, public/assets/js/symbol-map.js)
```

---

## 🎯 Core Files to Study First (Recommended Learning Order)

1. [`bootstrap.php`](file:///C:/Users/tinif/repos/WTFCode/bootstrap.php)
   - **Why study first**: Shows how a modern PHP 8.4 application initializes, registers PSR-4 autoloading, handles uncaught exceptions, and configures secure session storage.
2. [`src/RepoScanner.php`](file:///C:/Users/tinif/repos/WTFCode/src/RepoScanner.php)
   - **Why study next**: The central coordinator that walks the file tree, discovers languages, and orchestrates static analysis passes.
3. [`workers/tree-sitter.mjs`](file:///C:/Users/tinif/repos/WTFCode/workers/tree-sitter.mjs)
   - **Why study third**: Explains how WebAssembly compiles Tree-Sitter language grammars to extract Abstract Syntax Trees (AST) and symbol call graphs.
4. [`src/Analysis/EvidenceFusion.php`](file:///C:/Users/tinif/repos/WTFCode/src/Analysis/EvidenceFusion.php)
   - **Why study fourth**: Teaches how to reconcile, deduplicate, and fuse facts produced by multiple independent analysis tools.
5. [`src/BlastRadiusService.php`](file:///C:/Users/tinif/repos/WTFCode/src/BlastRadiusService.php)
   - **Why study fifth**: Implements Breadth-First Search (BFS) graph traversal to measure the transitive impact of changing a function or database table.
6. [`public/project.php`](file:///C:/Users/tinif/repos/WTFCode/public/project.php)
   - **Why study sixth**: The primary web interface controller rendering the repository dashboard and connecting to Cytoscape.js.

---

## 🛠️ How to Run & Verify

1. **Start Local Development Server**:
   ```bash
   php -S localhost:8080 -t public
   ```
2. **Verify PHP Syntax**:
   ```bash
   php -l bootstrap.php
   php -l public/index.php
   ```
