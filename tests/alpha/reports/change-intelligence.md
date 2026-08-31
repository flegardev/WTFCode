# Alpha change-intelligence torture test

These checks use real WTFCode history plus the disposable Before/After fixture in `tests/V3ChangeTest.php`. Results are static evidence and are manually compared with Git; “potential scope drift” is deliberately not presented as proof of developer intent.

## Real pair: false-positive detector fix

- Pair: `2b47b8b359ddb72429024dd9d32f4854ab7e7b66` → `e4e0bc55f66c777644c9b2dff85194944d410390`
- Declared intent: Fix SQL table detection.
- Git truth: three changed files in application code and tests: the analysis version, the SQL evidence filter, and its regression.
- WTFCode result: three files across two areas, no high-risk path pattern, no route/schema/dependency/env/security/architecture fact delta, and no potential scope drift after ALPHA-029.
- Manual verdict: correct. A detector implementation change should not be misreported as an application schema change.

## Real pair: semantic AST checkpoint

- Pair: `8ea92764ab68bcae4c0014f95a4d4a321cf6e54f` → `8f62122ee88df537df524ff956430e33c0b0dc05`
- Declared intent: Add semantic AST dependencies.
- Git truth: 26 files across application code, documentation, configuration/dependencies, and tests.
- WTFCode semantic delta: five dependencies added, one security boundary added and one removed, and one architecture area added. No route, schema, or environment delta was claimed.
- Scope result: documentation remains labeled **Potential scope drift** because it is outside the dependency intent. The output explicitly says this static comparison “does not prove” an intent violation.
- Manual verdict: the semantic delta is useful and consistent with Git. The documentation flag is conservative review guidance, not evidence of wrongdoing. ALPHA-030 fixed the plural `dependencies` intent being misclassified.

## Broad Alpha checkpoint

- Pair: frozen V3 `3cece8a96d12badeac609cf84497a35c086fa86b` → hardened code checkpoint `d6ac66ba7a3d608f103908b83fdc79ea0f39635e`.
- WTFCode reports 65 changed files across seven areas and four conservative high-risk path patterns. Its bounded semantic summary reports two routes added, 54 security-boundary facts added and one removed, and four architecture areas added.
- Manual Git review confirms the breadth is expected for the Alpha brief: corpus/harness work, evidence-policy fixes, UI clarity, cache tests, and regression coverage. The 54 security facts are changed auth/process/upload boundary evidence in source and fixtures; they are not 54 confirmed vulnerabilities.
- The narrow intent parser labels several areas as potential drift. Manual verdict: this is expected caution for an intentionally broad multi-commit program, and shows why the label must remain non-accusatory.

## Scope-drift regression probes

- `Add Google login` with authentication, data, and test changes: data remains potential scope drift.
- `Fix SQL table detection` with generic application code and tests: no drift; generic implementation code is not treated as unrelated.
- `Update dependencies` with application, dependency, and test changes: no drift; singular and plural dependency intent are equivalent.

## AI Change Guard

`tests/V3ChangeTest.php` creates a disposable local project, captures Before, adds a real function, rescans, captures After, and verifies that the new critical symbol is reported. It also inspects the persisted snapshot and rejects secret-shaped values. Imported corpus repositories were never modified or executed for this workflow.

## Remaining limits

- Category-level scope comparison cannot infer human intent and may still flag adjacent documentation or configuration work.
- Semantic facts are bounded to changed paths and supported syntax; absence is not proof that behavior did not change.
- Symbol impact depends on the latest project graph in WTFCode's database; Git-only runs can have no stored impact graph.

