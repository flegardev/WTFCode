# Alpha performance

Generated from isolated static-analysis runs. Cache hits are reported by providers; a partial run is not counted as complete.

## Quick summary

- Runs: 27; median 2.81s; range 0.40–20.03s.
- Duration outliers (>2× median): adminer, django-framework, drizzle-orm, prisma-examples, turborepo, vercel-ai-chatbot.
- Near the 128 MiB worker ceiling (>=110 MiB): adminer, django-framework, drizzle-orm.
- Runs with disclosed partial reasons: 19.

## Maximum summary

- Runs: 27; median 3.98s; range 0.13–69.63s.
- Duration outliers (>2× median): adminer, chatbot-ui, django-framework, docker-getting-started, drizzle-orm, fastapi-full-stack, turborepo, wtfcode.
- Near the 128 MiB worker ceiling (>=110 MiB): adminer, django-framework, drizzle-orm, turborepo.
- Runs with disclosed partial reasons: 19.

## Per-run evidence

| Repository | Profile | Status | Discovered | Analyzed | Skipped | Duration | Peak MiB | Slowest provider | Partial reason |
|---|---:|---:|---:|---:|---:|---:|---:|---|---|
| adminer | maximum | partial | 277 | 265 | 12 | 16.70s | 114.0 | typescript-semantic | Partial analyzer providers: php-parser, tree-sitter, typescript-semantic.; Graph or enrichment limits: relationship_limit_reached, product_intelligence_limited. |
| adminer | quick | partial | 277 | 265 | 12 | 6.98s | 114.0 | typescript-semantic | Partial analyzer providers: php-parser, tree-sitter, typescript-semantic.; Graph or enrichment limits: relationship_limit_reached, product_intelligence_limited. |
| chatbot-ui | maximum | partial | 315 | 295 | 20 | 10.35s | 76.0 | typescript-semantic | Partial analyzer providers: tree-sitter, typescript-semantic.; Graph or enrichment limits: relationship_limit_reached, product_intelligence_limited. |
| chatbot-ui | quick | partial | 315 | 295 | 20 | 5.59s | 70.0 | typescript-semantic | Partial analyzer providers: tree-sitter, typescript-semantic.; Graph or enrichment limits: relationship_limit_reached, product_intelligence_limited. |
| create-t3-turbo | maximum | partial | 138 | 123 | 15 | 0.29s | 22.0 | wtfcode-native | Partial analyzer providers: tree-sitter. |
| create-t3-turbo | quick | partial | 138 | 123 | 15 | 2.68s | 18.0 | typescript-semantic | Partial analyzer providers: tree-sitter. |
| django-framework | maximum | partial | 7002 | 3000 | 4002 | 49.56s | 126.0 | ripgrep | The scanner stopped after the 3,000-file MVP limit. The results still describe scanned files, but omitted files may affect the application.; Partial analyzer providers: tree-sitter.; Failed analyzer providers: typescript-semantic.; Graph or enrichment limits: symbol_limit_reached, relationship_limit_reached, product_intelligence_limited. |
| django-framework | quick | partial | 7002 | 3000 | 4002 | 13.59s | 126.0 | wtfcode-native | The scanner stopped after the 3,000-file MVP limit. The results still describe scanned files, but omitted files may affect the application.; Partial analyzer providers: tree-sitter.; Failed analyzer providers: typescript-semantic.; Graph or enrichment limits: symbol_limit_reached, relationship_limit_reached, product_intelligence_limited. |
| django-react | maximum | partial | 96 | 83 | 13 | 0.23s | 12.0 | wtfcode-native | Partial analyzer providers: tree-sitter. |
| django-react | quick | partial | 96 | 83 | 13 | 2.16s | 10.0 | tree-sitter | Partial analyzer providers: tree-sitter. |
| docker-getting-started | maximum | success | 83 | 41 | 42 | 8.13s | 32.0 | typescript-semantic | none |
| docker-getting-started | quick | success | 83 | 41 | 42 | 3.37s | 28.0 | typescript-semantic | none |
| drizzle-orm | maximum | partial | 1389 | 1339 | 50 | 9.17s | 116.0 | typescript-semantic | Partial analyzer providers: tree-sitter, ast-grep.; Failed analyzer providers: typescript-semantic.; Graph or enrichment limits: symbol_limit_reached, relationship_limit_reached, product_intelligence_limited. |
| drizzle-orm | quick | partial | 1389 | 1339 | 50 | 20.03s | 112.0 | ast-grep | Partial analyzer providers: tree-sitter, ast-grep.; Failed analyzer providers: typescript-semantic.; Graph or enrichment limits: symbol_limit_reached, relationship_limit_reached, product_intelligence_limited. |
| express-realworld | maximum | partial | 66 | 57 | 9 | 5.91s | 12.0 | grype | Partial analyzer providers: tree-sitter.; Failed analyzer providers: typescript-semantic. |
| express-realworld | quick | partial | 66 | 57 | 9 | 1.84s | 10.0 | typescript-semantic | Partial analyzer providers: tree-sitter.; Failed analyzer providers: typescript-semantic. |
| fastapi-full-stack | maximum | partial | 252 | 219 | 33 | 10.75s | 58.0 | grype | Partial analyzer providers: tree-sitter. |
| fastapi-full-stack | quick | partial | 252 | 219 | 33 | 4.32s | 56.0 | typescript-semantic | Partial analyzer providers: tree-sitter. |
| firebase-quickstarts | maximum | partial | 413 | 324 | 89 | 2.27s | 42.0 | typescript-semantic | Partial analyzer providers: tree-sitter.; Failed analyzer providers: typescript-semantic. |
| firebase-quickstarts | quick | partial | 413 | 324 | 89 | 4.03s | 36.0 | typescript-semantic | Partial analyzer providers: tree-sitter.; Failed analyzer providers: typescript-semantic. |
| flask-framework | maximum | partial | 236 | 131 | 105 | 0.77s | 54.0 | wtfcode-native | Partial analyzer providers: tree-sitter.; Graph or enrichment limits: relationship_limit_reached, product_intelligence_limited. |
| flask-framework | quick | partial | 236 | 131 | 105 | 1.23s | 56.0 | tree-sitter | Partial analyzer providers: tree-sitter.; Graph or enrichment limits: relationship_limit_reached, product_intelligence_limited. |
| langchain-nextjs | maximum | success | 65 | 52 | 13 | 0.24s | 14.0 | wtfcode-native | none |
| langchain-nextjs | quick | success | 65 | 52 | 13 | 2.81s | 14.0 | typescript-semantic | none |
| laravel-starter | maximum | partial | 53 | 42 | 11 | 5.59s | 14.0 | grype | Partial analyzer providers: tree-sitter. |
| laravel-starter | quick | partial | 53 | 42 | 11 | 1.60s | 14.0 | typescript-semantic | Partial analyzer providers: tree-sitter. |
| laravel-vue-ai-native | maximum | partial | 292 | 276 | 16 | 1.48s | 30.0 | typescript-semantic | Partial analyzer providers: tree-sitter.; Failed analyzer providers: typescript-semantic. |
| laravel-vue-ai-native | quick | partial | 292 | 276 | 16 | 2.39s | 28.0 | typescript-semantic | Partial analyzer providers: tree-sitter.; Failed analyzer providers: typescript-semantic. |
| microservices-demo | maximum | partial | 364 | 255 | 109 | 1.91s | 38.0 | typescript-semantic | Partial analyzer providers: tree-sitter.; Failed analyzer providers: typescript-semantic.; Graph or enrichment limits: relationship_limit_reached, product_intelligence_limited. |
| microservices-demo | quick | partial | 364 | 255 | 109 | 2.93s | 36.0 | typescript-semantic | Partial analyzer providers: tree-sitter.; Failed analyzer providers: typescript-semantic.; Graph or enrichment limits: relationship_limit_reached, product_intelligence_limited. |
| nestjs-starter | maximum | success | 16 | 12 | 4 | 0.15s | 4.0 | wtfcode-native | none |
| nestjs-starter | quick | success | 16 | 12 | 4 | 1.56s | 6.0 | typescript-semantic | none |
| next-postgres-auth | maximum | success | 45 | 41 | 4 | 6.66s | 14.0 | grype | none |
| next-postgres-auth | quick | success | 45 | 41 | 4 | 2.43s | 10.0 | tree-sitter | none |
| nuxt-starter | maximum | success | 35 | 31 | 4 | 0.13s | 4.0 | wtfcode-native | none |
| nuxt-starter | quick | success | 35 | 31 | 4 | 0.40s | 6.0 | tree-sitter | none |
| prisma-examples | maximum | partial | 1390 | 1061 | 329 | 3.98s | 58.0 | typescript-semantic | Partial analyzer providers: tree-sitter.; Failed analyzer providers: typescript-semantic.; Graph or enrichment limits: product_intelligence_limited. |
| prisma-examples | quick | partial | 1390 | 1061 | 329 | 6.78s | 58.0 | typescript-semantic | Partial analyzer providers: tree-sitter.; Failed analyzer providers: typescript-semantic.; Graph or enrichment limits: product_intelligence_limited. |
| react-crud | maximum | partial | 24 | 16 | 8 | 6.43s | 24.0 | grype | Failed analyzer providers: typescript-semantic. |
| react-crud | quick | partial | 24 | 16 | 8 | 1.38s | 8.0 | typescript-semantic | Failed analyzer providers: typescript-semantic. |
| realworld-index | maximum | partial | 328 | 75 | 253 | 0.34s | 40.0 | wtfcode-native | Partial analyzer providers: tree-sitter. |
| realworld-index | quick | partial | 328 | 75 | 253 | 2.84s | 38.0 | typescript-semantic | Partial analyzer providers: tree-sitter. |
| supabase-payments | maximum | success | 90 | 73 | 17 | 6.73s | 16.0 | grype | none |
| supabase-payments | quick | success | 90 | 73 | 17 | 2.72s | 16.0 | typescript-semantic | none |
| svelte-realworld | maximum | success | 62 | 51 | 11 | 0.20s | 8.0 | wtfcode-native | none |
| svelte-realworld | quick | success | 62 | 51 | 11 | 1.55s | 8.0 | typescript-semantic | none |
| turborepo | maximum | partial | 5541 | 3000 | 2541 | 69.63s | 124.0 | grype | The scanner stopped after the 3,000-file MVP limit. The results still describe scanned files, but omitted files may affect the application.; Partial analyzer providers: tree-sitter.; Failed analyzer providers: typescript-semantic, syft.; Graph or enrichment limits: product_intelligence_limited. |
| turborepo | quick | partial | 5541 | 3000 | 2541 | 9.36s | 78.0 | typescript-semantic | The scanner stopped after the 3,000-file MVP limit. The results still describe scanned files, but omitted files may affect the application.; Partial analyzer providers: tree-sitter.; Failed analyzer providers: typescript-semantic.; Graph or enrichment limits: product_intelligence_limited. |
| vercel-ai-chatbot | maximum | partial | 179 | 166 | 13 | 1.79s | 50.0 | typescript-semantic | Partial analyzer providers: tree-sitter.; Failed analyzer providers: typescript-semantic. |
| vercel-ai-chatbot | quick | partial | 179 | 166 | 13 | 6.54s | 46.0 | typescript-semantic | Partial analyzer providers: tree-sitter.; Failed analyzer providers: typescript-semantic. |
| vitesse | maximum | success | 79 | 66 | 13 | 0.33s | 16.0 | wtfcode-native | none |
| vitesse | quick | success | 79 | 66 | 13 | 2.07s | 14.0 | typescript-semantic | none |
| wtfcode | maximum | partial | 193 | 182 | 11 | 12.37s | 74.0 | tree-sitter | Partial analyzer providers: tree-sitter.; Graph or enrichment limits: relationship_limit_reached, product_intelligence_limited. |
| wtfcode | quick | partial | 193 | 182 | 11 | 4.52s | 74.0 | tree-sitter | Partial analyzer providers: tree-sitter.; Graph or enrichment limits: relationship_limit_reached, product_intelligence_limited. |
