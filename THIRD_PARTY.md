# Third-party inventory

WTFCode does not vendor third-party source. This inventory distinguishes required runtime tools, optional tools already used by the foundation, and evaluated future analyzers. Exact installed versions come from `php tools/doctor.php`; missing optional tools do not block a basic scan.

| Name | Purpose | License | Invocation | Status |
| --- | --- | --- | --- | --- |
| PHP | Application runtime and native analyzer | PHP License 3.01 | Direct runtime | Required |
| MySQL | Metadata and graph persistence | GPL-2.0 with MySQL licensing terms | PDO connection | Required |
| Git | Restricted public-repository clone and history inspection | GPL-2.0-only | Argument-array subprocess | Required |
| Node.js | Host for isolated TypeScript and Tree-sitter workers | MIT | Argument-array subprocess | Required for semantic/syntax enhancement, installed 24.19.0 |
| ripgrep 15.2.0 | Fast bounded fallback search | MIT OR Unlicense | Local executable through bounded argument-array subprocess | Integrated, optional |
| Playwright CLI | Development-only browser verification | Apache-2.0 | Test-time CLI | Development only |
| nikic/PHP-Parser | PHP AST extraction | BSD-3-Clause | Composer library | Integrated, 5.8.0 |
| web-tree-sitter | General syntax parsing runtime | MIT | Isolated Node worker | Integrated, 0.20.8 |
| tree-sitter-wasms | Precompiled language grammars | Unlicense | Loaded by isolated Node worker | Integrated, 0.1.13 |
| ts-morph | TypeScript semantic indexing | MIT | Isolated Node worker | Integrated, 28.0.0 with bundled TypeScript 6.0.2 |
| Universal Ctags | Broad-language symbol fallback | GPL-2.0-or-later | CLI JSON output | Adapter integrated; binary not installed |
| @ast-grep/napi 0.45.1 | Structural JavaScript/TypeScript/JSX/TSX pattern matching | MIT | Pinned npm library in an isolated Node worker | Integrated, optional |
| Semgrep | Optional static security analysis | LGPL-2.1-or-later for the open-source engine; rule licenses vary | CLI JSON output | Evaluated; not installed |
| Gitleaks | Secret detection | MIT | CLI JSON output | Evaluated; not installed |
| OSV-Scanner | Dependency vulnerability lookup | Apache-2.0 | CLI JSON output | Evaluated; not installed |
| Syft | Optional SBOM generation | Apache-2.0 | CLI JSON output | Evaluated; not installed |
| Grype | Optional vulnerability correlation | Apache-2.0 | CLI JSON output | Evaluated; not installed |

CodeQL is intentionally absent from the executable inventory. A future adapter must remain disabled unless the user supplies an eligible installation and the applicable license permits the intended use.
