# WTFCode

**Know what your AI actually built.**

WTFCode is a plain-PHP codebase understanding tool for people who can build quickly with AI but want to understand the application before changing it again. Import a public GitHub repository and it produces a plain-English, evidence-based project map.

## Current MVP

- Secure registration, login, logout, CSRF protection, and per-user project ownership.
- Public GitHub repository import with strict `https://github.com/owner/repository` validation.
- Private Git clone storage outside the public web root.
- Automatic stack detection for Next.js, React, Vue, FastAPI, Django, Node APIs, PHP, Supabase, Docker, Vercel, and common data-layer signals.
- File map with routes, API endpoints, authentication, configuration, UI, and data-model roles.
- Import/include dependency extraction for JavaScript, TypeScript, PHP, and Python.
- File explanations in plain English and a technical view.
- Confirmed direct and transitive blast-radius reports, plus clearly-labelled inferred system impact.
- Clickable architecture nodes that show their exact file evidence; inferred system-to-system links are never presented as confirmed runtime facts.
- Feature tracing across matching files and confirmed import/include relationships.
- Rules-based "things to understand before editing" findings for large files, auth surfaces, duplicated content, unresolved local imports, implementation notes, repeated clients, configuration surface, and credential-like patterns.
- Repository question page that answers only from scanned metadata. It does not use or pretend to use an AI API.
- Git commit comparison using the repository’s real history, grouped by area with conservative sensitive-path review flags.
- A safe prompt builder that uses the scanned architecture and actual file paths rather than fabricating project context.
- An opt-in "Learn my app" checklist with per-user exploration progress.

## V3 implementation status

V3 phases 1 through 6 are implemented without replacing the verified V2 analyzer. Repository scans pass through an analyzer-provider registry and coordinator, preserve per-engine run status, fuse equivalent graph evidence deterministically, and persist source-engine provenance on symbols, relationships, routes, findings, and package inventory. Active precision engines include nikic/PHP-Parser, Tree-sitter WASM grammars, an isolated ts-morph semantic worker, an isolated ast-grep structural worker, and a bounded ripgrep text fallback. Universal Ctags is integrated as an optional fallback.

Evidence is ranked conservatively: semantic resolution outranks direct syntax, structural patterns remain `likely`, and ripgrep runtime text remains `heuristic`. Security profiles combine redacted Gitleaks results, optional Semgrep code findings, OSV dependency advisories, optional Syft inventory, and optional offline Grype confirmation without treating a finding as proof of exploitability. See `docs/V3-FOUNDATION.md`, `config/tool-manifest.json`, and `THIRD_PARTY.md` for boundaries and provenance.

The Cytoscape graph starts at architecture level and drills through subsystems, features, files, and symbols. It supports local search, confidence/relationship/risk/framework filters, neighbor focus, pan/zoom, and confidence-weighted strongest-path tracing without replacing the server-rendered PHP detail pages.

The product-intelligence pass enriches fused evidence with multi-signal feature clusters, partial UI/HTTP/control-flow paths, classified database operations, endpoint effects, and runtime-backed service boundaries. It labels partial traces explicitly and never treats README text alone as proof that an integration is active.

## Intentional MVP boundaries

- Only public GitHub repositories are supported. Private repository OAuth is not faked.
- Source contents are analysed in memory during the scan but are not saved to MySQL. The clone remains in private application storage so Git analysis and rescans can work.
- Static analysis cannot prove runtime behavior. Dynamic imports, remote services, generated code, environment-specific deployment behavior, and indirect dependencies may not be detected.
- Imports are capped at a 100 MB cloned repository, 3,000 readable files, 256 KB per readable file, and 20 MB of total readable source per scan. A partial scan is disclosed in the project findings.
- Security analysis is static and incomplete. It does not prove exploitability or replace tests, manual review, incident response, backups, or Git.

## Architecture

```text
public/                 Web entry points
src/Auth.php            Session authentication
src/RepositoryImporter  URL validation and restricted Git cloning
src/RepoScanner.php     File discovery, stack detection, dependencies, nodes, findings
src/Project.php         Project ownership and persistence
src/ExplorationService  Per-user learning checklist and progress
src/PromptSafetyService Evidence-backed change-prompt builder
src/ExplanationService  Plain-English and evidence-based explanations
src/GitDiffService.php  Safe commit comparison
storage/repos/          Private repository clones, never web-served
storage/logs/           Private application logs
database/schema.sql     MySQL 8 schema
```

## Local setup

Requirements:

- PHP 8.3+ with `pdo_mysql`
- MySQL 8+
- Git available on the server `PATH`

1. Create the database and tables:

   ```powershell
   Get-Content database/schema.sql | mysql -u root -p
   ```

2. Configure the database with shell environment variables from `.env.example`, or copy `config/database.php` to `config/database.local.php` and replace the connection values. `database.local.php` is ignored by Git.

   If you already created the database from an older project version, apply the numbered SQL files in `database/migrations/` in order, stopping after the newest migration already reflected in your schema.

3. Serve `public` as the document root:

   ```powershell
   php -S localhost:8000 -t public
   ```

4. Open `http://localhost:8000`, create an account, and import a public GitHub repository.

## Security notes

- Every database access uses PDO prepared statements.
- Every modifying form validates a CSRF token.
- Project queries include the authenticated user ID before files, scan data, or Git history are accessible.
- Imports only accept canonical public GitHub HTTPS URLs. Arbitrary clone targets, file URLs, SSH URLs, and non-GitHub hosts are rejected.
- Repository paths are allocated server-side from a project ID; callers never submit a filesystem path.
- Git runs through argument arrays with the shell bypassed; clone URLs and commit refs are independently allowlisted. Imports disable Git terminal prompts and time out after 90 seconds.
- Git clone disables submodule recursion. The scanner skips symlinks and ignored dependency/build directories, and no imported repository code is installed or executed.
- Technical errors are logged privately. Production-facing errors remain generic.

## Tests

The lightweight test file deliberately has no framework dependency:

```powershell
php tests/UnitTest.php
php tests/V2AnalysisTest.php
php tests/V2IntegrationTest.php
php tests/V3FoundationTest.php
php tests/V3AstTest.php
php tests/V3StructuralTest.php
php tests/V3SecurityTest.php
php tests/V3GraphTest.php
php tests/V3FeatureTest.php
php tests/V3IntegrationTest.php
php tests/Benchmark.php --group=core
```

`UnitTest.php` covers repository URL restriction, blast-radius explanation behavior, and read-only scanner inspection of imports and symbols. The V2 and V3 integration suites require the configured MySQL database. `Benchmark.php` shallow-clones a versioned public-repository suite and reports whether required stack and architecture signals are present; see `tests/benchmarks/README.md` for the human scorecard workflow.

## Roadmap

1. GitHub OAuth with least-privilege private repository access.
2. Durable scan jobs and background queues for large repositories.
3. AST-based dependency extraction and richer framework adapters.
4. An optional, consent-based LLM explanation service.
5. Prompt-safety suggestions that understand the current blast radius.
6. Shared team projects, pull-request explanations, and onboarding guides.
