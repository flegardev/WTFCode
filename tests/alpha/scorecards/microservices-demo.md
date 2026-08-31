# Alpha scorecard — microservices-demo

- Repository: https://github.com/GoogleCloudPlatform/microservices-demo.git
- Commit: `34ffea9175946982c3088ed84994fe6019ad6e92`
- Reviewer: OpenAI Codex, manual static/source review
- Review date: 2026-08-20

- Stack accuracy (1–5): 2
- Architecture accuracy (1–5): 1
- Feature accuracy (1–5): 3
- Route accuracy (1–5): 1
- Database understanding (1–5): 1
- Service understanding (1–5): 2
- Feature tracing (1–5): 1
- Blast radius usefulness (1–5): 1
- Git/change explanation (1–5): 2
- Security usefulness (1–5): 2
- Explanation clarity (1–5): 2
- Evidence quality (1–5): 2
- False-positive control (1–5): 4
- Overall usefulness (1–5): 2

## Primary usefulness question

Did WTFCode teach you something useful that you did not understand before? **No**

What did it teach you?

It confirmed Docker/Kubernetes and found checkout/search signals, but those were already obvious. It did not explain the Go/gRPC service topology that defines the application.

## Trust question

Would you trust WTFCode before asking an AI coding agent to change this repository? **No**

Reason:

The summary centers a small Flask shopping-assistant service and one route, missing the polyglot microservice architecture, gRPC boundaries, and cross-service call graph.

## Confusion and truthfulness

What output confused you the most?

Calling this a “Flask application” is locally true but globally misleading for the repository.

What did WTFCode confidently say that turned out to be wrong?

No single fabricated fact remained after support-path fixes; the failure is missing context and architecture dominance.

## Manual probes

- Relevant feature traces and hop review: Checkout/Search evidence is real, but load-generator evidence was removed and service hops remain largely absent.
- Where-does-this-button-go sample: Frontend-to-checkout gRPC flow could not be reconstructed usefully.
- Blast-radius sample: Central service/API symbols are not normalized well enough for a trustworthy result.
- Can-I-delete-this sample: Kubernetes/service files should be treated as convention/configuration-sensitive; no graph-based safety conclusion is adequate.
- Database/auth/service checks: Kubernetes and some Postgres signals are visible; service-specific storage and gRPC contracts are not explained.
- Confidence calibration samples: Likely Search and strong Checkout are correct but incomplete.
- Explanation-mode comparison: Both modes inherit the wrong high-level emphasis.
- Test recommendation quality: Generic repository checks are less useful than service contract, gRPC, checkout, cart, and end-to-end recommendations.
- Safe prompt quality: Would cite some real files but lacks the service map needed to bound a safe cross-service change.

## Product value

- Approximate import-to-first-useful-insight time: no genuinely useful new insight in the reviewed run
- Best insight WTFCode found: None beyond confirming deployment-heavy checkout/search code.
- Value category: UNDERSTANDING

Notes:

This is an accepted Alpha limitation, not evidence that the corpus scan “passed” semantically.
