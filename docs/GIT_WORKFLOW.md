# Git Workflow Guide — AgeSense / OSCA System

> Step-by-step walkthrough for teammates: from cloning the repo to getting a PR merged.
> Read this once before your first contribution. Refer back when in doubt.

---

## Table of Contents

1. [One-time Setup — Clone and Configure](#1-one-time-setup--clone-and-configure)
2. [Daily Workflow — Start of Every Work Session](#2-daily-workflow--start-of-every-work-session)
3. [Making Changes](#3-making-changes)
4. [Commit Message Format](#4-commit-message-format)
5. [Pushing Your Branch](#5-pushing-your-branch)
6. [Opening a Pull Request](#6-opening-a-pull-request)
7. [While Your PR Is Under Review](#7-while-your-pr-is-under-review)
8. [After Your PR Is Merged](#8-after-your-pr-is-merged)
9. [Branch Naming Rules](#9-branch-naming-rules)
10. [Do's and Don'ts](#10-dos-and-donts)
11. [Common Mistakes and How to Fix Them](#11-common-mistakes-and-how-to-fix-them)
12. [Quick Reference Card](#12-quick-reference-card)

---

## 1. One-time Setup — Clone and Configure

Do this only once on a new machine.

### Step 1 — Clone the repository

```bash
git clone https://github.com/somarjez/osca-agesense.git
cd osca-agesense
```

### Step 2 — Set your name and email in Git

Your name and email appear on every commit you make. Use the same email as your GitHub account.

```bash
git config --global user.name "Your Full Name"
git config --global user.email "your-email@example.com"
```

Verify it was saved:

```bash
git config --global user.name
git config --global user.email
```

### Step 3 — Install all project dependencies

Run all four of these in sequence:

```bash
# PHP dependencies
composer install

# Node.js / frontend dependencies
npm install

# Python dependencies (from inside the python/ folder)
cd python
python -m venv venv

# Activate the virtual environment:
# Windows:
venv\Scripts\activate
# macOS/Linux:
source venv/bin/activate

pip install -r requirements.txt
cd ..
```

### Step 4 — Set up your environment file

```bash
cp .env.example .env
php artisan key:generate
```

Then open `.env` and fill in your local database credentials. See the README for all required fields.

### Step 5 — Set up the database

```bash
php artisan migrate
php artisan db:seed
```

> The seeder (`OscaCsvSeeder`) reads from `../osca.csv` — one level above the project root. Place the file there before seeding.

### Step 6 — Verify everything works

```bash
php artisan serve
```

Open `http://127.0.0.1:8000` and log in with:
- Email: `admin@osca.local`
- Password: `password`

If the page loads, you are ready.

---

## 2. Daily Workflow — Start of Every Work Session

**Every single day, before you touch any files**, do this:

```bash
# Switch to the main branch
git checkout main

# Download the latest changes from GitHub
git pull origin main
```

This keeps your local `main` up to date. If you skip this step you will eventually have conflicts because you were working on outdated code.

After pulling, create or switch to your feature branch (see [Step 3](#3-making-changes)).

---

## 3. Making Changes

### Step 1 — Create a branch for your work

**Never commit directly to `main`.** Create a branch first.

```bash
git checkout main
git pull origin main
git checkout -b feat/your-feature-name
```

Replace `feat/your-feature-name` with a name that describes what you are doing. See [Branch Naming Rules](#9-branch-naming-rules) for the exact format.

Examples:

```bash
git checkout -b feat/add-barangay-filter
git checkout -b fix/modal-button-invisible-dark-mode
git checkout -b chore/update-python-requirements
```

### Step 2 — Make your code changes

Edit files normally. Do your work. Test it.

### Step 3 — Check what files you changed

```bash
git status
```

This shows which files were modified, added, or deleted.

### Step 4 — Stage only the files relevant to your task

**Do not use `git add .` or `git add -A` blindly.** Stage specific files:

```bash
git add path/to/changed/file.php
git add path/to/another/file.blade.php
```

If you changed many files in the same folder:

```bash
git add app/Http/Controllers/SeniorCitizenController.php
git add resources/views/seniors/index.blade.php
```

Check what is staged before committing:

```bash
git diff --staged
```

### Step 5 — Commit your changes

```bash
git commit -m "feat: add barangay-level filter to risk report"
```

See [Commit Message Format](#4-commit-message-format) for the exact rules.

### Step 6 — Repeat Steps 2–5 as you work

Commit often — one commit per logical change. Do not bundle unrelated changes into one commit.

---

## 4. Commit Message Format

All commits must follow the **Conventional Commits** format. The branch protection rules on `main` enforce this — a commit that does not match the pattern will be rejected.

### Format

```
<type>(<optional scope>): <short description>
```

### Types

| Type | Use it when |
|---|---|
| `feat` | Adding a new feature or page |
| `fix` | Fixing a bug |
| `chore` | Config, tooling, dependencies, build scripts |
| `docs` | Updating documentation only |
| `refactor` | Restructuring code without changing behavior |
| `test` | Adding or fixing tests |
| `style` | CSS, formatting — no logic change |
| `perf` | Performance improvement |
| `ci` | CI/CD pipeline changes (GitHub Actions) |

### Examples — Good commits

```
feat: add archived QoL surveys section to archives page
fix: modal buttons invisible in dark mode due to CSS class override
chore: add pyrightconfig.json to fix Pylance import resolution
docs: update GIT_WORKFLOW.md with PR template walkthrough
refactor: extract cluster query helpers into ClusterAnalyticsService
test: add batch KMeans validation to test_ml_pipeline.py
style: fix delete button contrast in senior profile view
ci: wire python-ml-tests job to required status checks in ruleset
```

### Examples — Bad commits (will be rejected or look unprofessional)

```
# Too vague — what changed?
update

# No type prefix
fixed the bug on the modal

# All caps, not following format
FIX MODAL

# Too long and rambling
made some changes to the senior citizen form and also fixed the button colors and updated some css
```

### Optional scope

Add a scope in parentheses to narrow down where the change is:

```
feat(dashboard): add risk level filter to KPI cards
fix(qol-survey): prevent draft deletion when survey is processing
chore(python): upgrade umap-learn to 0.5.6
```

---

## 5. Pushing Your Branch

Once you have at least one commit, push your branch to GitHub:

```bash
git push origin feat/your-feature-name
```

The first time you push a new branch, use `-u` to set the upstream tracking:

```bash
git push -u origin feat/your-feature-name
```

After that, you can just run `git push` while on that branch.

### If you get a rejected push

This usually means someone pushed to the same branch since you last pulled. Fix it with:

```bash
git pull --rebase origin feat/your-feature-name
git push
```

**Never force push to a shared branch.** If you are unsure, ask first.

---

## 6. Opening a Pull Request

### Step 1 — Go to GitHub

Open [https://github.com/somarjez/osca-agesense](https://github.com/somarjez/osca-agesense) in your browser.

### Step 2 — Click "Compare & pull request"

GitHub shows a yellow banner after you push a branch. Click it. Or go to **Pull requests → New pull request**.

### Step 3 — Set the target branch

Make sure you are merging **into `main`** (or `develop` if working on a shared feature branch), not the other way around.

- **Base branch:** `main`
- **Compare branch:** your feature branch

### Step 4 — Fill in the PR template

The PR form auto-fills with a template. **Fill in every section.** Do not delete sections you think are not needed — write "N/A" if something does not apply.

The template asks for:

| Section | What to write |
|---|---|
| **What / Why** | One sentence on what the PR does and why it is needed |
| **Type of change** | Check the relevant box: bug fix, new feature, etc. |
| **What changed** | A brief table of files you changed and what changed in each |
| **Database migrations** | Yes/No — if yes, name the migration file |
| **How to test** | Exact steps a reviewer should follow to manually test your change |
| **Screenshots** | Before/after screenshots for any visual UI changes |
| **Checklist** | Check off every item — do not skip this |
| **Notes for reviewer** | Anything the reviewer needs to know that is not obvious from the code |

### Step 5 — Request a reviewer

On the right sidebar, click **Reviewers** and select your teammate. Do not merge your own PR without a review.

### Step 6 — Submit the PR

Click **Create pull request**.

---

## 7. While Your PR Is Under Review

### What to do while waiting

- Continue working on a different task on a new branch
- Do not keep committing to the PR branch after submitting unless responding to review comments
- Check GitHub notifications for reviewer comments

### Responding to review comments

If a reviewer requests changes:

1. Read the comment carefully
2. Make the requested change in your local branch
3. Commit it with a clear message:
   ```bash
   git commit -m "fix: address review comment on modal button contrast"
   ```
4. Push to the same branch:
   ```bash
   git push
   ```
5. Reply to the comment on GitHub to let the reviewer know it is addressed

### If your branch is out of date with main

GitHub will warn you if `main` has new commits that are not in your branch. Update your branch:

```bash
git checkout feat/your-feature-name
git fetch origin
git rebase origin/main
git push --force-with-lease
```

> `--force-with-lease` is safe — it only force-pushes if no one else has pushed to your branch since your last push. Never use plain `--force` on a shared branch.

### CI checks must pass

Three automated checks run on every PR:

| Check | What it does |
|---|---|
| `ci / php-checks` | PHP syntax check, database migrations, PHPUnit tests, debug statement scan |
| `ci / python-ml-tests` | Runs `python/tests/test_ml_pipeline.py` — validates the full ML pipeline |
| `ci / js-build` | Runs `npm ci && npm run build` — confirms the frontend compiles |

All three must show a green checkmark before the PR can be merged. If a check fails, click it to read the error log, fix the issue, and push again.

---

## 8. After Your PR Is Merged

### Step 1 — Switch back to main and pull

```bash
git checkout main
git pull origin main
```

### Step 2 — Delete your local feature branch

```bash
git branch -d feat/your-feature-name
```

GitHub deletes the remote branch automatically after merging (if the repo is configured to do so). If not, delete it manually on GitHub: **Pull requests → Closed → your PR → Delete branch**.

### Step 3 — Start fresh for your next task

Go back to [Step 2 — Daily Workflow](#2-daily-workflow--start-of-every-work-session) and create a new branch from the updated `main`.

---

## 9. Branch Naming Rules

Branch names must match this pattern (enforced by the ruleset):

```
(main|develop|feat|fix|hotfix|chore|docs|refactor|test)(/[a-z0-9][a-z0-9-]*)?
```

### Valid branch names

```
feat/add-barangay-filter
fix/modal-dark-mode-contrast
chore/update-composer-deps
docs/update-ml-pipeline-readme
refactor/extract-cluster-service
hotfix/crash-on-empty-qol-survey
test/add-batch-kmeans-validation
develop
```

### Invalid branch names (will be rejected on push)

```
my-feature          # No type prefix
Feature/AddFilter   # Uppercase letters not allowed
feat/Add_Filter     # Underscores not allowed in the slug
fix-button          # Missing slash separator
HOTFIX              # Uppercase
```

---

## 10. Do's and Don'ts

### Do's

- **Do** pull from `main` at the start of every session before creating a branch
- **Do** create one branch per task — do not bundle multiple unrelated features
- **Do** commit frequently with clear, descriptive messages
- **Do** stage specific files, not everything at once
- **Do** fill in the PR template completely
- **Do** request a reviewer on every PR
- **Do** fix CI failures before asking for a re-review
- **Do** delete your branch after it is merged
- **Do** communicate in PR comments if you are blocked or need clarification

### Don'ts

- **Don't** push directly to `main` — the branch is protected, it will be rejected
- **Don't** merge your own PR without at least one approval
- **Don't** force-push to `main` or any shared branch
- **Don't** commit `.env` files — they are in `.gitignore` for a reason
- **Don't** commit `vendor/`, `node_modules/`, or `python/venv/` — these are also gitignored
- **Don't** use `git add .` without checking `git status` first — you might stage files you do not intend to commit
- **Don't** squash or rebase someone else's commits without discussing it first
- **Don't** leave debug code (`dd()`, `dump()`, `var_dump()`, `print_r()`) in committed files — the CI pipeline will reject it
- **Don't** leave a PR open without responding to review comments for more than 2 days
- **Don't** create branches from another feature branch (unless intentionally working on a sub-feature) — always branch from `main`

---

## 11. Common Mistakes and How to Fix Them

### "I committed to main directly"

Stop before pushing. Move your commit to a new branch:

```bash
# Create a new branch with your commit
git checkout -b feat/your-feature-name

# Reset main back to where it was (your commit stays on the new branch)
git checkout main
git reset --hard origin/main
```

Now your commit is on the feature branch where it belongs. Open a PR from there.

### "I pushed to the wrong branch"

If you pushed to `main` and it was rejected (it will be, because of branch protection), you are fine — the commit never landed. Create the correct branch and push there instead.

### "My branch has conflicts with main"

```bash
git checkout feat/your-feature-name
git fetch origin
git rebase origin/main
```

Git will pause at each conflicting file. Open the file, resolve the conflict markers (`<<<`, `===`, `>>>`), then:

```bash
git add path/to/resolved/file
git rebase --continue
```

When done, push:

```bash
git push --force-with-lease
```

### "I committed the .env file by accident"

If you have not pushed yet:

```bash
# Unstage .env from the last commit
git rm --cached .env
git commit -m "chore: remove .env from tracking"
```

If you already pushed — tell the team lead immediately. The `.env` may contain secrets that need to be rotated.

### "My commit message is wrong but I have not pushed yet"

```bash
git commit --amend -m "fix: correct description"
```

Only do this before pushing. Never amend a commit that is already on GitHub.

### "I need to undo my last commit but keep my changes"

```bash
git reset HEAD~1
```

Your changes go back to unstaged. You can re-stage and recommit.

### "The CI php-checks job failed with 'debug statements found'"

Search your code for `dd(`, `dump(`, `var_dump(`, or `print_r(` and remove them. Then commit and push the fix.

### "The CI python-ml-tests job failed"

Run the tests locally to see the error:

```bash
cd python
venv\Scripts\activate        # Windows
python tests/test_ml_pipeline.py
```

Fix what is failing, commit, and push.

### "The CI js-build job failed"

Run locally:

```bash
npm run build
```

Fix any Vite/TypeScript errors shown in the output.

---

## 12. Quick Reference Card

```
SETUP (once)
  git clone https://github.com/somarjez/osca-agesense.git
  git config --global user.name "Your Name"
  git config --global user.email "you@example.com"

START OF EVERY SESSION
  git checkout main
  git pull origin main

START A NEW TASK
  git checkout -b feat/your-feature-name

SAVE YOUR WORK
  git status                        ← see what changed
  git add path/to/file              ← stage specific files
  git diff --staged                 ← verify what you are about to commit
  git commit -m "feat: description" ← commit with conventional format

PUSH TO GITHUB
  git push -u origin feat/your-feature-name   ← first push
  git push                                     ← subsequent pushes

AFTER PR IS MERGED
  git checkout main
  git pull origin main
  git branch -d feat/your-feature-name

COMMIT TYPES:  feat  fix  chore  docs  refactor  test  style  perf  ci

BRANCH FORMAT: feat/short-description  fix/short-description  chore/short-description
```
