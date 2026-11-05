# Contributing to NexusDAV

Thank you for your interest in NexusDAV! Contributions of all kinds are welcome: bug reports, documentation, client compatibility reports, and code.

> NexusDAV is in its 0.x series. The structure, tooling and conventions below will evolve, so please open an issue to discuss larger changes before investing a lot of time.

## Ways to contribute

- **Report a bug.** Open an issue with steps to reproduce, the expected and actual behavior, your NexusDAV version, database (PostgreSQL or SQLite) and the client used (for example DAVx5, Thunderbird, Apple Calendar).
- **Report client compatibility.** Tested a client against NexusDAV? Tell us the client name and version, what works and what does not. The [client checklist](docs/testing/client-checklist.md) lists the steps the project runs; reporting results against its step IDs makes them easy to compare. This helps fill the [client compatibility table](docs/guide.md#client-compatibility).
- **Suggest a feature.** Check the [Roadmap](docs/guide.md#roadmap) first, then open an issue describing the use case rather than only the solution.
- **Improve documentation.** Fixes to the README, setup guides and reverse proxy examples are always welcome.
- **Send code.** See below.

**Security issues must not be reported through public issues.** Please follow [SECURITY.md](SECURITY.md) instead.

## Development setup

You need the components listed under [Requirements](docs/guide.md#requirements): PHP 8.3+ (8.4 recommended) with the required extensions, Composer 2, Node.js (current LTS) and either SQLite or PostgreSQL.

```bash
git clone https://github.com/offsyanka99/NexusDAV.git
cd NexusDAV
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
```

You can also develop with Docker using the Compose files described in the README (`compose.yaml` plus `compose.postgres.yaml` or `compose.sqlite.yaml`). `compose.yaml` pulls the published image; add `compose.build.yaml` last to build from your checkout:

```bash
docker compose -f compose.yaml -f compose.sqlite.yaml -f compose.build.yaml up -d --build
```

## Making a change

1. **Fork** the repository and create a branch from `main`, for example `fix/caldav-sync-token` or `feature/app-passwords`.
2. Keep the change **focused**: one logical change per pull request.
3. **Add or update tests** for the behavior you change. DAV compatibility bugs should come with a test that reproduces the problem, ideally a raw HTTP request and response.
4. Run the **code style and test checks** locally (see below) before pushing.
5. Open a **pull request** against `main`. Describe what changed and why, link the related issue, and mention which clients or databases you tested with.

### Tests and code quality

CI runs the same checks. Run them locally before opening a pull request:

```bash
composer test        # PHPUnit
composer lint        # Pint, check only
composer analyse     # PHPStan / Larastan
```

Notes:

- Tests must pass on **both PostgreSQL and SQLite**. CI runs the suite once per database. Lint and analyse run once.
- CI also builds the Docker image and runs `docker/smoke-test.sh` against it. The script fails on a PHP older than 8.4.27 and checks `/health`, the admin panel headers, cached config, a DAV request with an app password and its request log line, a client round trip (events, tasks, contacts, sync, creating and deleting a calendar), `.well-known`, `nexusdav:reset-admin-password` run as `nexusdav`, and that logs go to the container output. The Dockerfile itself stops the build on a PHP older than 8.4.27. When you change the Dockerfile, the entrypoint or anything under `docker/`, run it locally:

  ```bash
  docker build --pull -t nexusdav:local . && docker/smoke-test.sh nexusdav:local
  ```
- DAV protocol behavior should be checked against real clients where possible and, over time, against standard compliance suites such as the CalDAV/CardDAV test tools and `litmus` for WebDAV.
- When you change a file in [docs/proxy](docs/proxy/), test it in front of the image over HTTPS: discovery, a `PROPFIND` with an app password, `MKCALENDAR`, a large upload, and the client address in the log.

### Releasing

Maintainers publish a release by pushing a version tag. The [release workflow](.github/workflows/release.yml) runs the CI checks, builds the image from a freshly pulled base, runs the smoke test, then builds and pushes `ghcr.io/offsyanka99/nexusdav` for `linux/amd64` and `linux/arm64`. A base image with PHP older than 8.4.27 stops it on either platform.

1. Change the tag on the `image:` line of `compose.yaml` and the version named in the README and the guide (Quick start, Client compatibility) to the new version.
2. Merge that to `main`, then tag the merge commit and push the tag:

   ```bash
   git tag -a v0.1.0 -m "NexusDAV 0.1.0"
   git push origin v0.1.0
   ```
3. Check that the workflow passed and that the package is public (the first push creates it as private; change it under the package settings).
4. Write the release notes on GitHub: changes, upgrade steps, and anything an admin must do.

Tags are `v<major>.<minor>.<patch>`, with an optional pre-release suffix such as `-rc.1`. The image gets the exact version, `<major>.<minor>`, `<major>` from 1.0 on, and `latest` for a release without a suffix. A pre-release gets only its exact tag. The Dashboard shows the version and the tagged commit, for example `0.1.6+4f82db8`.

### Code conventions

- Follow the Laravel and PSR-12 coding style. Use the project's formatter rather than formatting by hand.
- Write clear, descriptive commit messages in the imperative mood (for example `Add app password revocation`).
- Do not commit secrets, real credentials or personal data. Use obviously fake values in tests and examples.
- User-facing admin-panel text must use Laravel's translation helpers. Keep English and Russian entries in `lang/en.json` and `lang/ru.json`; the translation test rejects missing and unused keys.

### Translations

The admin panel is translated; DAV responses, `/health`, the container log and the reasons stored for Diagnostics stay in English.

- Use the English text as the key with `__()` or `trans_choice()`, and add the English and Russian values to `lang/en.json` and `lang/ru.json` in the same change. A Russian plural needs three forms, `one|few|many` (for 1, 2 and 5: `ошибка|ошибки|ошибок`).
- To add a language, add `lang/<code>.json` with every key and `_language_name` set to the language's own name. The user menu and the Configuration page pick it up from the file. Add `lang/<code>/` for Laravel's validation and auth messages (`php artisan lang:publish` gives the English ones), and `lang/vendor/<namespace>/<code>/` for any Filament string missing from Filament's own files.
- A stored diagnostic reason gets its label in `App\Support\DiagnosticReason`; a service exception shown in a notification gets one in `App\Support\PanelErrorTranslator`. The stored and logged value stays English.
- `tests/Feature/LocalizationTest.php` fails on a key that is missing or unused, on Russian framework messages that do not cover the English ones, and, in the `en_XA` pseudo-locale, on any visible panel text that is not translated.
- Russian Laravel validation messages are adapted from [Laravel-Lang/lang](https://github.com/Laravel-Lang/lang) under MIT; see [its license](lang/ru/LICENSE.Laravel-Lang).
- User-portal text is not part of this catalog; the portal arrives in Version 2.

### Dependencies

Use the latest stable versions of libraries when possible and keep the versions in [Requirements](docs/guide.md#requirements) accurate. WebDAV-Push has no tagged release, so it is tracked by commit. Before adding a new dependency, check that it is maintained, compatible with the supported PHP and Laravel versions, and compatible with the MIT license.

## Review process

A maintainer will review your pull request. Expect questions or requested changes; this is a normal part of the process. Pull requests that do not pass the automated checks cannot be merged.

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE) that covers the project.

## Conduct

Be respectful and constructive. Harassment and personal attacks are not tolerated. A formal code of conduct will be added as the community grows.
