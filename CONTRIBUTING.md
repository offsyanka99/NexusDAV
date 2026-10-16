# Contributing to NexusDAV

Thank you for your interest in NexusDAV! Contributions of all kinds are welcome: bug reports, documentation, client compatibility reports, and code.

> NexusDAV is at an early stage. The structure, tooling and conventions below will evolve, so please open an issue to discuss larger changes before investing a lot of time.

## Ways to contribute

- **Report a bug.** Open an issue with steps to reproduce, the expected and actual behavior, your NexusDAV version, database (PostgreSQL or SQLite) and the client used (for example DAVx5, Thunderbird, Apple Calendar).
- **Report client compatibility.** Tested a client against NexusDAV? Tell us the client name and version, what works and what does not. This helps fill the [client compatibility table](README.md#client-compatibility).
- **Suggest a feature.** Check the [Roadmap](README.md#roadmap) first, then open an issue describing the use case rather than only the solution.
- **Improve documentation.** Fixes to the README, setup guides and reverse proxy examples are always welcome.
- **Send code.** See below.

**Security issues must not be reported through public issues.** Please follow [SECURITY.md](SECURITY.md) instead.

## Development setup

You need the components listed under [Requirements](README.md#requirements): PHP 8.3+ (8.4 recommended) with the required extensions, Composer 2, Node.js (current LTS) and either SQLite or PostgreSQL.

```bash
git clone https://github.com/<owner>/nexusdav.git
cd nexusdav
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
```

> The commands above are the standard Laravel workflow and are a placeholder until the project skeleton is published. They will be kept in sync with the repository.

You can also develop with Docker using the Compose files described in the README (`compose.yaml` plus `compose.postgres.yaml` or `compose.sqlite.yaml`).

## Making a change

1. **Fork** the repository and create a branch from `main`, for example `fix/caldav-sync-token` or `feature/app-passwords`.
2. Keep the change **focused**: one logical change per pull request.
3. **Add or update tests** for the behavior you change. DAV compatibility bugs should come with a test that reproduces the problem, ideally a raw HTTP request and response.
4. Run the **code style and test checks** locally (see below) before pushing.
5. Open a **pull request** against `main`. Describe what changed and why, link the related issue, and mention which clients or databases you tested with.

### Tests and code quality

The project aims to run the following in CI. Run the same locally before opening a pull request:

```bash
composer test        # automated tests
composer lint        # code style
composer analyse     # static analysis
```

Notes:

- Tests must pass on **both PostgreSQL and SQLite**, since both are supported.
- DAV protocol behavior should be checked against real clients where possible and, over time, against standard compliance suites such as the CalDAV/CardDAV test tools and `litmus` for WebDAV.
- The exact tool names (test runner, style fixer, static analyzer) will be documented once they are set up in the repository.

### Code conventions

- Follow the Laravel and PSR-12 coding style. Use the project's formatter rather than formatting by hand.
- Write clear, descriptive commit messages in the imperative mood (for example `Add app password revocation`).
- Do not commit secrets, real credentials or personal data. Use obviously fake values in tests and examples.
- User-facing text must be translatable (the admin panel and user portal are planned to be multilingual).

### Dependencies

Use the latest stable versions of libraries when possible and keep the versions in [Requirements](README.md#requirements) accurate. WebDAV-Push has no tagged release, so it is tracked by commit. Before adding a new dependency, check that it is maintained, compatible with the supported PHP and Laravel versions, and compatible with the MIT license.

## Review process

A maintainer will review your pull request. Expect questions or requested changes; this is a normal part of the process. Pull requests that do not pass the automated checks cannot be merged.

## License

By contributing, you agree that your contributions will be licensed under the [MIT License](LICENSE) that covers the project.

## Conduct

Be respectful and constructive. Harassment and personal attacks are not tolerated. A formal code of conduct will be added as the community grows.
