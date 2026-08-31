# WTFCode -- Multi-Engine Static Code Analysis & Symbol Graph Platform

A multi-engine static analysis platform combining Web Tree-Sitter WebAssembly, ast-grep, ts-morph, and Nikic PHP-Parser to generate interactive Cytoscape.js symbol graphs, framework route maps, and AST security audits.

![WTFCode Product Overview](./public/assets/images/product-overview.png)

## Overview

**WTFCode** is a web-based code intelligence and static analysis engine designed to help developers explore code structure and audit multi-language repositories.

The platform bridges client-side WebAssembly AST parsing with backend worker threads. It generates visual dependency and symbol call graphs, extracts web framework routes (Laravel, Symfony, Express, Next.js), detects dead code paths, and performs AST structural pattern scanning to highlight security vulnerabilities.

## Features

- **Interactive Symbol & Call Graphing**: Visualizes functions, classes, interfaces, and module import hierarchies using an interactive Cytoscape.js canvas.
- **Multi-Engine AST Traversal**:
  - **Web Tree-Sitter (Wasm)**: In-browser parsing for C, C++, Rust, Go, Python, JavaScript, and TypeScript.
  - **ast-grep NAPI**: Structural AST matching and pattern execution.
  - **ts-morph**: TypeScript AST inspection and symbol resolution.
  - **Nikic PHP-Parser 5**: AST and class hierarchy extraction for PHP codebases.
- **Framework & Route Extraction**: Identifies registered routes, HTTP verbs, middleware, and controller action mappings across supported frameworks.
- **AST Security Scanner**: Structural pattern matching that flags unescaped SQL strings, unsafe sinks, and hardcoded credentials.
- **Supabase Snapshot Storage**: Persists analyzed repository snapshots and dependency graphs to Supabase PostgreSQL for caching.
- **GitHub Integration**: Connects with public repositories and private repositories via GitHub App credentials.

## Screenshots

| Code Intelligence & Symbol Graph Overview |
|:---:|
| ![WTFCode Interface](./public/assets/images/product-overview.png) |

## Tech Stack

### Frontend & Parsing Engine
- **Visual Graph Rendering**: Cytoscape.js (`cytoscape`)
- **WebAssembly AST Parsers**: Web Tree-Sitter (`web-tree-sitter`), Tree-Sitter Wasm Grammars (`tree-sitter-wasms`)
- **Structural Analysis**: `@ast-grep/napi`, `ts-morph`
- **UI Architecture**: Vanilla ES6 Modules, Modern CSS Grid / Glassmorphism

### Backend API & Worker Layer
- **API Runtime**: PHP 8.3 (Modular bootstrap router)
- **PHP AST Engine**: Nikic PHP-Parser (`nikic/php-parser` ^5.0)
- **Worker Threads**: Node.js Worker Threads (`workers/typescript-semantic.mjs`, `workers/tree-sitter.mjs`, `workers/ast-grep.mjs`)
- **Database & Persistence**: Supabase PostgreSQL / Session Connection Pooler
- **Deployment**: Docker, Vercel (`Dockerfile.vercel`)

## Project Structure

```text
WTFCode/
├── config/                     # Application configuration and scanner profiles
├── database/                   # Schema migrations and SQL initialization
├── deploy/                     # Vercel and Docker deployment assets
├── docs/                       # Architecture specifications and documentation
├── public/                     # Web root and static assets
│   ├── assets/
│   │   ├── images/             # Product preview images
│   │   └── js/                 # Symbol mapper and Cytoscape UI controllers
│   └── index.php               # Front controller entrypoint
├── src/                        # PHP core scanner, route extractors, and analyzers
├── storage/                    # Temporary AST cache and scan artifacts
├── supabase/                   # Supabase database schema and migrations
├── tests/                      # Unit and integration test suites
├── views/                      # PHP view templates
├── workers/                    # Node.js worker threads (ast-grep, ts-morph, tree-sitter)
├── bootstrap.php               # Application initialization and autoloader
├── composer.json               # PHP dependencies (nikic/php-parser)
├── Dockerfile.vercel           # Container definition for Vercel
└── package.json                # Node worker dependencies and verification scripts
```

## Installation & Setup

### Prerequisites

- PHP 8.3+
- Node.js 18+ (Node.js 20+ recommended)
- Composer 2+
- npm 9+

### Setup

1. **Clone the repository:**
   ```bash
   git clone https://github.com/flegardev/WTFCode.git
   cd WTFCode
   ```

2. **Install Node.js worker dependencies:**
   ```bash
   npm install
   ```

3. **Install PHP dependencies:**
   ```bash
   composer install
   ```

4. **Configure environment variables:**
   ```bash
   cp .env.example .env
   ```

5. **Start local PHP development server:**
   ```bash
   php -S localhost:8000 -t public
   ```

6. Open [http://localhost:8000](http://localhost:8000) in your browser.

## Configuration

Configure environment variables in `.env`:

```env
APP_ENV=development
APP_DEBUG=true

# Supabase Database Connection (Optional for local memory scans)
DATABASE_URL=postgresql://postgres.project:password@host:5432/postgres
DB_SSLMODE=require
WTF_STORAGE_PATH=/tmp/wtfcode
WTF_SCAN_PROFILE=quick

# GitHub App Integration (Optional: for private repository imports)
GITHUB_APP_ID=
GITHUB_APP_CLIENT_ID=
GITHUB_APP_CLIENT_SECRET=
GITHUB_APP_PRIVATE_KEY=

# AI Explanation Provider (Optional: 'deterministic', 'openai', or 'ollama')
WTF_CODE_EXPLANATION_PROVIDER=deterministic
WTF_CODE_OPENAI_API_KEY=
WTF_CODE_OLLAMA_ENDPOINT=http://127.0.0.1:11434/api/chat
```

## Available Scripts

| Command | Description |
|---|---|
| `npm run check:workers` | Validates syntax and loading of all Node.js AST worker threads |
| `php -S localhost:8000 -t public` | Starts local PHP development server on port 8000 |
| `composer validate` | Validates `composer.json` syntax and schema |

## License

This project is licensed under the MIT License.
