# Fact Drift Sentinel

A Drupal 10.5 and 11 module that finds published pages whose facts have drifted from their canonical source.

Organisations that run several Drupal sites repeat the same facts across many pages, such as clinic phone numbers and opening hours. When the source of truth changes, the copies on published pages go stale, and nothing notices. Fact Drift Sentinel reads the true facts from a configured source, finds the matching claims in published content, and reports every mismatch with the page, the snippet, the expected value, and the value it found.

## Status

Specification only. Nothing is built yet. The specification in [`specs/app.yml`](specs/app.yml) is the authority for what the module does and how it is built. The README will cover installation and use once the MVP lands.

## How it will work

1. A **source** (a config entity backed by a `FactSource` plugin, CSV first) supplies the true facts.
2. **Extractors** (`ClaimExtractor` plugins, phone and hours first) find claims in the text of published nodes.
3. The **scanner** attributes each claim to a fact, compares the normalised values, and records a **finding** (a content entity) for each mismatch.
4. Saving a node queues a rescan, and cron sweeps the whole site on an interval.
5. Editors read the findings at `/admin/reports/fact-drift`, and managers dismiss the ones that are not real.
6. `drush fact-drift:scan` and `drush fact-drift:report` do the same from the command line.

An optional AI extractor, built on the `ai` module, is a stretch goal. It is off by default, behind a kill switch, rate-limited, and never sends unpublished content.

## Authorship

Designed and directed by Stephen Benner, who verifies each change by hand. The code is written by coding agents to the specification.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
