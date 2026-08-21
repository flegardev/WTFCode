# WTFCode GitHub App setup

WTFCode uses a GitHub App for read-only private repository access. It does not accept personal access tokens and does not persist installation access tokens.

## GitHub App settings

Create the App under the GitHub account or organization that will own it.

- GitHub App name: `WTFCode` (or another globally unique display name)
- Homepage URL: `https://wtf-code.vercel.app`
- Callback URL: `https://wtf-code.vercel.app/github-callback.php`
- Expire user authorization tokens: enabled
- Request user authorization (OAuth) during installation: enabled
- Setup URL: leave empty
- Redirect on update: disabled
- Webhook: disabled; WTFCode validates authorization before every repository operation
- Repository permissions:
  - Contents: Read-only
  - Metadata: Read-only (GitHub grants this automatically)
- Organization permissions: none
- Account permissions: none
- Where can this GitHub App be installed: Any account

After creation, generate one private key. Store the downloaded key only in the production secret manager, then remove any unnecessary local copy after Vercel is configured.

## Vercel production variables

Configure these server-only variables for the Production environment:

```text
GITHUB_APP_ID=<numeric App ID>
GITHUB_APP_SLUG=<slug from the App public URL>
GITHUB_APP_CLIENT_ID=<GitHub App client ID>
GITHUB_APP_CLIENT_SECRET=<GitHub App client secret>
GITHUB_APP_PRIVATE_KEY=<complete PEM private key>
GITHUB_APP_CALLBACK_URL=https://wtf-code.vercel.app/github-callback.php
```

`GITHUB_APP_CLIENT_SECRET` and `GITHUB_APP_PRIVATE_KEY` must be sensitive variables. Paste the PEM with real line breaks when possible. WTFCode also accepts Vercel values containing literal `\n` sequences and converts those server-side.

Never expose these values in browser JavaScript or commit them to an environment file.

## Token lifecycle

The callback uses a GitHub user authorization token only long enough to prove that the authenticated GitHub user can access the installation. That token is never persisted. Each repository listing, clone, re-analysis, rescan, or Git-diff rehydration creates a fresh installation token. Repository operations scope the token to the selected repository ID. WTFCode passes clone credentials through an isolated `GIT_ASKPASS` process environment, removes the helper immediately, and revokes the installation token after the operation.
