# fact_drift

Rules for any agent working in this repository.

1. `specs/app.yml` is the authority. Build only what it describes. A change of design changes the spec first, in its own commit.
2. Code follows Drupal's coding standards (Drupal and DrupalPractice), including docblocks on classes, methods, and functions. This is a deliberate exception to the owner's general no-comments rule, decided 2026-10-16. Docblocks state contracts (what, parameters, returns, throws); they never narrate the code. No other comments.
3. Tests are part of the specification. Write the unit, kernel, and functional tests it lists. A test counts only after it has been shown to fail when the code it covers is removed.
4. Use only APIs present in Drupal 10.5, with the 11.1 object-oriented hooks bridged by `#[LegacyHook]`. No `\Drupal::` static calls inside classes; inject dependencies.
5. Never commit secrets, API keys, or real patient or staff data. Fixtures use invented clinics.
6. Commits carry no agent attribution. Authorship is stated once, in the README: designed and directed by the owner, written by agents.
7. Stage files by name; never `git add -A`. Main stays green.
