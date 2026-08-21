# WTFCode V3 multi-engine foundation

This document describes the phase-1 foundation retained by the completed V3 implementation. Later V3 phases added AST and structural analyzers, security providers, graph UX, change intelligence, product modes, explanation providers, caching, and incremental analysis. The current implementation status and product boundaries live in `README.md`; this document remains the detailed contract for provider isolation, evidence provenance, process safety, and persistence.

## Scan flow

```text
RepoScanner safe file discovery
  -> AnalysisRequest (validated repository root, in-memory readable files, profile)
  -> AnalysisCoordinator
  -> AnalyzerRegistry
  -> independent AnalyzerProviderInterface implementations
  -> AnalyzerResult per provider
  -> EvidenceFusion
  -> normalized symbol graph with provenance
  -> SymbolGraphStore + AnalyzerRunStore
```

The native V2 analyzer is retained as `wtfcode-native` version `2.0.0`. Phase 2 adds PHP-Parser, Tree-sitter, and TypeScript semantic providers while keeping their engine versions separate from the platform scan version. This lets WTFCode improve orchestration and combine engines without pretending that the native parser itself changed.

## Provider contract

Every provider declares a stable ID, version, supported languages, capabilities, availability, analysis entry point, and health check. Providers return data; they do not write directly to the WTFCode graph tables.

Provider failures are converted into an isolated result with one of these statuses:

- `success`
- `partial`
- `unavailable`
- `failed`

A failed optional provider does not discard successful results from another provider.

## Evidence identity and fusion

Symbol identity uses normalized language, file, qualified name, symbol type, and source range. Relationship identity uses the resolved source and target, relationship type, evidence file, and evidence line. Route identity uses file, HTTP method, normalized path, and evidence line.

Equivalent facts retain one graph row. Their metadata contains a provenance list with:

- engine and engine version
- platform analysis version
- confidence
- evidence file, line, and range
- raw evidence type

Agreement from at least two independent engines can promote the beginner-facing confidence label to `confirmed`. A single engine, including a semantic analyzer, is capped at `strong`. Agreement never manufactures an edge that no provider emitted, and repeated evidence from one engine does not count as independent confirmation.

## Process boundary

`SafeProcessRunner` is the only approved boundary for analyzer CLIs. It uses an argument array with shell bypass, validates the working directory, passes only allowlisted environment keys, caps captured stdout and stderr independently, records truncation and exit codes, and terminates timed-out processes.

On Windows, process output is written to monitored private temporary files. PHP's Windows pipe implementation can block despite nonblocking mode, which would defeat timeout enforcement. Temporary files are removed after every run.

Imported repositories remain data. The process runner is for trusted analyzer executables only and does not authorize project scripts, package installation, framework bootstrapping, tests, hooks, Dockerfiles, or repository binaries.

## Persistence

Migration `003_multi_engine_foundation.sql` adds:

- scan profile and provider status JSON on `scan_runs`
- queryable `analysis_provider_runs`
- provenance JSON on symbols, relationships, and routes

The complete normalized metadata is still retained in `metadata_json` for compatibility with existing V2 readers.

## Doctor

Run:

```powershell
php tools/doctor.php
php tools/doctor.php --json
```

The doctor only probes installed tools and the configured MySQL connection. It does not install anything or execute imported repository code.
