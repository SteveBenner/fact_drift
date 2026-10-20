# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-10-20

The `ai_extractor` milestone in `specs/app.yml`.

### Added
- The `fact_drift_ai` submodule (`modules/fact_drift_ai`, depends on `drupal/ai` ^1.5):
  - An `llm` claim extractor. It finds phone numbers and opening hours written in forms the regexes miss ("weekdays from eight until five").
  - Grounding: each claim's quote must appear in the page, and its value must pass the existing normalisers, so a claim the model invents is dropped.
  - Two modes: `fallback`, the default, asks the model only for the claim types the regexes found none of in a field; `alongside` asks for both on every allowed field.
- **Data gate:**
  - Only fields on an allowlist, which is empty by default, of nodes the anonymous user can view are sent, and only their text.
  - Calls are held while `ai_logging` prompt logging, `ai_observability` input logging, or external moderation could store or forward that text.
- **Call guard on every call:**
  - The kill switch and `enabled`.
  - A per-run budget (`max_calls_per_run`) and a minimum interval (`min_interval_ms`).
  - `timeout_seconds`.
  - Bounded retries with full-jitter backoff, on timeouts and 5xx only.
  - 429 handling that honours Retry-After, and a circuit breaker on outages.
- **Ollama timeout:** `TimeoutOllamaProvider` makes the Ollama provider honour the timeout. It is swapped in through `hook_ai_provider_info_alter`, because `ai_provider_ollama` 1.2.0-rc3 hard-codes 600 s.
- **Cache:** results are cached in `cache.fact_drift_llm` by a hash of window, provider, model, types and prompt version, so unchanged pages cost no calls. Page text is never cached or logged.
- **`reasoning` setting:** `none` by default, which sends `reasoning_effort: none`. On CPU, qwen3:4b took 220 s with reasoning on and 4 s with it off.
- **Settings and status:** an "AI extractor" settings tab at `/admin/config/content/fact-drift/ai`, and a status report entry for side paths.
- **Run summary:** one log line per run (calls, cache hits, retries, deferrals, rejections, tokens), also printed by `drush fact-drift:scan`.
- **Eval:** `drush fact-drift:ai-eval` reports precision and recall over a labelled fixture of invented clinics (`modules/fact_drift_ai/tests/fixtures/eval.yml`).
- **Base-module extension points:** contextual extractors (`ContextualClaimExtractorInterface`, `ExtractionContext`), claim provenance, and the `claim_types` and `marker` attribute keys.
- **Provenance on findings:** an `extractor` base field. The report marks AI findings and has an extractor filter, and `drush fact-drift:report` adds an extractor column.
- **Settings form:** shows when settings.php overrides the kill switch, and does not write the override back.

### Changed
- **Deferred work:** a model that is rate-limited, down, or out of budget defers only its own work. The queue item requeues with `DelayedRequeueException` after its regex findings are recorded. This replaces 0.1.0's `SuspendQueueException` rule.
- **Closing findings:** a scan closes a finding only if the extractor that produced it ran. A held or deferred extractor leaves its findings open, while a removed extractor still closes them.
- **Settings moved:** the unused `llm` settings moved from `fact_drift.settings` to `fact_drift_ai.settings`. Update hook 10002 removes them, and update hook 10001 installs the finding's `extractor` field.
- **Extractor list:** the settings form lists only the plain extractors. The AI extractor's one switch is on its own tab.
- **Extractor labels:** the phone and hours extractors are now labelled "Phone patterns" and "Hours patterns", so the extractor filter never repeats the claim-type filter's words.
- **Report layout:**
  - The claim type column never hides, so the AI marker stays in view.
  - Hours values break only between their segments.
  - Filter links never wrap, and the Operations column is padded and centred, so it no longer covers the AI marker on a narrow screen.
  - "Show all columns" follows the table region's width when the admin sidebar opens or closes, and it no longer sits against the filters.

### Fixed
- **Base fields:** the scanner reads only configurable fields. In 0.1.0 it also scanned base fields such as the revision log message, which are not published page content, and could report a phone number from an editor's note.
- **CI:** installs `drupal/ai` and `drupal/ai_provider_ollama`, and phpstan now covers `modules/` and the install files.

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
