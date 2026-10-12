# Releasing

How ÅbenForms backend versions are numbered and cut, and what a kommune needs in order to
run a given version.

## Version numbers

The backend follows [Semantic Versioning](https://semver.org/). The project is pre-1.0, so:

- **0.MINOR.0** marks new features, and anything a deployer must act on: a new required
  environment variable, a manual Drush step, a removed endpoint, or a change to the
  frontend contract.
- **0.MINOR.PATCH** is for fixes that need no action beyond the normal deploy.
- **1.0.0** comes with the first kommune pilot in production. After that, a breaking change to
  an API, config schema or deploy contract needs a new major version.

A security fix goes into the next release at any level and is marked **[SECURITY]** in
`CHANGELOG.md`. A kommune can then search for that prefix between its running version and the
latest one.

`deployed-YYYYMMDD-HHMMSS` tags are set by the deploy pipeline for every production deploy.
They record what ran where; they are not versions.

## Every pull request

Add a line under `## [Unreleased]` in `CHANGELOG.md`, in the right section (Security, Added,
Changed, Fixed), with the issue or PR number. Start security fixes with `[SECURITY]`. If the
change needs a manual step, add it under "Deploy notes".

Dependency bumps from Dependabot need no line, unless they fix a security advisory.

## Cutting a release

1. On `main`, rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD`, add a new empty
   `## [Unreleased]` above it, and update the compare links at the bottom.
2. Merge that change through a pull request as usual.
3. Tag the merge commit and publish the release, with the changelog section as the notes:

   ```bash
   git checkout main && git pull
   git tag -a vX.Y.Z -m "vX.Y.Z"
   git push origin vX.Y.Z
   gh release create vX.Y.Z --title "vX.Y.Z - <one-line summary>" --notes-file <section.md>
   ```

The frontend (madsnorgaard/aabenforms-frontend) is versioned separately. When a release
changes the contract between the two, its deploy notes name the minimum frontend version.

## Running a version

| Requirement | Version |
|-------------|---------|
| PHP | 8.4 |
| Drupal core | 11.3 line (`drupal/core-recommended ~11.3.14`); 11.4 is not supported yet |
| Database | MariaDB 11 (production), DDEV default locally |
| Composer | 2.x |

Each release's deploy notes list the environment variables and manual steps it needs. Those
needed today:

- `AABENFORMS_CPR_KEY`: base64 of 32 random bytes, for CPR encryption at rest (since 0.3.0).
- `AABENFORMS_DEMO_SITE=1`: only on the public demo site, never on a kommune site.

## Rolling back

Every production deploy takes a database snapshot before `drush updatedb` and
`drush config:import`, and fails closed if the dump is too small. To roll back:

1. Check out the previous release tag, or the previous `deployed-*` tag, and deploy it.
2. If the release ran database updates, restore the snapshot taken before it. Drupal update
   hooks cannot be undone.

A kommune running its own instance should pin to a release tag, not to `main`.
