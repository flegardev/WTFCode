# Scanner benchmark suite

This is the accuracy loop for WTFCode. The manifest contains 13 public repositories across PHP, Next.js, React, Express, FastAPI, Django, Supabase, Docker, monorepo, and small AI-app examples.

Run the quick calibration set:

```powershell
php tests/Benchmark.php --group=core
```

Run every benchmark (network access and Git are required):

```powershell
php tests/Benchmark.php --all
```

The runner uses shallow clones in a uniquely named temporary directory, does not execute imported code, limits every clone to 120 seconds, and removes the clone unless `--keep` is supplied. It reports deterministic signals only: required stack items, selected incompatible-stack regressions, architecture-node keys, file count, and findings.

On Windows, antivirus or Git can temporarily hold a pack file after a clone. The runner retries cleanup; if a temporary test clone remains, clean only WTFCode benchmark directories with:

```powershell
php tests/Benchmark.php --cleanup
```

After reviewing each repository in the WTFCode UI, record the human scores in `scorecard.md`:

| Metric | What to judge |
| --- | --- |
| Stack detection | Only technologies with concrete source/config evidence are claimed. |
| Architecture | Nodes and their evidence correspond to real systems. |
| Dependency mapping | Imports/includes resolve to the intended local files. |
| Feature tracing | A feature query produces useful, evidence-linked steps. |
| Blast radius | Dependents are accurate and uncertainty is explicit. |
| Change explanation | Commit comparisons group changes sensibly without inventing authorship. |
| False positives | Claims and findings do not arise from README text, fixtures, or scanner code. |
| Overall usefulness | A developer can understand the repository faster and more safely. |

When a result is wrong: add a minimal unit fixture first, fix the deterministic analyzer, then rerun the affected benchmark and its scorecard entry.

The current reproducible detector baseline is recorded in `baseline-2026-08-19.md`.
