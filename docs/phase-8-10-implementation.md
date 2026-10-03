# Phase 8.10 Implementation Log: Enterprise Release Readiness & Integrity Hardening

**For:** the project owner, Security, Operations and Engineering.

**Status: Workstream A in progress. STOPPED at a decision boundary (§3).**

- Production: **NOT DEPLOYED / NOT CHANGED.**
- Push: **NOT DONE.**
- No production branch has been merged.

| | |
|---|---|
| Phase 8.9 frozen baseline | `5d522df`; application code `1acd789` |
| Phase 8.10 implementation baseline | `358edbf`: the discovery documents, committed before any implementation |
| Branch | `feature/sep_25_hrm` (no upstream) |
| Authoritative discovery | `phase-8-10-discovery.md` (89 findings). Those counts are not re-interpreted here. Findings that come up during implementation are listed in §4 and kept apart from them. |

## 1. Workstream A: progress

| Item | Finding | Status | Evidence |
|---|---|---|---|
| A3 | P810-OP-01: image cannot build | **Fixed in code (`bd32662`), build not executed.** | §2 |
| A1 | P89-OPS-001: backup / restore | **Not started.** Stopped (§3). | — |
| A2 | P89-OPS-012 / D8.10-002: hotfix release decision | **Not started.** Stopped (§3). | — |
| A4 | P810-OP-02: upgrade rehearsal | **Not started.** Stopped (§3). | — |

## 2. A3, P810-OP-01: Docker build

**Root cause (FACT).**
- The Dockerfile pinned `PHP_VERSION=8.3` for every stage.
- `composer.lock` requires PHP ≥ 8.4.1 (`symfony/console` and `symfony/http-kernel` 8.1.5 declare `php >=8.4.1`). The generated `vendor/composer/platform_check.php` enforces `PHP_VERSION_ID >= 80401`.
- `config/database.php` imports `Pdo\Mysql`, which exists only from PHP 8.4.
- So `composer install` refused the lock, and a forced install could not boot.

**Further defects found in the same file (FACT):**
- **`docker-php-ext-install pdo_pgsql`.** `libpq-dev` is not installed, so the extension cannot compile. The application does not use PostgreSQL.
- **`docker-php-ext-install mbstring pdo_sqlite`.** Both are compiled into the official images. The official 8.5 build uses `--enable-mbstring` and `--with-pdo-sqlite=/usr`.
- **`docker-php-ext-install opcache` in the runtime stage.** Since PHP 8.5, OPcache is always compiled in. The official 8.5 Dockerfile has no OPcache step.

**Fix (`bd32662`):**
- **Every base image pinned by version and digest.** Each tag was resolved on Docker Hub on 2026-10-03.

  | Image | Digest |
  |---|---|
  | `php:8.5.11-cli-trixie` | `sha256:19642e17…` |
  | `php:8.5.11-apache-trixie` | `sha256:70d80539…` |
  | `composer:2.9.5` | `sha256:698d3801…` (the local Composer version) |
  | `node:22.22.1-alpine` | `sha256:8094c002…` (the local Node version) |

- **PHP 8.5** is the line the test suite runs on (D8.10-004, technical recommendation (a)). The patch release is **8.5.11**, the current 8.5 patch, chosen for security fixes. The local suite has been verified on **8.5.4**, so the suite must be re-run inside the built image (§3, D8.10-005).
- **Extension list.** `gd intl zip pdo_mysql bcmath exif pcntl` are installed. `mbstring`, `pdo_sqlite`, `sodium` and `opcache` come with the image. `docker/php/local.ini` keeps configuring OPcache.
- **`composer.json`.** `"php": "^8.3"` became `"^8.5"`, which reflects the real requirement and **strengthens** it. `composer.lock` changed only its `content-hash` and the platform entry; no package moved.

**Compatibility (FACT, static):**

| Component | Result |
|---|---|
| Workers, scheduler, `migrate`, `app` | One shared image (`x-app-base` in `docker-compose.yml`), so they all get the same runtime. |
| `pcntl` | Still installed (queue worker timeouts). |
| Platform requirements | `composer check-platform-reqs --no-dev` passes on PHP 8.5: `ext-gd`, `intl`, `zip`, plus extensions built into the image. |
| `composer validate` | Valid. |
| `IdentityArchitectureTest` | Reads `composer.json`: 8 passed. |

**Not verified:**
- **The image build has not been executed.** This development host has no container runtime (`docker`, `podman`, `buildah`: none installed). Installing one changes the host and is not authorised.
- `apt` packages within the pinned Debian snapshot are not version-pinned. The digest pins the base OS layer only.

**Gate impact.** "Production Docker image builds successfully" remains **OPEN**. It needs a Docker-capable runner (D8.10-005; see also §3).

## 3. STOP: decision boundary reached

Two of the brief's stop conditions were met, so implementation stopped.

### 3.1 A new High issue was discovered (P810-SEC-015, §4)

`composer audit` (run when `composer.lock` was refreshed) reports advisories affecting installed packages:

| Package | Installed | Advisory | Severity (advisory) | Fixed in |
|---|---|---|---|---|
| `league/commonmark` | 2.10.0 | GHSA-3q6v-r5mr-hxv8: quadratic-time denial of service in the GFM table extension block-start scan | **High** | 2.10.2 (2.10.3 available) |
| `league/commonmark` | 2.10.0 | GHSA-97jj-33gv-5xf9: `DisallowedRawHtml` bypass when a disallowed tag name ends the raw-HTML literal | Medium | 2.10.2 |
| `laravel/framework` | v13.29.0 | GHSA-jh5r-qr3c-85q8 (CVE-2026-102279): XSS in debug page information | Low | v13.30.0 (v13.34.0 available) |

Fixing these means changing dependency versions. CLAUDE.md requires approval for that, so it is **decision D8.10-020**. No dependency was updated.

### 3.2 Production-like infrastructure is unavailable for A3 verification

The image cannot be built on this host (§2). This is recorded under D8.10-005, which is now blocking for the build gate.

### 3.3 Not started because of the stop

A1 (backup / restore), A2 (hotfix release decision) and A4 (upgrade rehearsal). None of them depends on the dependency decision technically. They wait for the instruction to continue.

## 4. Findings discovered during implementation

These are not part of the discovery counts.

| ID | Severity | Finding | Component | Evidence | Status |
|---|---|---|---|---|---|
| P810-SEC-015 | **High** (advisory severity; exposure assessment below) | Installed dependencies carry published advisories, including a High denial of service in `league/commonmark` | `league/commonmark` 2.10.0; `laravel/framework` v13.29.0 | `composer audit`, 2026-10-03 | **Open. Decision D8.10-020.** |

**Exposure (INFERENCE from code; nothing executed):**
- `league/commonmark` is reached through `Str::markdown` (GitHub-flavoured, tables enabled) in two places:
  - `resources/views/filament/pages/ai-copilot.blade.php:49`;
  - `resources/views/filament/components/ai-conversation-transcript.blade.php:43`.
- Both render **model output**. Candidate-editable text can influence that output through prompt injection (P810-AI-02).
- Output is bounded by `AI_MAX_TOKENS` (default 4,000), and only signed-in staff can view these pages.
- No other `->markdown()` use was found in `app/` or the views.
- Exploitability therefore looks limited, but it is not disproved. The severity stays High until Security accepts a different rating.
- Both renders use `html_input: strip`, so the raw-HTML bypass (Medium) is unlikely to apply (INFERENCE).
- The Laravel advisory needs `APP_DEBUG=true`, which the production checklist forbids (`production-environment.md`).

## 5. Commits in this workstream

| Commit | Change |
|---|---|
| `358edbf` | Phase 8.10 discovery documents (implementation baseline) |
| `bd32662` | Dockerfile and `composer.json` / `composer.lock` alignment (P810-OP-01) |
| (this commit) | Implementation log, security-review addendum, decision D8.10-020 |
