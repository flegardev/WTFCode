# Third-party inventory

WTFCode does not vendor analyzer source. The browser ships Cytoscape's pinned minified distribution and license so the graph works without a runtime CDN. This inventory distinguishes required runtime tools, optional analyzers, and development-only tools. Exact installed versions come from `php tools/doctor.php`; missing optional tools do not block a basic scan.

| Name | Purpose | License | Installation source | Invocation | Status |
| --- | --- | --- | --- | --- | --- |
| PHP | Application runtime and native analyzer | PHP License 3.01 | PHP for Windows / WinGet | Direct runtime | Required |
| MySQL | Metadata and graph persistence | GPL-2.0 with MySQL licensing terms | MySQL Community Server | PDO connection | Required |
| Git | Restricted public-repository clone and history inspection | GPL-2.0-only | Git for Windows | Argument-array subprocess | Required |
| Node.js | Host for isolated workers | MIT | Official Node.js Windows installer | Argument-array subprocess | Required for semantic/syntax enhancement, installed 24.19.0 |
| ripgrep 15.2.0 | Fast bounded fallback search | MIT OR Unlicense | Existing Codex/VS Code distribution | Local executable through bounded argument-array subprocess | Integrated, optional |
| Playwright CLI | Development-only browser verification | Apache-2.0 | Local Codex Playwright skill | Test-time CLI | Development only |
| nikic/PHP-Parser 5.8.0 | PHP AST extraction | BSD-3-Clause | Packagist via pinned Composer lock | Composer library | Integrated |
| web-tree-sitter 0.20.8 | General syntax parsing runtime | MIT | npm via pinned package lock | Isolated Node worker | Integrated |
| tree-sitter-wasms 0.1.13 | Precompiled language grammars | Unlicense | npm via pinned package lock | Loaded by isolated Node worker | Integrated |
| ts-morph 28.0.0 / TypeScript 6.0.2 | TypeScript semantic indexing | MIT / Apache-2.0 | npm via pinned package lock | Isolated Node worker | Integrated |
| Universal Ctags | Broad-language symbol fallback | GPL-2.0-or-later | Not installed | CLI JSON output | Adapter integrated; optional |
| @ast-grep/napi 0.45.1 | Structural JavaScript/TypeScript/JSX/TSX matching | MIT | npm via pinned package lock | Isolated Node worker | Integrated, optional |
| Cytoscape.js 3.34.1 | Interactive graph rendering and path queries | MIT | npm via pinned package lock; minified browser distribution and license copied to `public/assets/lib` | Vanilla browser script | Integrated, required for interactive graph; server-rendered fallback remains |
| Semgrep CLI | Static security analysis | LGPL-2.1-or-later for the engine; rule licenses vary | Not installed | Fixed local high-signal rules via bounded CLI JSON | Adapter integrated; optional |
| Gitleaks 8.30.1 | Filesystem and Maximum-profile Git-history secret detection | MIT | Official GitHub release, verified in tool manifest | Local executable; pinned upstream rules; 100% redacted JSON | Integrated, optional |
| OSV-Scanner 2.5.1 | Lockfile vulnerability lookup | Apache-2.0 | Official GitHub release, verified in tool manifest | Local executable; bounded JSON; call analysis disabled | Integrated, optional |
| Syft 1.51.0 | Declared/resolved/detected package inventory | Apache-2.0 | Official GitHub release, verified in tool manifest | Local executable; local-only enrichment config; Syft JSON | Integrated, optional |
| Grype 0.117.0 | Offline vulnerability confirmation | Apache-2.0 | Official GitHub release, verified in tool manifest | Local executable; auto-update/external sources disabled; JSON | Integrated, optional |

CodeQL is intentionally absent from the executable inventory. A future adapter must remain disabled unless the user supplies an eligible installation and the applicable license permits the intended use.

Downloaded analyzer executables remain outside Git in `tools/bin/`. Their official release sources, verified release-artifact checksums, exact extracted executable hashes, and verification date are pinned in `config/tool-manifest.json`; WTFCode does not silently update them.
