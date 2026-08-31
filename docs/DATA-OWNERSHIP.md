# Data ownership and retention

## Durable state

Supabase PostgreSQL owns the durable application state: users and password hashes, database-backed PHP sessions, login-attempt counters, projects, derived file and dependency metadata, architecture evidence, scan findings, symbol names and signatures, bounded relationship evidence excerpts, routes, provider results/cache, change snapshots, and learning progress.

All project reads and writes are scoped by the authenticated PHP user. Supabase browser roles have no table privileges or RLS policies. The backend database connection is the only intended data path.

## Ephemeral state

Vercel container files are disposable. Hosted repository clones are created under `/tmp/wtfcode/repos` with randomized names, scanned without installing or executing repository code, and removed after the request. Temporary process files and local caches under `/tmp/wtfcode` may disappear on restart and must never be treated as durable state.

Complete source files and repository clones are not persisted in PostgreSQL. The derived graph can contain bounded source-derived evidence such as symbol signatures and relationship excerpts. A source view, Git comparison, or rescan may temporarily clone the same public repository again. This means results can differ if the public repository changes and a historical commit is no longer available in the bounded clone.

## Operator responsibilities

Before public launch, define retention periods, account/project deletion, user export, abuse handling, legal contact, and backup/restore procedures. If an external explanation provider is enabled, disclose that bounded evidence and the user's question leave WTFCode, document the provider and retention terms, and obtain any required consent.
