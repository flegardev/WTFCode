# Five-person pilot research

Status: preparation only. No participants recruited, invitations sent, feedback collected or willingness-to-pay results asserted.

## Recruitment and consent

After the launch checklist passes and the founder approves outreach, invite five adults building an application with an AI coding tool: a mix of non-technical founders, indie builders and less experienced developers. Each must own or have permission to analyze a small public GitHub repository. Exclude confidential, private, oversized and intentionally malicious repositories. Select people willing to criticize the product; friends' politeness is not validation.

Keep the five approved email addresses in private configuration, not this repository. Use P01–P05 in research notes. Explain the operator, storage, export/deletion controls, optional feedback and retention before participation. Ask permission separately for screen recording; default to written notes. Do not record credentials or source code. Agree a support channel and a reasonable response window. Do not promise continuous availability.

## Session script (35–45 minutes)

Opening: “We are testing whether the product helps you understand your app. This is a test of the product, not you. Please say what is confusing. You can stop, skip a question or delete your account. We will not ask for passwords or private code.”

1. Ask what they believe their app does and one change they want to make. Record their expected behavior as a reference; do not invent a business purpose from framework detection.
2. Ask them to visit the app, register with the approved address, verify their email and sign in. Observe the real email inbox only with consent; never copy their token into notes.
3. Ask them to import their public repository and follow the real scan. Time from submission to usable results separately from task time. Pause the session for an operational failure rather than coaching around it.
4. Give tasks A–E below, one at a time. Start a timer at the question and stop when the participant identifies an answer they can explain with evidence. Record success, partial success, failure or skipped, and every moderator hint.
5. Offer optional structured in-app feedback. Show export and deletion; let the participant choose whether to retain their account for a repeat visit.

| Task | Neutral prompt | Evidence of success |
| --- | --- | --- |
| A | In your own words, what does this imported application do? | A useful explanation consistent with the owner's stated behavior and actual source. They distinguish unknown business purpose from detected structure. |
| B | Find an important part of its architecture and explain what it connects to. | Identifies a concrete component and follows a supported relationship; does not mistake the graph for runtime proof. |
| C | Find where a feature you care about is implemented. | Asks a useful question, follows a numbered source reference and identifies a relevant file/symbol. Human review checks technical accuracy. |
| D | Choose a real change and explain what you would inspect before editing. | Uses Change Impact, identifies relevant evidence and acknowledges that the result cannot guarantee breakage or safety. |
| E | Would you choose to use this again? Why or why not? | Candid reason, a specific repeat-use situation or a specific reason to stop. “No” is useful evidence. |

Follow-up questions: What was hardest to understand? Which explanation seemed wrong? What did you trust and why? What would you use instead? What would make you return next week? Only after discussing value: Would you pay anything for this? If so, for what outcome and at what price? Present €19/month and €9/report as hypotheses after the unaided answer, with no checkout or payment request.

## Tracking template (blank, no fabricated observations)

| Participant | Consent / date | Public repo permission checked | Onboarding time | Scan outcome / duration | A outcome / seconds / hints | B outcome / seconds / hints | C outcome / seconds / hints | D outcome / seconds / hints | E repeat intent | Accuracy concern / confusion | Unaided willingness to pay | Follow-up approved |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| P01 | | | | | | | | | | | | |
| P02 | | | | | | | | | | | | |
| P03 | | | | | | | | | | | | |
| P04 | | | | | | | | | | | | |
| P05 | | | | | | | | | | | | |

Store contact details separately with restricted access. Research notes are proposed for 30-day retention unless the operator and participants agree otherwise; do not collect them until that policy is approved. Correct or delete notes on request. Never publish identifiable quotes without separate approval.

## Proposed decision rules, not measured results

Aim for at least four of five participants to complete A–C without moderator intervention and reach their first useful source-backed answer within five minutes after scan completion. Every participant should understand the uncertainty in D. Three of five expressing a concrete repeat-use need is a reason to run another small iteration, not proof of product-market fit. Interest in paying requires a later voluntary commercial test after business/billing approval. Zero critical isolation, deletion, account or evidence failures is a release gate.

Track invited addresses from the allowlist, completed/failed scans and durations from the private operator CLI, and task/repeat/payment intent from consented notes. Do not infer returning participants or successful learning from page views. No third-party tracking is introduced.

## A sustainable 20-hour week

Budget four hours for five sessions, six for the most important fixes, three for operations/backup checks, two for support, three for analysis and two as a buffer. Tell participants the actual support hours and expected response time after the founder approves them. For a serious privacy/security failure: close workspace access, preserve sanitized evidence, pause sessions and follow the incident procedure. Outreach and incident messages require the founder's authorization.
