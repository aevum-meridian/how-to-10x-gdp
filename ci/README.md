# CI workflow (pending activation)

`github-workflow-ci.yml` is the DEV-M quality-gates pipeline (Pint, Larastan
Level 10 no baseline, full Pest suite against real PostgreSQL 17 + Redis
including the DOCUMENT 0.1 §CI prose-logic agreement gate, composer audit,
CycloneDX SBOM). A red pipeline blocks merge.

It lives here rather than in `.github/workflows/` because the automation
token used to open this PR lacks the GitHub `workflows` permission and is
refused by GitHub when pushing workflow files — an honest limitation, not a
choice. To activate:

```bash
mkdir -p .github/workflows
git mv ci/github-workflow-ci.yml .github/workflows/ci.yml
git commit -m "ci: activate DEV-M quality gates workflow"
```

(Any maintainer pushing with normal user credentials can do this.)
