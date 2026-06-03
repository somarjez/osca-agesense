# AgeSense Documentation Index

This folder contains all documentation for the AgeSense OSCA Senior Citizen Profiling and Analytics System.

---

## Read This First

For normal setup and defense, read these in order:

1. **[DEPLOYMENT.md](DEPLOYMENT.md)** — Complete setup guide. Start here.
2. **[ML_DEPLOYMENT.md](ML_DEPLOYMENT.md)** — ML artifact validation and service startup.
3. **[DATABASE_SHARING_AND_TEAM_SETUP.md](DATABASE_SHARING_AND_TEAM_SETUP.md)** — Syncing the database across devices.

If you are a teammate contributing code, also read:

4. **[GIT_WORKFLOW.md](GIT_WORKFLOW.md)** — Branch, commit, PR, and merge rules.

> **Warning — UPDATING_THE_MODEL.md:** Do not use this file for normal deployment or defense setup.
> It describes the retraining workflow and is only needed if the model must be retrained from scratch.
> For normal setup and defense, [DEPLOYMENT.md](DEPLOYMENT.md) and [ML_DEPLOYMENT.md](ML_DEPLOYMENT.md) are sufficient.

---

## Which File to Use for Each Purpose

| I need to... | Read this file |
|---|---|
| Set up the system from scratch | [DEPLOYMENT.md](DEPLOYMENT.md) |
| Transfer the system to another device | [DEPLOYMENT.md](DEPLOYMENT.md) → Pre-Defense Checklist section |
| Validate the ML artifacts before a demo | [ML_DEPLOYMENT.md](ML_DEPLOYMENT.md) |
| Understand the two prediction paths | [ML_DEPLOYMENT.md](ML_DEPLOYMENT.md) |
| Start or stop the Python ML services | [ML_DEPLOYMENT.md](ML_DEPLOYMENT.md) |
| Export and import the database | [DATABASE_SHARING_AND_TEAM_SETUP.md](DATABASE_SHARING_AND_TEAM_SETUP.md) |
| Make sure all devices show the same results | [DATABASE_SHARING_AND_TEAM_SETUP.md](DATABASE_SHARING_AND_TEAM_SETUP.md) |
| Understand the database tables and columns | [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) |
| Understand how the ML pipeline works internally | [ML_PIPELINE.md](ML_PIPELINE.md) |
| Update or retrain the ML model | [UPDATING_THE_MODEL.md](UPDATING_THE_MODEL.md) |
| Create a branch, commit, and open a PR | [GIT_WORKFLOW.md](GIT_WORKFLOW.md) |
| Know what has been built and what is planned | [ROADMAP.md](ROADMAP.md) |
| Understand every feature in the system | [SYSTEM_FUNCTIONALITY.md](SYSTEM_FUNCTIONALITY.md) |
| Review the GIS senior distribution/accessibility heatmap changes | [GIS_SENIOR_DISTRIBUTION_ACCESSIBILITY_HEATMAP.md](GIS_SENIOR_DISTRIBUTION_ACCESSIBILITY_HEATMAP.md) |
| Explain why results are identical across devices | [REPRODUCIBILITY_AND_CONSISTENCY.md](REPRODUCIBILITY_AND_CONSISTENCY.md) |
| Make defensible claims about the model in the defense | [model-validation-defensible-statements.md](model-validation-defensible-statements.md) |
| Give the LGU a plain-language validation summary | [VALIDATION_SUMMARY_LGU.md](VALIDATION_SUMMARY_LGU.md) |
| Understand a specific schema migration | [MIGRATION_GUIDE.md](MIGRATION_GUIDE.md) |
| Find the product/design context for AI design tooling | [`../PRODUCT.md`](../PRODUCT.md), [`../DESIGN.md`](../DESIGN.md) |

---

## What Each File Contains

### [DEPLOYMENT.md](DEPLOYMENT.md)

Complete system setup guide for Windows development. Covers system requirements, step-by-step installation using `setup.bat`, manual installation steps, environment configuration, starting the system with `start.bat`, stopping with `stop.bat`, and common setup errors.

Also includes:
- **Pre-Defense Device Checklist** — everything to do before a demo or defense on a new device
- **Before Push Checklist** — validation steps before committing and pushing code

### [ML_DEPLOYMENT.md](ML_DEPLOYMENT.md)

Everything specific to the machine learning services. Covers the two prediction paths (notebook_cache for 283 seed seniors vs live_model for new seniors), the canonical artifact directory (`python/models/`), required artifact files and their expected shapes, how to validate artifacts with `validate_model_artifacts.py`, how to start the Flask services, how to test reproducibility, and how to copy the artifact bundle to another device.

### [DATABASE_SHARING_AND_TEAM_SETUP.md](DATABASE_SHARING_AND_TEAM_SETUP.md)

How to share a validated database across team devices. Covers exporting and importing the SQL dump, setting up a shared remote MySQL for the defense, verifying prediction source distribution after import, and privacy rules for handling the dump file.

> **Note:** `DATABASE_SHARING.md` also exists and contains earlier documentation on the same topic with additional detail. `DATABASE_SHARING_AND_TEAM_SETUP.md` is the current authoritative guide. Both are kept for reference.

### [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md)

Reference documentation for every database table: columns, types, relationships, indexes, and notes on encrypted fields and soft deletes. Read this if you need to write queries or understand how data flows through the system.

### [ML_PIPELINE.md](ML_PIPELINE.md)

Deep technical reference for the ML pipeline internals: feature engineering formulas, UMAP/KMeans clustering, the GBR/RFR risk scoring ensemble, recommendation generation logic, the three-tier fallback strategy, and cross-device consistency design. This is for understanding the system, not for deployment.

### [UPDATING_THE_MODEL.md](UPDATING_THE_MODEL.md)

Step-by-step guide for retraining and redistributing the ML model. Written for the developer who owns the Jupyter notebook. Other teammates do not need this — they only need `git pull` and the artifact bundle transfer.

### [GIT_WORKFLOW.md](GIT_WORKFLOW.md)

How to contribute code: daily pull habit, branch naming, commit message format, running validation before committing, pushing, opening a pull request, and what to do after your PR is merged. Read this once before your first contribution.

### [ROADMAP.md](ROADMAP.md)

Phase-by-phase development plan. Shows which features are done, in progress, and planned. Includes a Gantt chart and milestone definitions.

### [SYSTEM_FUNCTIONALITY.md](SYSTEM_FUNCTIONALITY.md)

Comprehensive functional reference for every feature in the system: profiling, surveys, ML analysis, reports, recommendations, admin tools, roles and permissions, and security notes. Intended for thesis panelists, evaluators, and future maintainers.

### [REPRODUCIBILITY_AND_CONSISTENCY.md](REPRODUCIBILITY_AND_CONSISTENCY.md)

How AgeSense guarantees identical output on every device: age frozen to survey date, fully version-pinned Python dependencies, a SHA-256 model manifest startup check, and the `validate_system.py` harness. Read this when results drift between machines.

### [model-validation-defensible-statements.md](model-validation-defensible-statements.md)

Citable, defensible statements about the model's validity for the current v2.0.0 / K=4 build (cluster quality metrics, what the model does and does not claim). Use during the defense.

### [VALIDATION_SUMMARY_LGU.md](VALIDATION_SUMMARY_LGU.md)

A plain-language summary of validation results for the LGU and non-technical evaluators.

### [MIGRATION_GUIDE.md](MIGRATION_GUIDE.md)

Notes on schema migrations and how to apply them when moving between versions.

> **Root context files:** [`../PRODUCT.md`](../PRODUCT.md) and [`../DESIGN.md`](../DESIGN.md) live at the project root. They capture the product strategy (users, purpose, principles) and the forest/ink visual design system for AI design tooling and new contributors.

---

## Recommended Reading Order for Teammates

**Before first setup (any device):**
1. [DEPLOYMENT.md](DEPLOYMENT.md)
2. [ML_DEPLOYMENT.md](ML_DEPLOYMENT.md)
3. [DATABASE_SHARING_AND_TEAM_SETUP.md](DATABASE_SHARING_AND_TEAM_SETUP.md)

**Before contributing code:**
4. [GIT_WORKFLOW.md](GIT_WORKFLOW.md)

**Before a demo or defense:**
5. [DEPLOYMENT.md](DEPLOYMENT.md) — Pre-Defense Device Checklist section
6. [ML_DEPLOYMENT.md](ML_DEPLOYMENT.md) — Validating Artifacts section

**For understanding the system:**
7. [ML_PIPELINE.md](ML_PIPELINE.md)
8. [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md)
9. [SYSTEM_FUNCTIONALITY.md](SYSTEM_FUNCTIONALITY.md)
