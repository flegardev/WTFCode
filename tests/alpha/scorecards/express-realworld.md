# Alpha scorecard — express-realworld

- Repository: https://github.com/gothinkster/node-express-realworld-example-app.git
- Commit: `30b68e1e881462b2f4164ea09ab4c4f5699c7b0b`
- Reviewer: OpenAI Codex, manual static/source review
- Review date: 2026-08-20

- Stack accuracy (1–5): 5
- Architecture accuracy (1–5): 4
- Feature accuracy (1–5): 4
- Route accuracy (1–5): 4
- Database understanding (1–5): 4
- Service understanding (1–5): 4
- Feature tracing (1–5): 3
- Blast radius usefulness (1–5): 3
- Git/change explanation (1–5): 2
- Security usefulness (1–5): 3
- Explanation clarity (1–5): 4
- Evidence quality (1–5): 4
- False-positive control (1–5): 5
- Overall usefulness (1–5): 4

## Primary usefulness question

Did WTFCode teach you something useful that you did not understand before? **Yes**

What did it teach you?

It exposed the Express/Prisma/Docker boundary, eight main routes, JWT authentication surface, and the two implicit Prisma join tables without requiring prior Nx familiarity.

## Trust question

Would you trust WTFCode before asking an AI coding agent to change this repository? **Mostly**

Reason:

The routes, models, Profile/Login clusters, and database entities match source. Tree-sitter is partial and TypeScript semantic analysis failed, so I would verify deeper calls manually.

## Confusion and truthfulness

What output confused you the most?

The generic “Node API” label hid Express until ALPHA-020; the fixed stack keeps both the category and framework.

What did WTFCode confidently say that turned out to be wrong?

No calibrated claim was wrong. The important issue was the omitted Express identity, not a fabricated framework.

## Manual probes

- Relevant feature traces and hop review: Login reaches the correct controller/service; some Prisma/JWT calls remain unresolved.
- Where-does-this-button-go sample: This is API-only; POST `/users/login` correctly maps to `login()` and GET `/user` shows auth middleware.
- Blast-radius sample: Auth middleware and Prisma model dependents are useful but incomplete under provider failure.
- Can-I-delete-this sample: Auth middleware/model files have confirmed or convention-sensitive use and must not be called safe.
- Database/auth/service checks: User, Article, Comment, Tag, favorites/follows, JWT middleware, and Prisma are source-confirmed.
- Confidence calibration samples: High route confidence and strong Login/Profile are correct.
- Explanation-mode comparison: Vibe “gatekeeper” and technical JWT/middleware wording are distinct.
- Test recommendation quality: Login, JWT rejection, current-user, update-user, article/profile, and database tests are relevant.
- Safe prompt quality: Preserves route/auth/schema contracts and references real controllers/services.

## Product value

- Approximate import-to-first-useful-insight time: about 2 seconds
- Best insight WTFCode found: The social relationships live in Prisma join tables even though the route layer presents them as follows/favorites.
- Value category: ARCHITECTURE

Notes:

The public benchmark independently passed with both Express and Node API required.
