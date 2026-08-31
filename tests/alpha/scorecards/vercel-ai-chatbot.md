# Alpha scorecard — vercel-ai-chatbot

- Repository: https://github.com/vercel/ai-chatbot.git
- Commit: `c2f8235e1f3ea903ad8b7f61447c4f74164b5c58`
- Reviewer: OpenAI Codex, manual source, trace, blast-radius, prompt, and deletion review
- Review date: 2026-08-20

- Stack accuracy (1–5): 4
- Architecture accuracy (1–5): 4
- Feature accuracy (1–5): 5
- Route accuracy (1–5): 5
- Database understanding (1–5): 4
- Service understanding (1–5): 4
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

It revealed a 20-route surface behind the chat UI: guest/auth routes, chat and stream endpoints, document/history/message/model/suggestion/vote APIs, and a separate upload route.

## Trust question

Would you trust WTFCode before asking an AI coding agent to change this repository? **Mostly**

Reason:

Routes and feature entrypoints are strong, but TypeScript semantic analysis failed and AI-provider/data-flow traces do not yet prove where every prompt field and response travels.

## Confusion and truthfulness

What output confused you the most?

The AI chat trace starts with some auth-layout and icon symbols before reaching core chat code; relevance ordering needs work.

What did WTFCode confidently say that turned out to be wrong?

ALPHA-019 initially answered a full-path deletion question about the chat `route.ts` using the auth `route.ts`. Exact-path resolution now selects the requested file.

## Manual probes

- Relevant feature traces and hop review: AI chat, login, and upload entrypoints are correct; prompt/provider/response endpoints are incomplete.
- Where-does-this-button-go sample: File upload reaches POST `/api/files/upload`; chat submission reaches POST `/api/chat`, with deeper provider flow only partly resolved.
- Blast-radius sample: `AUTH_SECRET` shows a medium-risk route/environment effect; sampled type aliases produce low-value low-risk results.
- Can-I-delete-this sample: The chat route is now exactly resolved and classified as possible convention use, never safe-to-delete.
- Database/auth/service checks: Chat/Document/Message/Stream/Suggestion/User/Vote tables, Postgres/Redis, and Auth.js routes are source-confirmed.
- Confidence calibration samples: Strong AI chat/Login/Upload and high route paths are correct.
- Explanation-mode comparison: Vibe describes chat/auth/upload roles; technical mode names route handlers, environment, and persistence.
- Test recommendation quality: Guest/auth chat, streaming, upload validation, history/document deletion, votes, and model selection are relevant.
- Safe prompt quality: An auth-change prompt cited real auth/actions/config/model files and warned about guest routes, schema, secrets, and deployment.

## Product value

- Approximate import-to-first-useful-insight time: about 4 seconds
- Best insight WTFCode found: The “chat app” is also a document/history/voting/upload system with distinct API and persistence boundaries.
- Value category: UNDERSTANDING

Notes:

The app's provider abstraction is a remaining false-negative area: model/provider data flow needs a more useful bounded trace.
