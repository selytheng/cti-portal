# CTI Portal — Enterprise Cyber Threat Intelligence Web Portal

> A SOC-grade threat-intelligence workspace: ingest security feeds, extract
> structured intel with AI, verify it against trust sources (NVD, CISA KEV,
> EPSS, OTX, Abuse.ch), correlate developing stories, and generate
> analyst-ready reports — all in one place.

[![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![Vue 3](https://img.shields.io/badge/Vue-3-4FC08D?logo=vuedotjs&logoColor=white)](https://vuejs.org)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-4169E1?logo=postgresql&logoColor=white)](https://www.postgresql.org)
[![Tests](https://img.shields.io/badge/tests-6%2F6%20passing-brightgreen)](./tests/Feature)

---

## The story: built entirely by vibe coding with AI

This project is an experiment in **spec-driven agentic development** — and it
worked end to end.

**No production code in this repository was written by human hands.** The
entire application — every controller, service, queued job, migration, policy,
and Vue page — was authored by an AI coding agent (Claude/GLM via OpenClaw)
working from a single full-stack specification.

The workflow that produced it:

1. **A human wrote the spec.** A detailed system document (`*_spec_v3.md`)
   covering architecture, RBAC, data schema, the ingestion pipeline,
   configuration center, and deployment — revised three times based on the
   AI's critical feedback (each review round made the spec measurably better:
   similarity-based story merging, revision tracking, rate-limit-aware
   enrichment).
2. **The AI built it in phases.** Foundation → Configuration Center →
   Ingestion pipeline → Repository & reports → Dashboard & deployment docs —
   each phase running and verified before the next started.
3. **The human tested like a user.** Clicking through the browser, hitting
   blank tabs and 500s, and reporting symptoms — *not* stack traces. Every bug
   report became a real fix: template render crashes, a poisoned cache entry,
   a queue lease shorter than the job runtime.
4. **Lessons became skills.** Each resolved incident was folded back into the
   agent's learned procedures, so the next build starts smarter.

The human's role wasn't typing code — it was **spec author, acceptance tester,
and bug reporter**. The AI's role was architect, engineer, and on-call fixer.
That division of labor is the whole experiment.

## Why we built it

Security teams drown in threat intel:

- Intelligence is scattered across **dozens of RSS feeds, CERT advisories, and
  vendor PSIRTs** — no single view of what actually matters
- Analysts **copy-paste CVEs and IOCs into spreadsheets** and verify them by
  hand against NVD, KEV, and reputation services
- The same incident gets reported by five sources as five separate "events",
  with no automatic correlation as the story develops
- Reports for leadership are **hand-assembled documents**

The CTI Portal fixes this in one flow: **ingest → filter → extract →
correlate → verify → report**, with a human analyst reviewing and approving —
never re-typing.

## Framework & architecture

| Layer | Technology |
|---|---|
| Backend | **Laravel 13** (PHP 8.3+), strict typing, Service/Action patterns |
| Frontend | **Inertia.js + Vue 3**, Tailwind CSS, dark-first SOC theme |
| Database | **PostgreSQL 16+** — JSONB threat attributes, `tsvector` full-text search, `pg_trgm` similarity matching |
| Queues | **Redis + Laravel Horizon** — staged ingestion pipeline |
| Real-time | **Laravel Reverb** — live query progress over websockets |
| Auth | Laravel Fortify/Sanctum + **TOTP MFA** (enforced for privileged roles) |
| RBAC | `spatie/laravel-permission` — 3 roles, 22+ granular permissions |
| Audit | `spatie/laravel-activitylog` — immutable trail with field-level diffs |
| Tests | PHPUnit feature tests (pipeline, RBAC, exports, auth gates) |

## What it does

- **Query Hub** — "days ago" lookback modal, live pipeline stages over
  websockets, per-run history with execution logs, cancel support
- **Ingestion pipeline** — fetch RSS/Atom/JSON → keyword focus/exception
  filtering (before AI, to save tokens) → multi-provider AI extraction
  (Gemini / Claude / OpenAI / OpenRouter / custom OpenAI-compatible, with a
  deterministic no-key fallback) → 3-stage story correlation → incremental
  merge with revision tracking → IOC + CVE verification
- **CTI Repository** — full-text + faceted search over JSONB attributes,
  developing-story lifecycle, enrichment timeline, analyst notes
- **Trust-source verification** — IOCs against OTX/Abuse.ch
  (URLhaus/MalwareBazaar/ThreatFox), CVEs against NVD + CISA KEV + FIRST EPSS,
  in-pipeline and on-demand
- **Reports** — branded HTML/PDF from analyst-editable templates, consolidated
  multi-CTI reports, STIX 2.1 / CSV exports, Sanctum-protected SIEM feeds
- **Governance** — user management, immutable audit log, secure seeding,
  session management

## Design decisions worth stealing

These were the agent's calls during the build — each one earned its place by
breaking something first:

- **Secure superadmin seeding** — the spec's hardcoded default credential was
  replaced with a random password printed once at seed time + forced password
  change and MFA on first login. Enterprise portals don't ship shared secrets.
- **`retry_after` > job runtime** — a queue lease shorter than the job runtime
  causes duplicate workers and `MaxAttemptsExceededException`. The pipeline
  sets `REDIS_QUEUE_RETRY_AFTER=7200`.
- **Plain arrays in cache** — storing objects in the cache eventually returns
  `__PHP_Incomplete_Class` across process boundaries. Serialize data, not
  objects.
- **Revision-tracked everything** — AI merges, analyst edits, and source
  changes all write field-level diffs to `cti_revisions`; `modified_at` and
  `revision_count` tell the developing-story lifecycle story.
- **Graceful degradation over hard failure** — no AI key? Deterministic
  fallback extraction still ingests. No verifier keys? IOCs/CVEs stay
  `Unknown` instead of crashing. Trust sources enrich when available.
- **Prompt layers** — per-provider prompts in the database, sane defaults in
  code; extraction prompts carry "never invent CVE IDs or IOCs" guardrails.
- **Filter before AI** — keyword exceptions run *before* extraction so noise
  never costs tokens.
- **Spec-first, test-after** — every phase ended with live browser
  verification plus a feature-test pass before moving on.

## Getting started

```bash
git clone https://github.com/<your-username>/cti-portal.git
cd cti-portal
# → full step-by-step in how_to_run.md (ends at php artisan serve)
```

- **Development quickstart:** [`how_to_run.md`](how_to_run.md)
- **Production deployment (no Docker):** [`how_to_host.md`](how_to_host.md)
- **Tests:** `php artisan test`

## Repository layout

```
app/
├── Http/Controllers/      # Query Hub, Repository, Config, Users, Auth
├── Jobs/                  # Ingestion pipeline, CVE verification passes
├── Models/                # CtiRecord, QueryRun, IocVerifier, CveVerifier…
├── Policies/              # RBAC enforcement (UserPolicy, …)
└── Services/              # FeedParser, CtiExtractor, StoryCorrelator,
                           # AiClient, IocCheckService, CveVerifierService,
                           # ReportRenderer, CtiExportService, …
resources/js/Pages/         # Inertia pages (Query Hub, Repository,
                           # Configuration, Dashboard, Users, Audit, …)
database/migrations/        # 20+ migrations (CTI schema, verifiers, audit)
how_to_run.md               # Dev quickstart
how_to_host.md              # Production deployment guide
```

## Security notes

- All secrets are environment- or UI-configured and encrypted at rest
  (`encrypted` casts on API keys)
- Default superadmin credentials are never committed — the seeder generates
  them at install time
- Privileged roles require TOTP MFA; the audit log records every
  configuration change, query run, and export with before/after diffs

## Credits

Built as a **vibe-coding collaboration**: specification and acceptance
testing by [LyTheng Se](https://github.com/selytheng), design and engineering by
[OpenClaw](https://openclaw.ai) (Claude/GLM). Framework by the Laravel
community — and to every maintainer of the open threat-intel feeds this
portal consumes: CISA, NIST NVD, abuse.ch, AlienVault OTX, FIRST, and the
independent security press.

## License

MIT
