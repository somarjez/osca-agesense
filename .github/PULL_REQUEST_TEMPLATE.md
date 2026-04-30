## What does this PR do?

<!-- One or two sentences. What is the end result a user or developer will see? -->

## Why is this change needed?

<!-- What problem does it solve, or what feature does it add? Link to an issue if one exists. -->
Closes #

## Type of change

<!-- Check all that apply -->
- [ ] Bug fix
- [ ] New feature
- [ ] UI / style change
- [ ] Refactor (no behaviour change)
- [ ] Database migration
- [ ] ML / Python change
- [ ] Documentation
- [ ] CI / configuration

## What changed?

<!-- List the key files or areas modified and briefly explain each. -->
| Area | What changed |
|---|---|
| `app/` | |
| `resources/views/` | |
| `database/migrations/` | |
| `python/` | |
| `routes/` | |

## Database migrations

- [ ] No migration in this PR
- [ ] Migration included — backward compatible (additive only)
- [ ] Migration included — **breaking** (column removed / renamed / type changed)

<!-- If breaking, describe the rollback plan: -->

## How to test

<!-- Step-by-step instructions so a reviewer can verify the change works. -->
1.
2.
3.

**Expected result:**

## Screenshots / recordings

<!-- Attach before/after screenshots for any UI changes. Delete this section if not a UI change. -->

| Before | After |
|---|---|
| | |

## Checklist

- [ ] CI passes (php-checks + js-build green)
- [ ] No `dd()`, `dump()`, or `var_dump()` left in code
- [ ] `.env` is **not** committed
- [ ] Migrations run cleanly (`php artisan migrate`)
- [ ] Tested in both light mode and dark mode (if UI change)
- [ ] `APP_DEBUG=false` behaviour verified (no stack traces exposed)
- [ ] Commit messages follow `type(scope): description` format

## Notes for reviewer

<!-- Anything tricky, a conscious trade-off, or something to pay extra attention to. -->
