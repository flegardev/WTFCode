# Alpha scorecard — react-crud

- Repository: https://github.com/bezkoder/react-crud-web-api.git
- Commit: `8498b60d12cdbd7d3ada076fc5bb70a3eedc55e0`
- Reviewer: OpenAI Codex, manual static/source review
- Review date: 2026-08-20

- Stack accuracy (1–5): 5
- Architecture accuracy (1–5): 3
- Feature accuracy (1–5): 2
- Route accuracy (1–5): 5
- Database understanding (1–5): 3
- Service understanding (1–5): 2
- Feature tracing (1–5): 2
- Blast radius usefulness (1–5): 2
- Git/change explanation (1–5): 2
- Security usefulness (1–5): 2
- Explanation clarity (1–5): 3
- Evidence quality (1–5): 3
- False-positive control (1–5): 5
- Overall usefulness (1–5): 3

## Primary usefulness question

Did WTFCode teach you something useful that you did not understand before? **Somewhat**

What did it teach you?

It produced the exact client route map (`/`, `/tutorials`, `/add`, `/tutorials/:id`) immediately and correctly avoided inventing registration from service-worker terminology.

## Trust question

Would you trust WTFCode before asking an AI coding agent to change this repository? **Not yet**

Reason:

The frontend and routes are right, but the main CRUD behavior and localhost API client are under-explained. A change agent still needs to read the three components and HTTP service.

## Confusion and truthfulness

What output confused you the most?

The Quick run is partial only because the TypeScript semantic provider failed on a JavaScript app; that is safe isolation, but the banner needs to explain the practical impact.

What did WTFCode confidently say that turned out to be wrong?

Nothing in the calibrated run. Earlier output falsely called service-worker setup user Registration; ALPHA-007 fixed the general term ambiguity.

## Manual probes

- Relevant feature traces and hop review: CRUD is a false negative; no fabricated feature trace was accepted.
- Where-does-this-button-go sample: Add and tutorial-detail routes are correct, but component-to-HTTP-to-backend flow is incomplete.
- Blast-radius sample: Component imports are visible; transitive impact is too shallow to be decisive.
- Can-I-delete-this sample: Shared HTTP/service files must remain “possible use” when aliases or runtime behavior are unresolved.
- Database/auth/service checks: No local database or auth exists; the remote tutorial API deserves clearer treatment.
- Confidence calibration samples: All four high-confidence React Router paths are source-confirmed.
- Explanation-mode comparison: Understandable, but sparse because the feature catalogue misses CRUD.
- Test recommendation quality: Should recommend list, add, update, delete, publish filter, and API-error flows.
- Safe prompt quality: File names are real; scope guidance is useful but incomplete around the backend contract.

## Product value

- Approximate import-to-first-useful-insight time: about 2 seconds
- Best insight WTFCode found: The entire navigable UI is four client routes in one `App.js` switch.
- Value category: UNDERSTANDING

Notes:

This is a useful negative case: false-positive control is strong, coverage is not.
