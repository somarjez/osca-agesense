# Project Roadmap — AgeSense

> **System:** AgeSense — OSCA Senior Citizen Profiling and Analytics System
> **Last Updated:** 2026-05-03
> **Status:** Core system functionally complete. GIS module and production-hardening in planning.

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
| Phase 2 | Production Hardening | May 2026 – Jun 2026 | 🔄 In Progress |
| Phase 3 | GIS Module | Jun 2026 – Aug 2026 | 📋 Planned |
| Phase 4 | Advanced Features | Sep 2026 – Dec 2026 | 📋 Planned |

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
K-Means clustering (K=3) + UMAP        ░░░░ ████ ████ ░░░░
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

PHASE 2 — PRODUCTION HARDENING
───────────────────────────────
Role-based access control (RBAC)        ░░░░ ░░░░ ░░░░ ░░░░ ████ ████
Activity audit logging                  ░░░░ ░░░░ ░░░░ ░░░░ ████ ░░░░
User management UI                      ░░░░ ░░░░ ░░░░ ░░░░ ████ ░░░░
Queued batch ML inference               ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████
Email / notification system             ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████
Dynamic cluster evaluation metrics     ░░░░ ░░░░ ░░░░ ░░░░ ████ ░░░░
Data Privacy Act compliance review      ░░░░ ░░░░ ░░░░ ░░░░ ████ ████
Barangay report page (complete stub)    ░░░░ ░░░░ ░░░░ ░░░░ ████ ░░░░
Excel export (maatwebsite/excel)        ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████
Cluster snapshot generation             ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████
Linux/macOS ML service startup script  ░░░░ ░░░░ ░░░░ ░░░░ ████ ░░░░

PHASE 3 — GIS MODULE
─────────────────────
GIS field migration (lat/lng/address)   ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████
Points of Interest table + seeder       ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████
Bulk geocode job (barangay centroids)   ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████
Map view — senior pins + POI overlay    ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████ ░░░░
Risk heatmap overlay                    ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████ ░░░░
Proximity scoring in ML pipeline        ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████ ████
Map coordinate picker in profile form   ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████
GIS report page (/reports/gis)          ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████
GIS CSV export (lat/lng + distances)    ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████
Field GPS data collection workflow      ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████

PHASE 4 — ADVANCED FEATURES
─────────────────────────────
Longitudinal risk tracking dashboard   ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████ ░░░░
ML model retraining pipeline            ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████ ████
Senior photo upload                     ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████
Survey versioning UI                    ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████
Mobile-responsive field entry UI        ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████ ████
OSCA network multi-office support       ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ░░░░ ████ ████

Legend:  ████ = Active development   ░░░░ = Not started / completed
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
| K-Means clustering (K=3) | ✅ Done | UMAP 10-D input, cluster_metadata.json override |
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

## 4. Phase 2 — Production Hardening (In Progress)

**Period:** May 2026 – June 2026
**Status:** 🔄 In Progress

This phase addresses the security, reliability, and operational gaps identified before pilot deployment.

| Deliverable | Priority | Status | Description |
|---|---|---|---|
| Role-based access control (RBAC) | High | ⏳ Pending | Use `spatie/laravel-permission`. Roles: `admin`, `encoder`, `viewer` |
| Activity audit logging | High | ⏳ Pending | Eloquent observers on Senior, Survey, Recommendation models |
| User management UI | High | ⏳ Pending | Create/edit/deactivate user accounts within the web app |
| Dynamic cluster evaluation metrics | Medium | ⏳ Pending | Read metrics from JSON file alongside model artefacts |
| Data Privacy Act compliance review | High | ⏳ Pending | Field-level encryption for PII, consent field, retention policy |
| Barangay report page | Medium | ⏳ Pending | Complete the `reports.barangay` route with view and controller |
| Queued batch ML inference | Medium | ⏳ Pending | `ProcessMlBatch` job dispatched via Laravel queue |
| Email / notification system | Medium | ⏳ Pending | Critical risk alerts, recommendation assignment notifications |
| Excel export | Low | ⏳ Pending | Full registry export via `maatwebsite/excel` |
| Cluster snapshot generation | Low | ⏳ Pending | Scheduled artisan command for daily snapshots |
| Linux/macOS ML service startup | Low | ⏳ Pending | `start_services.sh` equivalent to the PS1 script |

---

## 5. Phase 3 — GIS Module

**Period:** June 2026 – August 2026
**Status:** 📋 Planned

The GIS module adds geographic visualisation of senior citizen locations and proximity analysis to essential services. See SYSTEM_FUNCTIONALITY.md §18 for the full technical specification.

### Sprint breakdown

**Sprint 3.1 — Data Foundation (June 2026)**

| Task | Description |
|---|---|
| Database migration | Add `latitude`, `longitude`, `address_line`, `location_source`, `location_verified_at` to `senior_citizens` |
| `points_of_interest` table | Create table and migration |
| Pagsanjan POI seed | Seed health centres, hospitals, pharmacies, markets, barangay halls for all 16 barangays using OpenStreetMap data |
| Bulk geocode command | `php artisan gis:geocode` — assign barangay centroid coordinates to all seniors missing GPS data |

**Sprint 3.2 — Map View (July 2026)**

| Task | Description |
|---|---|
| Leaflet.js integration | Add Leaflet.js and leaflet.markercluster to the frontend build |
| Senior location map (`/gis/map`) | Risk-coloured pins, popup cards, barangay/risk/group filters |
| POI overlay | Layer toggle for each POI category |
| Risk heatmap layer | Density heatmap for HIGH and urgent-priority seniors |
| Map coordinate picker | Embed small Leaflet map in the senior profile edit form for manual pin placement |

**Sprint 3.3 — Proximity Scoring + Reports (August 2026)**

| Task | Description |
|---|---|
| Haversine proximity calculator | PHP service method — nearest POI of each category per senior |
| GIS proximity score feature | `gis_proximity_score` integrated as optional feature in preprocessing pipeline |
| GIS report page (`/reports/gis`) | Choropleth by barangay, ranked table, proximity gap indicators |
| GIS CSV export | Senior lat/lng + nearest facility distances |
| Field GPS workflow documentation | Guide for OSCA staff to capture GPS coordinates in the field |

### Key dependencies

- Senior coordinate data must be collected or geocoded before the map is meaningful.
- POI data for Pagsanjan must be verified against current on-the-ground reality (OpenStreetMap data may be incomplete for rural barangays).
- Proximity scoring integration into the ML pipeline requires retraining the GBR/RFR models with the new `gis_proximity_score` feature.

---

## 6. Phase 4 — Advanced Features

**Period:** September 2026 – December 2026
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
| **M2 — Pilot Ready** | RBAC implemented, audit logging active, default credentials changed, Data Privacy review complete. Target: June 2026. |
| **M3 — GIS MVP** | Map view live with senior pins and POI overlay; basic proximity report available. Target: July 2026. |
| **M4 — GIS Full** | Proximity scoring wired into ML pipeline; GIS report page and CSV export complete. Target: August 2026. |
| **M5 — Production** | All Phase 2 and 3 complete; system deployed on a production server with HTTPS and automated backups. Target: September 2026. |
| **M6 — Advanced** | Longitudinal tracking, model retraining, and mobile UI complete. Target: December 2026. |

---

## 8. Feature Backlog

Items below are identified but not yet scheduled into a phase:

| Feature | Rationale | Effort |
|---|---|---|
| SMS notifications via Twilio | Alert OSCA staff of new urgent-priority seniors; useful in low-bandwidth/no-email environments | Medium |
| Offline PWA mode | Allow field workers to complete surveys without internet; sync when back online | High |
| Automated data retention | Permanently delete records older than a configurable retention period per Data Privacy Act | Medium |
| Senior consent tracking | Record informed consent date and method per senior for RA 10173 compliance | Low |
| DSWD / PhilSys API integration | Verify senior identity and eligibility against national databases | High (external) |
| Benchmarking across OSCA offices | Compare risk distributions across multiple municipalities | Depends on M6 |
| Custom report builder | Allow staff to configure which fields appear in exports | Medium |
| Senior self-assessment portal | Public-facing survey form seniors or family members can fill in | High |
