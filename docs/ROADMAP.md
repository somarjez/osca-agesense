# Project Roadmap — AgeSense

> **System:** AgeSense — OSCA Senior Citizen Profiling and Analytics System
> **Last Updated:** 2026-06-04
> **Status:** Phase 1, Phase 2, and Phase 3 (GIS module) complete — including bulk geocoding, accessibility proximity scoring, GIS CSV export, road-network route distances, and OpenStreetMap facility import. (The manual profile coordinate-picker was built then removed; `gis:geocode` is the sole coordinate source.) Phase 4 planned.

---

## Table of Contents

1. [Phase Summary](#1-phase-summary)
2. [Development Gantt Chart](#2-development-gantt-chart)
3. [Phase 1 — Core System (Completed)](#3-phase-1--core-system-completed)
4. [Phase 2 — Production Hardening (In Progress)](#4-phase-2--production-hardening-in-progress)
5. [Phase 3 — GIS Module](#5-phase-3--gis-module)
6. [Phase 4 — Advanced Features](#6-phase-4--advanced-features)
7. [Milestone Definitions](#7-milestone-definitions)
8. [Feature Backlog](#8-feature-backlog)

---

## 1. Phase Summary

| Phase | Name | Target Period | Status |
|---|---|---|---|
| Phase 1 | Core System | Jan 2026 – Apr 2026 | ✅ Complete |
| Phase 2 | Production Hardening | May 2026 | ✅ Complete |
| Phase 3 | GIS Module | May 2026 – Jun 2026 | ✅ Complete |
| Phase 4 | Advanced Features | Jun 2026 – Jul 2026 | 📋 Planned |

---

## 2. Development Gantt Chart

```
FEATURE / TASK                          Jan  Feb  Mar  Apr  May  Jun  Jul  Aug  Sep  Oct  Nov  Dec
                                        2026 2026 2026 2026 2026 2026 2026 2026 2026 2026 2026 2026
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

PHASE 1 — CORE SYSTEM
─────────────────────
Senior citizen profile (6-step form)    ████ ░░░░ ░░░░ ░░░░
QoL survey instrument (32 items)        ████ ████ ░░░░ ░░░░
ML preprocessing pipeline              ░░░░ ████ ████ ░░░░
K-Means clustering (K=4) + UMAP        ░░░░ ████ ████ ░░░░
Risk scoring ensemble (GBR + RFR)       ░░░░ ░░░░ ████ ████
Recommendation generation engine        ░░░░ ░░░░ ████ ████
Dashboard + analytics charts            ░░░░ ░░░░ ████ ████
Health Group (cluster) analysis report  ░░░░ ░░░░ ░░░░ ████
Risk report + CSV export                ░░░░ ░░░░ ░░░░ ████
Recommendation management               ░░░░ ░░░░ ░░░░ ████
Batch ML inference                      ░░░░ ░░░░ ░░░░ ████
Three-tier ML fallback strategy         ░░░░ ░░░░ ████ ████
PDF export (individual profile)         ░░░░ ░░░░ ░░░░ ████
CSV bulk import seeder                  ░░░░ ░░░░ ░░░░ ████
Authentication (session-based)          ████ ░░░░ ░░░░ ░░░░
Soft delete / archive / restore         ░░░░ ░░░░ ████ ░░░░
CI/CD pipeline (GitHub Actions)         ░░░░ ░░░░ ░░░░ ████
Dark mode toggle                        ░░░░ ░░░░ ░░░░ ████
Help Centre (in-app user guide)         ░░░░ ░░░░ ░░░░ ████
UI terminology simplification           ░░░░ ░░░░ ░░░░ ████

PHASE 2 — PRODUCTION HARDENING  ✅ ALL DONE
───────────────────────────────
Role-based access control (RBAC)        ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓
Activity audit logging                  ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓
Queued batch ML inference               ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓
Dynamic cluster evaluation metrics     ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓
Data Privacy Act compliance review      ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓
Barangay report page (complete stub)    ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓
Excel export (maatwebsite/excel)        ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓
Cluster snapshot generation             ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓
Linux/macOS ML service startup script  ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓

PHASE 3 — GIS MODULE  ✅ ALL DONE
─────────────────────
GIS field migration (lat/lng/source)    ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓
Facilities table + Pagsanjan seeder     ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓
Accessibility metrics table             ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓
GIS map view — senior pins + POI        ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓
GIS API (seniors/facilities/boundary)   ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓
Bulk geocode (barangay centroids)       ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓
Map coordinate picker (built, then removed)  ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████  ✗
Accessibility proximity scoring         ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓
GIS CSV export (lat/lng + distances)    ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓
Field GPS data collection workflow      ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓
Road-network route distances (ORS)      ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓
OpenStreetMap facility import           ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████  ✓

PHASE 4 — ADVANCED FEATURES  📋 PLANNED
─────────────────────────────
Longitudinal risk tracking dashboard   ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████ ░░░░
ML model retraining pipeline            ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████ ░░░░
Senior photo upload                     ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████
Survey versioning UI                    ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████
Mobile-responsive field entry UI        ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████ ████
OSCA network multi-office support       ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████

Legend:  ████ = Active development / done   ░░░░ = Not yet started   ✓ = Complete
```

---

## 3. Phase 1 — Core System (Completed)

**Period:** January 2026 – April 2026
**Status:** ✅ Complete

All primary workflows are implemented and tested:

| Deliverable | Status | Notes |
|---|---|---|
| Senior citizen profile management (6-step form) | ✅ Done | Create, edit, archive, restore, force delete |
| WHO-aligned QoL survey (32 items, 8 domains) | ✅ Done | Draft save, submission, per-domain scoring |
| ML preprocessing pipeline | ✅ Done | 35+ features, 6 section scores, UMAP reduction |
| K-Means clustering (K=4) | ✅ Done | UMAP 10-D input, cluster_metadata.json override (updated from K=3) |
| Risk scoring ensemble (GBR + RFR) | ✅ Done | 45% rule + 35% GBR + 20% RFR |
| Prescriptive recommendation engine | ✅ Done | 5 domains, 22+ disease-specific action sets |
| Interactive dashboard | ✅ Done | Real-time KPIs, charts, filters, 60s poll |
| Health Group (cluster) analysis report | ✅ Done | Evaluation metrics, barangay breakdown |
| Risk report | ✅ Done | Sortable, filterable, CSV export |
| Batch ML inference | ✅ Done | 100-senior chunks, progress indicator |
| Three-tier ML fallback | ✅ Done | HTTP → subprocess → PHP heuristic |
| PDF export | ✅ Done | Individual senior profile via dompdf |
| CSV bulk import | ✅ Done | OscaCsvSeeder with full ML pipeline trigger |
| Session-based authentication | ✅ Done | Laravel Breeze |
| Soft delete / archive / restore | ✅ Done | Senior cascade to surveys |
| CI/CD pipeline | ✅ Done | GitHub Actions: PHP checks + Python tests + JS build |
| In-app Help Centre | ✅ Done | FAQs, user guide, 8 sections |
| UI terminology simplification | ✅ Done | Plain-language labels replacing ML jargon |
| Archived-senior exclusion from cluster analysis | ✅ Done | `whereHas('seniorCitizen')` fix |
| Sidebar reorganisation | ✅ Done | Archives, Assessment Tools, Help sections |

---

## 4. Phase 2 — Production Hardening (Complete)

**Period:** May 2026
**Status:** ✅ Complete

All security, reliability, and operational gaps identified before pilot deployment have been addressed.

| Deliverable | Priority | Status | Description |
|---|---|---|---|
| Role-based access control (RBAC) | High | ✅ Done | `spatie/laravel-permission`. Roles: `admin`, `encoder`, `viewer`; middleware + route guards + conditional sidebar |
| Activity audit logging | High | ✅ Done | Eloquent observers on Senior, Survey, Recommendation models |
| Dynamic cluster evaluation metrics | Medium | ✅ Done | Read metrics from JSON file alongside model artefacts |
| Data Privacy Act compliance review | High | ✅ Done | Field-level encryption for PII, consent field, retention policy |
| Barangay report page | Medium | ✅ Done | Complete the `reports.barangay` route with view and controller |
| Queued batch ML inference | Medium | ✅ Done | `ProcessMlBatch` job dispatched via Laravel queue |
| Excel export | Low | ✅ Done | Full registry export via `maatwebsite/excel` at `/reports/registry/export`; sidebar link under Administration |
| Cluster snapshot generation | Low | ✅ Done | `osca:snapshot-clusters` command; scheduled daily at 23:55; on-demand "Take Snapshot" button on cluster report |
| Linux/macOS ML service startup | Low | ✅ Done | `start_services.sh` committed alongside the PowerShell script |

---

## 5. Phase 3 — GIS Module

**Period:** May 2026 – June 2026
**Status:** ✅ Complete

The GIS module adds geographic visualisation of senior citizen locations and proximity analysis to essential services. See SYSTEM_FUNCTIONALITY.md §18 and [GIS_FUNCTIONALITY_AND_MODIFIED_FILES.md](GIS_FUNCTIONALITY_AND_MODIFIED_FILES.md) for the full technical specification.

### Completed (Sprint 3.1 — Data Foundation)

| Task | Status | Notes |
|---|---|---|
| GIS fields on `senior_citizens` | ✅ Done | `latitude`, `longitude`, `location_source`, `location_accuracy`, `location_verified_at`; spatial index |
| `facilities` table + migration | ✅ Done | Stores health centres, hospitals, pharmacies, markets, barangay halls with lat/lng |
| `senior_accessibility_metrics` table | ✅ Done | Links seniors to nearest facilities per category with distances |
| Pagsanjan facility seeder | ✅ Done | `PagsanjanFacilitySeeder` — 13 facilities across 16 barangays |
| GIS API endpoints | ✅ Done | `/api/gis/seniors`, `/api/gis/facilities`, `/api/gis/boundary/pagsanjan`, `/api/gis/boundary/barangays` |
| GIS map view (`/reports/gis`) | ✅ Done | Leaflet map prototype with generalised senior pins, facility overlay, risk filters, stats panel |
| Privacy-safe coordinate generalisation | ✅ Done | Hash-based offset per senior around barangay anchor — no exact home locations exposed |

### Completed (Sprint 3.2 — Completion)

| Task | Status | Description |
|---|---|---|
| Bulk geocode command | ✅ Done | `php artisan gis:geocode` — assigns privacy-safe barangay-level coords to seniors missing GPS data |
| Map coordinate picker in profile form | ❌ Removed | Built, then removed — the pre-filled lat/lng fields misled admins into thinking they were editing real households; `gis:geocode` is the sole coordinate source |
| Accessibility proximity scoring | ✅ Done | `php artisan gis:score-proximity` writes `senior_accessibility_metrics` (nearest health centre, hospital, pharmacy, market, barangay hall) |
| GIS CSV export | ✅ Done | Admin-only `/reports/gis/export` — senior lat/lng + nearest facility distances + accessibility score |
| Field GPS / geocoding documentation | ✅ Done | [gis-geocoding.md](gis-geocoding.md); the manual-pin workflow is covered in [GIS_FUNCTIONALITY_AND_MODIFIED_FILES.md](GIS_FUNCTIONALITY_AND_MODIFIED_FILES.md) |

### Completed (Sprint 3.3 — Routing & Real Facilities)

| Task | Status | Description |
|---|---|---|
| Road-network route distances | ✅ Done | `/api/gis/route-distance` + `php artisan gis:cache-route-distances` (OpenRouteService, cached in `senior_facility_route_distances`) |
| OpenStreetMap facility import | ✅ Done | `php artisan facilities:import-osm` — replaces approximate seeded facilities with real OSM coordinates |

### Remaining / future

- **`gis_proximity_score` as an ML feature** — accessibility scoring is computed and stored, but is not yet wired into the GBR/RFR risk pipeline; this requires a model retrain and remains a future enhancement.
- POI data for Pagsanjan should continue to be verified against on-the-ground reality (OSM data may be incomplete for rural barangays).

---

## 6. Phase 4 — Advanced Features

**Period:** June 2026 – July 2026
**Status:** 📋 Planned

| Feature | Description | Dependency |
|---|---|---|
| Longitudinal risk tracking | Dashboard showing risk score trends over time per senior and per barangay; uses `cluster_snapshots` table | Phase 2 cluster snapshots |
| ML model retraining pipeline | Web-triggered or scheduled retraining on accumulated data; updates `.pkl` artefacts and logs model version | Requires sufficient new data |
| Senior photo upload | Photo field on profile form; stored in `storage/app/public/seniors/` | None |
| Survey versioning UI | Manage multiple QoL instrument versions; display which version was used for each survey | None |
| Mobile-responsive field entry | Optimise QoL survey form and profile form for tablet/phone use by field workers | None |
| Multi-office support | Extend the system to serve multiple OSCA offices (multi-tenancy); separate data per municipality | Major architectural change |

---

## 7. Milestone Definitions

| Milestone | Criteria |
|---|---|
| **M1 — Core Complete** | All Phase 1 deliverables implemented and passing CI checks. ✅ Achieved April 2026. |
| **M2 — Pilot Ready** | RBAC implemented, audit logging active, default credentials changed, Data Privacy review complete. ✅ Achieved May 2026. |
| **M3 — GIS MVP** | Map view live with senior pins and POI overlay; basic proximity report available. ✅ Achieved May 2026 (prototype). |
| **M4 — GIS Full** | Bulk geocoding, accessibility proximity scoring, GIS CSV export, route distances, and OSM facility import complete. ✅ Achieved June 2026. (The manual coordinate picker was built then removed; wiring `gis_proximity_score` into the ML pipeline remains a future enhancement requiring a model retrain.) |
| **M5 — Production** | All Phase 2 and 3 complete; system deployed on a production server with HTTPS and automated backups. Target: June 2026. |
| **M6 — Advanced** | Longitudinal tracking, model retraining, and mobile UI complete. Target: July 2026. |

---

## 8. Feature Backlog

Items below are identified but not yet scheduled into a phase:

| Feature | Rationale | Effort |
|---|---|---|
| Email / notification system | Critical risk alerts, recommendation assignment notifications | Medium |
| SMS notifications via Twilio | Alert OSCA staff of new urgent-priority seniors; useful in low-bandwidth/no-email environments | Medium |
| Offline PWA mode | Allow field workers to complete surveys without internet; sync when back online | High |
| Automated data retention | Permanently delete records older than a configurable retention period per Data Privacy Act | Medium |
| Senior consent tracking | Record informed consent date and method per senior for RA 10173 compliance | Low |
| DSWD / PhilSys API integration | Verify senior identity and eligibility against national databases | High (external) |
| Benchmarking across OSCA offices | Compare risk distributions across multiple municipalities | Depends on M6 |
| Custom report builder | Allow staff to configure which fields appear in exports | Medium |
| Senior self-assessment portal | Public-facing survey form seniors or family members can fill in | High |
