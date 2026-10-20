# Fact Drift Sentinel

A Drupal 10.6 and 11 module that finds published pages whose facts have drifted from their canonical source.

Organisations that run several Drupal sites repeat the same facts across many pages, such as clinic phone numbers and opening hours. When the source of truth changes, the copies on published pages go stale, and nothing notices. Fact Drift Sentinel reads the true facts from a configured source, finds the matching claims in published content, and reports every mismatch with the page, the snippet, the expected value, and the value it found.

## Status

Version 0.2.1: the MVP plus the optional AI extractor, both described in [`specs/app.yml`](specs/app.yml). The specification is the authority for what the module does and how it is built. CI runs phpcs, PHPStan level 6, and PHPUnit on Drupal 10.6 with PHP 8.1 and on Drupal 11 with PHP 8.3.

## How it works

1. A **source** (a config entity backed by a `FactSource` plugin, CSV first) supplies the true facts.
2. **Extractors** (`ClaimExtractor` plugins, phone and hours first) find claims in the text of published nodes.
3. The **scanner** attributes each claim to a fact, compares the normalised values, and records a **finding** (a content entity) for each mismatch.
4. Saving a node queues a rescan, and cron sweeps the whole site on an interval.
5. Editors read the findings at `/admin/reports/fact-drift`, and managers dismiss the ones that are not real.
6. `drush fact-drift:scan` and `drush fact-drift:report` do the same from the command line.

The optional `fact_drift_ai` submodule adds an AI extractor for claims written in prose, such as "weekdays from eight until five". See [AI extractor](#ai-extractor-optional).

## Install

```sh
composer config repositories.fact_drift vcs https://github.com/SteveBenner/fact_drift
composer require stevebenner/fact_drift:^0.2
drush en fact_drift
```

After upgrading from an earlier release, run `drush updb` and then `drush cr`. The 0.2.0 updates add an extractor field to findings, fill it from each existing finding's claim type, and remove the unused `llm` settings key.

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

## AI extractor (optional)

The `fact_drift_ai` submodule finds phone numbers and opening hours that the regexes cannot read, such as "weekdays from eight in the morning until five", through the [`ai`](https://www.drupal.org/project/ai) module's provider abstraction. It has been tested with a local Ollama model only, so no page text leaves the host.

How it stays safe:

- **Off by default.** The **Enable the AI extractor** setting on its tab is its one switch. The kill switch on the main settings form stops every call at once, and you can set it in settings.php for each environment.
- **Grounded claims.** The model returns each claim's exact quote and a canonical value. A claim counts only if the quote is on the page and the value passes the same normalisers the regexes use. A claim the model invents is dropped. Findings from the model are marked **AI** in the report.
- **Restricted input.** Only fields on an allowlist, which is empty until you fill it, of pages that anonymous visitors can view are sent, and only their text. While `ai_logging` prompt logging, `ai_observability` input logging, or external moderation could keep or forward that text, no call is made, and the status report says why. To keep prompt logging for other uses, exclude the `fact_drift_ai` tag.
- **Bounded calls.** Each run has a call budget, a minimum interval, a timeout, and at most two retries with jittered backoff, on timeouts and 5xx only. A 429 waits for its Retry-After. An outage opens a circuit breaker, and the regex checks carry on. Results are cached by a hash of the text, so unchanged pages cost nothing on later sweeps.

### Set up Ollama with DDEV

```sh
OLLAMA_HOST=172.17.0.1:11434 ollama serve   # listen on the Docker bridge, not the LAN
ollama pull gemma3:4b
composer require drupal/ai drupal/ai_provider_ollama:^1.2@RC
drush en fact_drift_ai ai_provider_ollama
drush cset ai_provider_ollama.settings host_name http://host.docker.internal
drush cset ai_provider_ollama.settings port 11434
```

Then open **Configuration › Content authoring › Fact Drift Sentinel › AI extractor**. Choose the model, allow the body field of the content types to check, and enable the extractor. Enabling core's Inline Form Errors module (`drush en inline_form_errors`) puts validation errors under their field instead of at the top of the page.

`ai_provider_ollama` 1.2.0-rc3 hard-codes a 600-second timeout. `fact_drift_ai` replaces its plugin class with one that honours the timeout it is given, and other uses of Ollama on the site keep the 600-second default.

### Choosing a model

`drush fact-drift:ai-eval [--model=ID]` runs the extractor over 30 labelled sentences about the invented clinics and prints precision and recall. Measured on 2026-10-20 on a 32-core CPU with no GPU, with each model loaded beforehand and reasoning off:

| Model | Precision | Recall | Median call |
|---|---|---|---|
| gemma3:4b (the default) | 0.969 | 0.939 | 4.4 s |
| qwen3:8b | 0.939 | 0.939 | 4.0 s (timeout raised to 30 s) |
| qwen3:4b | 0.935 | 0.879 | 4.3 s |

Results vary from run to run. Treat a finding marked AI as a lead to check, because a wrong value from the model becomes a false finding.

Keep **Reasoning** off. With it on, qwen3:4b took 220 seconds instead of 4 for the same two claims. The first call after a model loads can exceed a 10-second timeout on CPU, so either keep the model loaded (`OLLAMA_KEEP_ALIVE`) or raise the timeout.

### AI demo (two minutes)

1. Start Ollama with gemma3:4b, enable `fact_drift_ai`, choose the model, and allow the page body.
2. Create a page that gives Riverside's hours in prose with a stale closing time: "Riverside Family Clinic welcomes patients weekdays from eight in the morning until four in the afternoon."
3. Run `ddev drush fact-drift:scan --node=<nid>`. It prints the AI run summary, and the report shows the finding marked AI. Scan again to see a cache hit and no call.
4. Turn on the kill switch and scan again. No call is made, and the finding stays open.
5. Run `ddev drush fact-drift:ai-eval`.

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
