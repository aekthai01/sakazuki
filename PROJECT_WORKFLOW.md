# BLACKUP V3 — Working Notes

This repository is the reviewable source snapshot for BLACKUP V3.

## Working agreement

- Make changes in the project source first and keep them surgical.
- Do not upload changes directly to the production host unless the owner explicitly asks for host deployment.
- After each completed code change, provide the owner with a downloadable patch containing the changed/new host files and a short upload note with the exact destination paths.
- The owner manually uploads the provided files to the production host.
- When a feature is requested for normal users only, keep it inside the `public_html/user/` surface and avoid changing reseller/admin/auth flows unless the feature truly requires it.
- Before important edits, trace the existing auth/role and affected code paths. After edits, run focused syntax/static checks and review the final diff.

## Repository safety

Production credentials are intentionally excluded from GitHub. Never commit real database credentials, supplier API credentials, passwords, encryption keys, recovery secrets, or runtime secret files. See `.gitignore` and the `.example` configuration files.

## Current reseller-program decision

The first version uses admin-reviewed onboarding:

1. Existing user opens the reseller-program page.
2. User sends sales-channel information to one of the official Telegram admins.
3. Admin reviews the application manually.
4. If approved, the applicant adds at least 500 THB opening credit.
5. Admin creates/opens the reseller account using the existing admin reseller-management flow.

No public self-service role upgrade is added.
