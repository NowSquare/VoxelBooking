# CLAUDE.md

Read `.ai/00-VoxelBooking-Promps.md` before any work. It defines the required document order, the living-document sync rules, and the compliance session gate.

## Document Chain

Read in this order. Each builds on the last.

| # | File | Governs |
|---|------|---------|
| 0 | `.ai/00-VoxelBooking-Promps.md` | Read order, living-doc rules, compliance session rule, rewrite manifest, entropy reminder |
| 1 | `.ai/10-VoxelBooking-PRD.md` | **Source of truth.** Architecture, data, features, scope, privacy, accessibility. PRD wins on technical matters |
| 2 | `.ai/11-VoxelBooking-Visual-Design.md` | Every visual and UX detail. Design doc wins on visual matters |
| 3 | `.ai/13-VoxelBooking-Voice-Guide.md` | All copy and messaging across every surface |
| 4 | `.ai/24-VoxelBooking-PHP-JS-translations.md` | Translation discipline, locale resolution, formatting rules |

Read `.agent/AGENTS.md` for the superpowers execution model (TDD, systematic debugging, brainstorming, planning, verification).

## Mandatory Gates

These checklists fire on specific change types. Consult the relevant gate before committing.

| Gate | File | Trigger |
|------|------|---------|
| **Legal/Compliance** | `.ai/23-VoxelBooking-Legal-Logging.md` | Any change touching data, auth, cookies, API, logging, exports, retention, messaging, privacy, or external services. **Not optional.** Read §9 and answer every applicable question |
| **Demo Mode** | `.ai/20-VoxelBooking-Demo-Checklist.md` | Any change to admin surfaces, booking page, or public endpoints |
| **Roles/Permissions** | `.ai/21-VoxelBooking-Roles-Checklist.md` | Any new admin surface, endpoint, or capability |
| **Agent API** | `.ai/22-VoxelBooking-Agent-API-Checklist.md` | Any change to API endpoints, data models, or external agent access |
| **i18n** | `.ai/24-VoxelBooking-PHP-JS-translations.md` | Any change to user-facing strings. No hardcoded English. Every string through `__()` (PHP) or `t()` (JS) |

The `.agent/workflows/` directory contains matching workflow triggers for each gate.

## Living Documents

Update these **during the session**, not after. The next session reads them to understand the product. Stale docs mean wrong assumptions. Present tense only, no "changed" or "updated" annotations.

- **`.ai/10-VoxelBooking-PRD.md`** — architecture, features, engine behavior, endpoints, settings, data models
- **`.ai/11-VoxelBooking-Visual-Design.md`** — visual language, tokens, components, animations
- **`README.md`** — setup, requirements, feature overview, technology stack

## Core Rules

- Test-driven development is the default for features and bug fixes.
- Use systematic debugging before patching unexpected behavior.
- Brainstorm before changing product behavior or implementing new features.
- Write a plan before non-trivial implementation.
- Verify with fresh evidence before claiming completion.
- No hardcoded English. Every user-visible string goes through the translation engine.
- Formatting through the Locale engine. Never raw `date()`, `number_format()`, or manual currency strings.
- All CSS must use canonical design tokens. Run `python3 scripts/audit-css-tokens.py` before committing CSS changes.

## Session End

Before closing a session, follow the commit and doc-update workflows:

1. `.ai/30-VoxelBooking-Commit-Changes.md` — sync living documents, update public docs, stage and commit
2. `.ai/31-VoxelBooking-Update-Docs-Changelog.md` — changelog, marketing site features, CodeCanyon changelog

## Project DNA

The booking page is the product. Not the admin dashboard. If the booking page is extraordinary, everything else is forgiven.

Design is the moat. Every booking page is a portfolio piece. No combination of business settings can produce an ugly page.

Craft over code. Variable names tell stories. Functions do one thing. Comments explain why, not what. The next developer should understand not just what it does but why it exists.

This codebase will outlive you. Every shortcut compounds into technical debt. Every pattern you establish will be copied. Take your time to do it right.
