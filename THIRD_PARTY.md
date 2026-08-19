# Third-party inventory

WTFCode does not vendor third-party source. This inventory distinguishes required runtime tools, optional tools already used by the foundation, and evaluated future analyzers. Exact installed versions come from `php tools/doctor.php`; missing optional tools do not block a basic scan.

| Name | Purpose | License | Invocation | Status |
| --- | --- | --- | --- | --- |
| PHP | Application runtime and native analyzer | PHP License 3.01 | Direct runtime | Required |
| MySQL | Metadata and graph persistence | GPL-2.0 with MySQL licensing terms | PDO connection | Required |
| Git | Restricted public-repository clone and history inspection | GPL-2.0-only | Argument-array subprocess | Required |
| Node.js | Host for future isolated TypeScript/tree-sitter workers | MIT | Argument-array subprocess | Optional, installed |
| ripgrep | Fast bounded fallback search and tool discovery support | MIT OR Unlicense | Argument-array subprocess | Optional, installed; provider not yet implemented |
| Playwright CLI | Development-only browser verification | Apache-2.0 | Test-time CLI | Development only |
| nikic/PHP-Parser | PHP AST extraction | BSD-3-Clause | Composer library | Evaluated; not installed |
| Tree-sitter | General syntax parsing | MIT | Isolated worker or CLI | Evaluated; not installed |
| ts-morph | TypeScript semantic indexing | MIT | Isolated Node worker | Evaluated; not installed |
| Universal Ctags | Broad-language symbol fallback | GPL-2.0-or-later | CLI JSON output | Evaluated; not installed |
| ast-grep | Structural pattern matching | MIT | CLI JSON output | Evaluated; not installed |
| Semgrep | Optional static security analysis | LGPL-2.1-or-later for the open-source engine; rule licenses vary | CLI JSON output | Evaluated; not installed |
| Gitleaks | Secret detection | MIT | CLI JSON output | Evaluated; not installed |
| OSV-Scanner | Dependency vulnerability lookup | Apache-2.0 | CLI JSON output | Evaluated; not installed |
| Syft | Optional SBOM generation | Apache-2.0 | CLI JSON output | Evaluated; not installed |
| Grype | Optional vulnerability correlation | Apache-2.0 | CLI JSON output | Evaluated; not installed |

CodeQL is intentionally absent from the executable inventory. A future adapter must remain disabled unless the user supplies an eligible installation and the applicable license permits the intended use.
