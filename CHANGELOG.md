# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-10-19

The MVP described in `specs/app.yml` `milestones.mvp`.

### Added
- Module skeleton for Drupal `^10.6 || ^11`: `fact_drift.info.yml`, `composer.json` (`stevebenner/fact_drift`), services, routes, permissions, menu links, and the Add source action.
- `FactSource` and `ClaimExtractor` plugin types with attribute discovery, plus the `csv` source and the `phone` and `hours` extractors.
- `Comparator`: phone and hours normalisation, the named-fact and retired-value matching rules, and comparison.
- The `fact_drift_finding` content entity, the `fact_drift_source` config entity, the finding access handler, and the sources list and form.
- The settings form at `/admin/config/content/fact-drift`, wired with `#config_target`, with a strict config schema.
- `Scanner` and `FindingRecorder`. Findings open on a mismatch, never duplicate, and close when the mismatch is gone. A dismissed finding is not reopened for the same value. A source that cannot be read is logged and leaves its findings alone.
- Facts are cached under `fact_drift_facts` and keyed on each source's fingerprint, so an edited CSV is reloaded by every scan path.
- Object-oriented hooks in `src/Hook/FactDriftHooks.php`, bridged to Drupal 10.6 with `#[LegacyHook]`:
  - node insert and update queue a rescan;
  - node delete closes the node's findings;
  - cron sweeps the site from a stored cursor;
  - theme and help.
- `SourceConfigSubscriber`, which invalidates the facts cache when a source is saved or deleted.
- The `fact_drift_rescan` queue worker.
- The report at `/admin/reports/fact-drift`:
  - a summary by claim type and by source;
  - state and type filters;
  - a paged findings table;
  - snippets escaped as plain text;
  - a CSRF-protected dismiss link;
  - a layout-only library (`css/fact-drift-report.css`) that fits the table to its region in Claro or Gin.
- Drush commands `fact-drift:scan`, `fact-drift:report` (table, JSON, CSV), and `fact-drift:sources`.
- 97 unit, kernel, and functional tests, each shown able to fail by breaking the code it covers. Fixture: `tests/fixtures/clinics.csv`, with invented clinics.
- GitHub Actions CI running phpcs (Drupal, DrupalPractice), PHPStan level 6, and PHPUnit on Drupal 10.6/PHP 8.1 and Drupal 11/PHP 8.3.
- DDEV local development with `ddev/ddev-drupal-contrib`.

### Changed
- The core floor rose from `^10.3` to `^10.6`. Composer refuses every 10.3.x and 10.4.x release, and drupal.org's advisory feed blocks every 10.5.x release.
