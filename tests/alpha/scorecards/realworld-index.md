# Alpha scorecard — realworld-index

- Repository: https://github.com/gothinkster/realworld.git
- Commit: `5d510ce6ec41bb97723e92fbd8d3e3458a381c09`
- Reviewer: OpenAI Codex, manual negative-control review
- Review date: 2026-08-20

- Stack accuracy (1–5): 5
- Architecture accuracy (1–5): 5
- Feature accuracy (1–5): 5
- Route accuracy (1–5): 5
- Database understanding (1–5): 5
- Service understanding (1–5): 5
- Feature tracing (1–5): 2
- Blast radius usefulness (1–5): 2
- Git/change explanation (1–5): 2
- Security usefulness (1–5): 3
- Explanation clarity (1–5): 4
- Evidence quality (1–5): 5
- False-positive control (1–5): 5
- Overall usefulness (1–5): 3

## Primary usefulness question

Did WTFCode teach you something useful that you did not understand before? **Somewhat**

What did it teach you?

Its useful behavior was restraint: this documentation/specification index is not presented as a runnable Login/Profile/Search application.

## Trust question

Would you trust WTFCode before asking an AI coding agent to change this repository? **Mostly**

Reason:

The final empty runtime stack/features/services is correct. The partial analyzer status still needs reading because absence here is a negative-control result, not proof about every linked external implementation.

## Confusion and truthfulness

What output confused you the most?

The overview is necessarily sparse and offers little guidance about the repository's specification/catalogue purpose.

What did WTFCode confidently say that turned out to be wrong?

Before ALPHA-012, e2e specs and generator tooling produced strong Profile/Login/Search features. The regression removes those runtime claims.

## Manual probes

- Relevant feature traces and hop review: No runtime trace was attempted because this repository is a specification/index.
- Where-does-this-button-go sample: Not applicable; inventing one would be a failure.
- Blast-radius sample: Not useful for linked implementations outside the repository.
- Can-I-delete-this sample: Documentation/configuration files require conservative wording; external link use is not captured as runtime dependence.
- Database/auth/service checks: No runtime database/auth/service claim was accepted.
- Confidence calibration samples: The absence of runtime claims is manually correct.
- Explanation-mode comparison: Both should explicitly describe the specification/index role in future.
- Test recommendation quality: Link validation/spec tests are relevant; application login tests are not.
- Safe prompt quality: Should keep changes scoped to specification/docs rather than invent app code.

## Product value

- Approximate import-to-first-useful-insight time: about 3 seconds
- Best insight WTFCode found: This repository describes implementations; it is not itself one of those runtime applications.
- Value category: LEARNING

Notes:

This negative control is important evidence for false-positive resistance, not a high-value application analysis.
