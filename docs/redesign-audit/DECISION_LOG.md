# Decision log and bounded Dashboard proposal

| Decision | Alternative rejected | Evidence / reason | Revisit |
|---|---|---|---|
| Audit docs only on redesign/audit-v1, Draft PR #21 | UI patch before observed baseline | Master Plan Phase A and handoff; unknowns still High/Critical | After runtime gates + pilot approval |
| Correct Master Plan first | Trust historical architecture list | No discovered fast-nav/hydration loader; Store landing confirmed | If actual runtime manifest differs |
| Keep dormant modules dormant | Include old JS to satisfy intended design | Old fast-nav owns body/main and could create collisions | Separate explicit architectural proposal only |
| Keep iframe and native page navigation | New SPA/shell in pilot | Existing consumers and music continuity | Separate proven ownership decision |
| Treat Dashboard as engineering pilot | Make it post-login landing | app.php and redirectByRole select buy.php | Only explicit product change |
| Do not silently populate Recent Keys | Reuse dormant dynamic fragment | Current source empty/unassigned branch; behavior change outside styling | After baseline + separate feature decision |
| Record Store autofocus and activity bfcache hypotheses | Fix shared behavior during visual pilot | Existing source paths; not yet reproduced | Separate regression evidence and approved scope |
| Do not use Production as runtime baseline experiment | Authenticated GET/cron probes | Request-time writes and explicit no-production authorization | Only separately authorized scope |
| Figma dependency diagram only, destination pending | New UI design based on assumptions | User requested Figma, audit gate excludes implementation | Widget plan selection; runtime evidence first |

## Proposed pilot scope (not authorized to implement by this report)

Goal: improve presentation/readability of the existing server-rendered User Dashboard only, maintaining all listed functions, native links and render branches. No new live hydration, fast navigation, balance feed or hidden duplicate UI.

**Expected application file:** `public_html/user/dashboard.php` only. Any approved markup/style change must be rooted under a Dashboard-specific main container. Do not broaden `.glass` or other global selectors to restyle shared nav. Preserve current PHP preamble, includes, data-lang contracts, activity include, CTA/query construction and role checks. The exact visual design is deliberately not locked before real baseline render/state review.

**Audit documentation updates:** `docs/SAKAZUKI_REDESIGN_MASTER_PLAN.md` and relevant `docs/redesign-audit/*.md` for observed evidence/decisions. If a component-specific asset becomes necessary, identify its exact new path and consumer and revise the scope before editing; no implicit permission to alter shared assets.

**Protected files outside pilot:** `public_html/app.php`; all three role `nav.php`; every `public_html/assets/js/*` and existing CSS asset; `public_html/user/dashboard_dynamic.php` and `dashboard_dynamic_content.php`; all `includes/*`; User/Reseller buy.php; deposit/auth/verification routes; all Reseller/Admin pages; `private/*`, tests not relevant to an identified regression, and `.github/workflows/*`. References are audited, not modified.

Function/state coverage is in BEHAVIOR_INVARIANTS.md. Exact viewport/context/assertions are in TEST_MATRIX.md. Baseline screenshots/recordings do not yet exist; no implementation readiness claimed. High-risk blockers are U-001 through U-006 and context-dependent U-009/U-012/U-014. Resolve relevant blockers before asking to approve implementation as ready.

## Rollback proposal

Before coding, record the then-current main SHA and create separate `redesign/user-dashboard-v1` from it; do not assume this audit SHA is still latest. Commit pilot changes separately from any needed behavior fix. Rollback on the working branch by reverting the specific pilot commit, preserving subsequent unrelated work; review the diff and rerun the relevant minimal matrix. No database migration, ledger mutation or asset activation belongs in pilot.

No merge or Production deploy is part of this audit. A later production request must separately list exact approved files, read-only compare host state, back up those files, define restore steps and receive explicit approval. Do not use broad FTP deployment. Source revert alone is not a completed host rollback.

## Gate status

Source consumer audit package is reviewable; it is not the complete runtime audit. Continue safe source investigation where useful. Do not proceed to UI implementation while runtime evidence is absent. The Master Plan Phase A / handoff requires pilot acceptance after the evidence package; this session's permission to audit does not waive that gate.
