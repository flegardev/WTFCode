# Alpha scorecard — supabase-payments

- Repository: https://github.com/supabase-community/nextjs-subscription-payments.git
- Commit: `3aa0d956fb46dda45a6676f74ffa77eb0fe10a11`
- Reviewer: OpenAI Codex, manual source, trace, blast-radius, prompt, and deletion review
- Review date: 2026-08-20

- Stack accuracy (1–5): 5
- Architecture accuracy (1–5): 5
- Feature accuracy (1–5): 5
- Route accuracy (1–5): 5
- Database understanding (1–5): 4
- Service understanding (1–5): 5
- Feature tracing (1–5): 3
- Blast radius usefulness (1–5): 3
- Git/change explanation (1–5): 2
- Security usefulness (1–5): 4
- Explanation clarity (1–5): 4
- Evidence quality (1–5): 4
- False-positive control (1–5): 5
- Overall usefulness (1–5): 4

## Primary usefulness question

Did WTFCode teach you something useful that you did not understand before? **Yes**

What did it teach you?

It showed that checkout is only one edge of a wider payment system: pricing UI, Stripe checkout, webhook processing, Supabase admin writes, customer portal, and six durable tables.

## Trust question

Would you trust WTFCode before asking an AI coding agent to change this repository? **Mostly**

Reason:

The complete Quick run, real routes/files, and corrected Stripe/Supabase service boundaries are strong. Traces hit an 80-hop presentation cap and do not always reach the final table/service symbol.

## Confusion and truthfulness

What output confused you the most?

The trace finds the right checkout/payment symbols but often leaves tables/services empty despite known static service symbols; the high-level answer is better than the hop narrative.

What did WTFCode confidently say that turned out to be wrong?

Before ALPHA-016 it called an S3 config comment AWS, a plugin metadata URL github.com, and Stripe's `apiVersion` Kubernetes. The final run reports only Stripe and Supabase.

## Manual probes

- Relevant feature traces and hop review: Login, registration, checkout, payments, and billing start correctly; missing terminal table/service hops are explicit limitations.
- Where-does-this-button-go sample: Pricing checkout maps to `handleStripeCheckout` then `checkoutWithStripe`; webhook/database aftermath is only partially connected.
- Blast-radius sample: Stripe symbols show medium risk and Supabase/Stripe effects, but only one direct dependent in sampled cases.
- Can-I-delete-this sample: `utils/supabase/admin.ts` returns “no detected use” plus an explicit not-safe warning; this is conservative but misses imports.
- Database/auth/service checks: Stripe, Supabase auth/admin, customers/prices/products/users/subscriptions are source-confirmed.
- Confidence calibration samples: Strong Payments/Admin/Registration/Login/Billing are correct; likely Checkout is correct.
- Explanation-mode comparison: Vibe explains subscription/customer flow; technical mode names webhook/admin-client boundaries.
- Test recommendation quality: Checkout, webhook signature/idempotency, customer portal, auth, RLS/data sync, and subscription lifecycle are relevant.
- Safe prompt quality: Real files, auth/schema warnings, small scope, and evidence-backed constraints were produced.

## Product value

- Approximate import-to-first-useful-insight time: about 3 seconds
- Best insight WTFCode found: The Stripe webhook and Supabase admin client are the real consistency boundary, not the checkout button.
- Value category: RISK

Notes:

This repository drove four general false-positive fixes and the conservative deletion-answer work.
