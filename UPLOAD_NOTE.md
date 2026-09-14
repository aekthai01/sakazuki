# Reseller Program — Host Upload Note

## Host files changed

Upload these files to the same paths on the production host:

1. `public_html/user/reseller_program.php` — new user-only reseller application/information page.
2. `public_html/user/nav.php` — adds the “สมัครตัวแทน / Become a Reseller” link to the desktop More menu and mobile drawer.

## No host upload needed

The following files are repository/workflow documentation only and do not need to be uploaded to the website:

- `.gitignore`
- `PROJECT_WORKFLOW.md`
- `DEPLOY_NOTE_RESELLER_PROGRAM.md`
- `private/xchetos.php.example`

## Deployment characteristics

- No database migration.
- No schema change.
- No registration/authentication rewrite.
- No reseller/admin page changes.
- Direct access to `user/reseller_program.php` requires an authenticated account with role `user`; other roles are redirected by the existing auth system.
- Telegram application contacts: `@DrkZeref` and `@om_shop99`.
