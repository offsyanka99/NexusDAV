# Security Policy

NexusDAV stores calendars, contacts, notes, tasks and files, and is usually exposed to the internet. We take security reports seriously.

## Supported versions

NexusDAV is in its 0.x series. The current release is 0.6.0 (first release 0.1.0). Security fixes are provided for the latest released version only. Images are rebuilt from a freshly pulled base for each release, and a base with PHP older than 8.4.27 stops the release.

| Version | Supported |
|---|---|
| Latest release | Yes |
| Older releases | No |
| Development branch (`main`) | Best effort |

## Reporting a vulnerability

**Please do not report security vulnerabilities through public GitHub issues, discussions or pull requests.**

Report them privately using one of these channels:

1. **GitHub private vulnerability reporting** (preferred): go to the repository's **Security** tab and choose **Report a vulnerability**.
2. **Email:** `<security contact address, to be set by the maintainer>`.

Please include as much of the following as you can:

- A description of the vulnerability and its impact
- The affected version or commit, database (PostgreSQL or SQLite) and deployment method (Docker, reverse proxy)
- Step-by-step instructions to reproduce it, or a proof of concept (for DAV issues, the raw HTTP request and response help a lot)
- Any suggested fix or mitigation

Do not include real user data or credentials from systems you do not own.

## What to expect

- **Acknowledgement** within 7 days.
- **Assessment and updates** as we investigate. We will tell you whether we consider the report valid and what we plan to do.
- **Fix and disclosure.** We will work on a fix and coordinate a public disclosure date with you. We aim to release a fix before details are published, and we will credit you in the release notes unless you prefer to stay anonymous.

These are targets for a small volunteer-run project, not guarantees.

## Scope

In scope, for example:

- Authentication and authorization flaws (including app passwords and sharing / ACL)
- Access to another user's calendars, contacts, notes, tasks or files
- Injection, cross-site scripting, request forgery and path traversal in the server, admin panel or user portal
- Weaknesses in rate limiting or brute-force protection
- Insecure default configuration, secrets handling or the installation process

Out of scope, for example:

- Vulnerabilities in third-party dependencies (sabre/dav, Laravel, Filament, Livewire and others) that are not caused by how NexusDAV uses them. Please report those to the respective projects. We will update dependencies once a fix is available.
- Issues that require physical access, a compromised server or an already compromised administrator account
- Missing security headers or settings that are the responsibility of your reverse proxy
- Denial of service through very high request volume
- Social engineering

## Hardening your deployment

- Always run NexusDAV behind HTTPS, for example through a reverse proxy.
- Leave `TRUSTED_PROXIES` unset (private ranges) or set it to your reverse proxy's address. Do not use `*`: the brute-force limit then counts every client as the proxy, so anyone can briefly lock a username out.
- Admin passwords must be at least 12 characters. Use a long random one.
- Use strong, unique passwords and prefer **app passwords** for DAV clients.
- Remove `ADMIN_PASSWORD` from your environment file after the first login.
- Keep the application, its dependencies and the Docker images up to date.
- Back up the database and the data volume, including the `APP_KEY`.

## Safe harbor

If you make a good-faith effort to follow this policy, avoid privacy violations and service disruption, and give us reasonable time to respond before disclosure, we will not pursue action against you for your research.
