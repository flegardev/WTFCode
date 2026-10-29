# Pilot container audit and remaining review gate

Updated 29 October 2026. **External release remains blocked.** This is an inventory and limited static reachability review, not penetration testing, exploit confirmation or risk acceptance.

## Actual results and changes

Grype 0.117.0 scanned Docker archives of the actual built image. Full results were retained as CI artifacts for seven days; compact before/after findings, image identifiers and full-report SHA-256 hashes are committed under [docs/pilot-container-evidence](docs/pilot-container-evidence).

| Image | Critical matches | High matches | Fixable High/Critical matches |
| --- | --- | --- | --- |
| Initial Bookworm / Node 24.19.0 / advanced binaries bundled | 36 | 253 | 106 |
| Current Trixie / Node 24.22.1 / Quick-only default image | 19 | 144 | 0 |

These are raw package/advisory matches: several installed curl packages produce separate entries for the same CVE. Do not interpret the sum as that many independent demonstrated exploits.

The supported [FrankenPHP Trixie variant](https://frankenphp.dev/docs/docker/) and verified Node 24.22.1 image replace the older runtime. [Node's October security release](https://nodejs.org/en/blog/vulnerability/october-2026-security-releases) documents the relevant patched runtime issues. The pilot does not expose advanced profiles, so unused Gitleaks/OSV/Syft/Grype executables no longer ship by default. Their source providers and pinned host regression tests remain. INCLUDE_SECURITY_ANALYZERS=true preserves an explicit experimental full build, which is outside the approved pilot candidate and needs separate updates/auditing.

The current image is Debian 13.7. All 19 remaining Critical matches have vendor fix state wont-fix in the scanner; High matches are 135 wont-fix and 9 not-fixed. “Wont-fix” here is scanner metadata, not proof of safety or a permanent upstream refusal. The Debian tracker explains these stable-release deferrals. No ignore list, severity editing or suppression was added.

CI fails if a High/Critical match has an available fix; it preserves the full inventory and marks remaining matches requires_triage. A green functional/audit-execution job does not clear this document's release gate.

## The seven distinct Critical IDs

The first six create three package matches each; the libxml2 issue creates one. Debian marks these as minor/no-DSA for the current stable release while the scanner uses a Critical rating. The stable package remains listed as vulnerable. The notes below describe the advisory condition and an inference from inspected application code, not a blanket exception.

| Advisory and primary vendor source | Condition described by the advisory | Inspected PHP path / limitation |
| --- | --- | --- |
| [CVE-2026-19931](https://security-tracker.debian.org/tracker/CVE-2026-19931) | Negotiate-authenticated connection reused after empty credentials | GitHub requests create fresh handles and do not configure Negotiate authentication. Other consumers of the installed library still need review. |
| [CVE-2026-9079](https://security-tracker.debian.org/tracker/CVE-2026-9079) | Clearing/reusing proxy authentication credentials | No proxy-authentication/reset reuse path was found in the inspected application. Verify actual hosted proxy configuration and Git/library consumers. |
| [CVE-2026-11856](https://security-tracker.debian.org/tracker/CVE-2026-11856) | Digest authentication with handle reuse across origins | GitHub requests use new handles, fixed API origins, no Digest configuration and no redirects. This is a code-path observation, not proof about every binary. |
| [CVE-2026-10536](https://security-tracker.debian.org/tracker/CVE-2026-10536) | HTTP/2 stream-dependency configuration followed by reset/cleanup | No stream-dependency or easy-reset call was found in application source. |
| [CVE-2026-18924](https://security-tracker.debian.org/tracker/CVE-2026-18924) | HTTP/2 server push with shared connection handles | No multi/shared-handle or push-callback path was found in the inspected PHP source. |
| [CVE-2026-8926](https://security-tracker.debian.org/tracker/CVE-2026-8926) | netrc lookup and a username-only URL can choose another user's credentials | Repository URLs reject embedded credentials; PHP requests do not enable netrc. The image does not copy a netrc credential store. Recheck any future integrations. |
| [CVE-2026-6653](https://security-tracker.debian.org/tracker/CVE-2026-6653) | Malicious XML/entity processing can trigger libxml2 use-after-free | No DOMDocument/XMLReader/SimpleXML/loadXML entry point was found in src/public/workers. Imported files are statically inspected, not executed. Vendor/library reachability is not exhaustively proven. |

Static review also checked for curl multi handles, Digest/Negotiate/netrc/proxy-authentication options and stream-dependency controls in application code. Keep private imports, new external providers and advanced profiles disabled: adding them can change the attack paths.

## Before external access

1. Review all retained High/Critical IDs, including the High findings not covered by the seven-row table, against the actual release image and configuration. Confirm package status with primary sources; do not dismiss an inventory finding just because a path is not obvious.
2. Prefer maintained stable vendor updates or an appropriately reviewed base image. Do not mix unstable packages or replace shared libraries blindly to obtain a green count.
3. Record either a verified remediation or a specific reviewed residual-risk decision and its assumptions, owner and revisit date. No such approval is asserted here.
4. Rebuild and scan the exact image to be deployed, verify its digest, rerun the full container/native matrix and hosted TLS/resource/account/worker checks. The prepared SMTP adapter, operator identity and backup/alert setup also remain gated.

The CI container test proves reproducible startup, two real public fixtures, metadata export/deletion, production checkout cleanup, cgroup limits and a hard temporary-storage cap. It does not establish a secure public deployment or complete hosted capacity.

