# Confidence calibration

Manual sample: 25 claims across 10 pinned repositories, including six claims captured before their Alpha fixes. This is a deliberately selected bug-finding sample, not a random sample and not statistically significant.

| Repository | Fact | Reported confidence | Manual truth | Classification |
|---|---|---|---|---|
| next-postgres-auth | Login feature | strong | correct | strong-but-correct |
| next-postgres-auth | Search feature | strong | correct | strong-but-correct |
| next-postgres-auth | Postgres service | likely | correct | likely-but-correct |
| next-postgres-auth (pre-fix) | AWS runtime service from lockfile | likely | wrong | likely-but-wrong |
| react-crud | `/tutorials` route | likely | correct | likely-but-correct |
| react-crud | `/tutorials/:id` route | likely | correct | likely-but-correct |
| react-crud (pre-fix) | Registration feature from service worker | strong | wrong | strong-but-wrong |
| express-realworld | Login feature | strong | correct | strong-but-correct |
| express-realworld | Profile feature | strong | correct | strong-but-correct |
| express-realworld | POST `/users/login` | likely | correct | likely-but-correct |
| fastapi-full-stack | Login feature | strong | correct | strong-but-correct |
| fastapi-full-stack | Registration feature | strong | correct | strong-but-correct |
| fastapi-full-stack | Admin feature | strong | correct | strong-but-correct |
| fastapi-full-stack | Search after support-path fix | strong | correct | strong-but-correct |
| flask-framework (pre-fix) | FastAPI framework identity | high/strong | wrong | strong-but-wrong |
| supabase-payments | Payments feature | strong | correct | strong-but-correct |
| supabase-payments | Checkout feature | likely | correct | likely-but-correct |
| supabase-payments (pre-fix) | AWS runtime service from config comment | likely | wrong | likely-but-wrong |
| supabase-payments (pre-fix) | Kubernetes from Stripe `apiVersion` | likely | wrong | likely-but-wrong |
| realworld-index (pre-fix) | Login feature from specs | strong | wrong | strong-but-wrong |
| vercel-ai-chatbot | AI chat feature | strong | correct | strong-but-correct |
| vercel-ai-chatbot | Upload feature | strong | correct | strong-but-correct |
| microservices-demo | Checkout feature after load-generator exclusion | strong | correct but incomplete | strong-but-correct |
| microservices-demo | Search feature | likely | correct but incomplete | likely-but-correct |
| WTFCode self-dogfood | fused `Auth` symbol identity | confirmed | correct | confirmed-but-correct |

## Observations

- Strong was not trustworthy until specs, generators, support scripts, and ambiguous vocabulary were excluded. Those were evidence-boundary defects, not threshold defects.
- Likely service claims were the weakest sampled area. Lockfiles, comments, metadata URLs, and generic `apiVersion` fields all produced real-repository false positives before ALPHA-013/014/016.
- After the fixes, none of the 19 resampled claims above was manually disproved. That is useful regression evidence, not a precision estimate.
- “Correct but incomplete” matters: Checkout on the microservices demo is true, while the repository-level architecture remains misleadingly incomplete.
- The bounded Alpha summaries expose strong and likely most often. Heuristic facts were not represented clearly enough to sample from the run artifact; that is a reporting limitation. Confirmed is available on fused graph evidence but rare at the product-summary layer.

## Calibration recommendation

Keep `confirmed` reserved for independent evidence agreement. Keep `strong` dependent on runtime-path plus graph/route corroboration. Treat `likely` service claims as review prompts, not settled runtime boundaries. UI copy should continue to disclose that static proof can be incomplete.
