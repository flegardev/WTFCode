# WTFCode — evidence-backed code intelligence

Stop asking “WTF does this code do?” WTFCode maps unfamiliar repositories, explains how systems connect, and shows what can break before you change them.

![WTFCode Product Overview](./public/assets/images/product-overview.png)

## Overview

WTFCode is a PHP application with isolated PHP and Node.js analysis providers. It combines their bounded static evidence into a symbol graph, routes, architecture signals, security review leads, evidence-backed answers, and change-impact reports.

Imported repositories are treated as untrusted input: WTFCode reads bounded source files but does not install their dependencies or execute their code. Complete source files are not stored in PostgreSQL; normalized metadata and bounded evidence excerpts can be. Hosted clones are temporary.

## Features

- **Interactive Symbol & Call Graphing**: Visualizes functions, classes, interfaces, and module import hierarchies using an interactive Cytoscape.js canvas.
- **Multi-Engine AST Traversal**:
  - **Web Tree-Sitter (Wasm)**: Isolated Node worker parsing for supported grammar bundles.
  - **ast-grep NAPI**: Structural AST matching and pattern execution.
  - **ts-morph**: TypeScript AST inspection and symbol resolution.
  - **Nikic PHP-Parser 5**: AST and class hierarchy extraction for PHP codebases.
- **Framework & Route Extraction**: Identifies registered routes, HTTP verbs, middleware, and controller action mappings across supported frameworks.
- **AST Security Scanner**: Structural pattern matching that flags unescaped SQL strings, unsafe sinks, and hardcoded credentials.
- **PostgreSQL Evidence Storage**: Persists normalized analysis results, jobs, and dependency graphs through a server-only PostgreSQL connection.
- **GitHub Integration**: Connects with public repositories and private repositories via GitHub App credentials.
- **Change Intelligence**: Expands a target or Git diff into affected symbols, routes, data boundaries, likely tests, and a grounded implementation prompt.
- **Durable Scan Jobs**: Enqueues imports and rescans for a separate lease-based worker instead of holding an HTTP request open.

## Screenshots

| Code Intelligence & Symbol Graph Overview |
|:---:|
| ![WTFCode Interface](./public/assets/images/product-overview.png) |

## Tech Stack

### Interface
- **Visual Graph Rendering**: Cytoscape.js (`cytoscape`)
- **UI Architecture**: Server-rendered PHP, vanilla JavaScript, and responsive CSS

### Parsing engine
- **WebAssembly AST Parser**: Web Tree-Sitter (`web-tree-sitter`) with pinned Tree-Sitter Wasm grammars (`tree-sitter-wasms`)
- **Structural Analysis**: `@ast-grep/napi`, `ts-morph`

### Backend API & Worker Layer
- **Application Runtime**: PHP 8.3+ with Composer autoloading and small HTTP/application/repository seams
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
│   └── *.php                   # Server-rendered HTTP entrypoints
├── src/                        # PHP application, repositories, scanner, and analyzers
├── storage/                    # Temporary AST cache and scan artifacts
├── supabase/                   # Supabase database schema and migrations
├── tests/                      # Unit and integration test suites
├── views/                      # PHP view templates
├── workers/                    # Node.js worker threads (ast-grep, ts-morph, tree-sitter)
├── bootstrap.php               # Security, sessions, and Composer bootstrap
├── composer.json               # PHP runtime and development dependencies
├── Dockerfile.vercel           # Container definition for Vercel
└── package.json                # Node worker dependencies and verification scripts
```

## Installation & Setup

### Prerequisites

- PHP 8.3+
- Node.js 20+
- Composer 2+
- npm 9+

### Setup

1. **Clone the repository:**
   ```bash
   git clone https://github.com/flegardev/WTFCode.git
   cd WTFCode
   ```

2. **Install PHP dependencies:**
   ```bash
   composer install
   ```

3. **Install the pinned Node.js worker dependencies:**
   ```bash
   npm ci
   ```

4. **Configure environment variables and a PostgreSQL database:**
   ```bash
   cp .env.example .env
   ```

5. **Apply the canonical schema:**
   ```bash
   php tools/migrate.php
   php tools/check-database.php
   ```

6. **Start a scan worker in a separate process:**
   ```bash
   composer worker
   ```

7. **Start the local PHP development server:**
   ```bash
   php -S localhost:8000 -t public
   ```

8. Open [http://localhost:8000](http://localhost:8000) in your browser.

## Configuration

Configure environment variables in `.env`:

```env
APP_ENV=development
APP_DEBUG=true

# Supabase Session pooler or direct PostgreSQL connection
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
| `composer test` | Runs PHPUnit plus the portable regression suite; no database or external analyzer binary is required |
| `composer test:all` | Runs every tier after PostgreSQL, Node dependencies, security tools, Git, and outbound test access are available |
| `composer test:analyzers` | Runs AST worker integration checks after `npm ci` |
| `composer test:integration` | Runs tests that require a disposable migrated PostgreSQL database |
| `composer test:security` | Runs PostgreSQL security tests with the binaries pinned in `config/tool-manifest.json` |
| `composer test:e2e` | Runs the public GitHub import check, which requires outbound network access |
| `composer lint` | Syntax-checks every project PHP file without executing application code |
| `composer analyse` | Runs PHPStan against the application, entrypoints, and tools |
| `composer format:check` | Reports formatting differences in new PSR-4 code and PHPUnit tests |
| `composer verify` | Runs the portable lint, test, static-analysis, and formatting gates |
| `composer worker` | Runs the durable scan worker loop; deploy this as a separate long-running process |
| `composer worker:once` | Claims and processes at most one available scan job |
| `pwsh tools/install-security-tools.ps1` | Installs hash-pinned Windows security analyzers into the ignored `tools/bin` directory |
| `bash tools/install-security-tools.sh` | Installs hash-pinned Linux security analyzers for local or CI use |
| `npm run check:workers` | Validates syntax and loading of all Node.js AST worker threads |
| `php -S localhost:8000 -t public` | Starts local PHP development server on port 8000 |
| `composer validate --strict` | Validates `composer.json` and its lock file |

## License

Proprietary. See the repository owner for licensing terms.
