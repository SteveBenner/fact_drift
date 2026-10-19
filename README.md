# Fact Drift Sentinel

A Drupal 10.6 and 11 module that finds published pages whose facts have drifted from their canonical source.

Organisations that run several Drupal sites repeat the same facts across many pages, such as clinic phone numbers and opening hours. When the source of truth changes, the copies on published pages go stale, and nothing notices. Fact Drift Sentinel reads the true facts from a configured source, finds the matching claims in published content, and reports every mismatch with the page, the snippet, the expected value, and the value it found.

## Status

Version 0.1.0, the MVP in [`specs/app.yml`](specs/app.yml). The specification is the authority for what the module does and how it is built. CI runs phpcs, PHPStan level 6, and PHPUnit on Drupal 10.6 with PHP 8.1 and on Drupal 11 with PHP 8.3.

## How it works

1. A **source** (a config entity backed by a `FactSource` plugin, CSV first) supplies the true facts.
2. **Extractors** (`ClaimExtractor` plugins, phone and hours first) find claims in the text of published nodes.
3. The **scanner** attributes each claim to a fact, compares the normalised values, and records a **finding** (a content entity) for each mismatch.
4. Saving a node queues a rescan, and cron sweeps the whole site on an interval.
5. Editors read the findings at `/admin/reports/fact-drift`, and managers dismiss the ones that are not real.
6. `drush fact-drift:scan` and `drush fact-drift:report` do the same from the command line.

An optional AI extractor, built on the `ai` module, is a stretch goal. It is off by default, behind a kill switch, rate-limited, and never sends unpublished content.

## Install

```sh
composer config repositories.fact_drift vcs https://github.com/SteveBenner/fact_drift
composer require stevebenner/fact_drift:^0.1
drush en fact_drift
```

## Configure a source

1. Write a CSV with the header `key,name,aliases,phone,former_phones,hours`. Separate several aliases or former phone numbers with `|`. See [`tests/fixtures/clinics.csv`](tests/fixtures/clinics.csv).
2. Go to **Configuration › Content authoring › Fact Drift Sentinel › Fact drift sources** and choose **Add source**. Give it the CSV's path, which can be a stream-wrapper URI such as `private://facts/clinics.csv`, an absolute path, or a path relative to the Drupal root. Then choose the claim types it provides.
3. Pick the content types, field types, and extractors to scan at `/admin/config/content/fact-drift`.

## Run a scan

```sh
drush fact-drift:scan                   # every published node of the configured types
drush fact-drift:scan --node=12         # one node
drush fact-drift:scan --source=clinics  # one source
drush fact-drift:report --format=json   # open findings; --state=closed|dismissed
drush fact-drift:sources                # each source's plugin, fact count and last load
```

Editing a node queues a rescan, and cron sweeps the site every `full_scan_interval` seconds, `nodes_per_cron` nodes at a time. People with **View fact drift findings** see the report at **Reports › Fact drift**. People with **Dismiss findings and draft corrections** can dismiss a finding.

## Demo

1. Start a DDEV site with three pages that repeat one clinic's phone number and hours, and a source pointing at a copy of `tests/fixtures/clinics.csv`.
2. Change the clinic's number in the copy, then run `ddev drush fact-drift:scan`.
3. Open `/admin/reports/fact-drift` and show the evidence: page, snippet, expected value, and found value.
4. Dismiss one finding with its CSRF-protected link.
5. Show the tests and CI, and how one test is proven able to fail.

## Development

```sh
ddev start
ddev poser
ddev symlink-project
ddev drush site:install standard --account-pass=admin -y
ddev drush en fact_drift -y
ddev phpcs && ddev phpstan && ddev phpunit
```

## Authorship

Designed and directed by Stephen Benner, who verifies each change by hand. The code is written by coding agents to the specification.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
