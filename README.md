# AgeSense

AgeSense is an explainable machine learning framework and decision-support system for profiling senior citizens and generating healthy ageing recommendations using indicators aligned with the World Health Organization Healthy Ageing Framework.

This repository combines a Laravel 11 web application, Livewire-based survey workflows, SQLite/MySQL-backed data storage, and Python machine learning services for preprocessing, clustering, risk estimation, and recommendation generation. The project is designed for community-level use cases such as Offices for Senior Citizens Affairs (OSCA), local government units, and academic research on ageing populations.

## Table of Contents

1. [Project Overview](#project-overview)
2. [Purpose](#purpose)
3. [Objectives](#objectives)
4. [Key Features](#key-features)
5. [System Architecture](#system-architecture)
6. [Technology Stack](#technology-stack)
7. [Repository Structure](#repository-structure)
8. [Dataset Coverage](#dataset-coverage)
9. [Setup Guide](#setup-guide)
10. [Running the System](#running-the-system)
11. [Machine Learning Workflow](#machine-learning-workflow)
12. [Application Modules](#application-modules)
13. [Important Routes](#important-routes)
14. [Configuration Notes](#configuration-notes)
15. [Outputs and Reports](#outputs-and-reports)
16. [Fallback Behavior](#fallback-behavior)
17. [Target Beneficiaries](#target-beneficiaries)
18. [References](#references)

## Project Overview

Population ageing increases the need for data-driven, transparent, and practical tools that can help organizations understand the conditions of older adults beyond simple administrative records. AgeSense addresses this gap by organizing senior citizen profile data and quality-of-life survey responses into a system that can:

- capture multidimensional ageing indicators
- group senior citizens into meaningful profile clusters
- estimate quality-of-life and domain-level risk patterns
- generate interpretable recommendations for intervention planning

The study and system are grounded in the WHO Healthy Ageing Framework, which emphasizes the interaction of:

- intrinsic capacity
- environmental support
- functional ability

## Purpose

The main purpose of this project is to transform OSCA-style senior citizen records and survey responses into actionable insights for healthy ageing assessment. Instead of using records only for documentation, the system supports deeper analysis for planning, prioritization, and evidence-based service design.

Specifically, AgeSense helps answer questions such as:

- What are the demographic, socioeconomic, health, and environmental characteristics of senior citizens in the study area?
- What meaningful senior citizen profiles can be discovered using clustering techniques?
- Which factors most strongly influence quality of life and ageing-related vulnerability?
- What recommendations can be generated from interpretable model outputs?

## Objectives

### General Objective

To apply machine learning techniques to assess and profile senior citizens using indicators aligned with the WHO Healthy Ageing Framework in order to identify meaningful patterns and support targeted healthy ageing interventions.

### Specific Objectives

- Collect and manage senior citizen profile and quality-of-life data.
- Preprocess the dataset through cleaning, handling missing values, encoding, normalization, and feature transformation.
- Identify senior citizen profiles using clustering methods such as K-Means, Hierarchical Clustering, and Gaussian Mixture Models.
- Evaluate clustering quality using validation metrics such as Silhouette Score, Davies-Bouldin Index, and Calinski-Harabasz Index.
- Estimate quality-of-life and domain-level risks using machine learning models.
- Generate explainable outputs and prescriptive recommendations for program planning and case-level support.

## Key Features

- Senior citizen profiling and record management
- Multi-step survey collection for profile and quality-of-life indicators
- WHO-aligned domain scoring
- Python preprocessing and inference services
- Cluster assignment and risk stratification
- Explainable recommendation generation
- Dashboard and analytics reporting
- PDF and spreadsheet export support
- Fallback heuristic scoring when remote ML services are unavailable

## System Architecture

```text
Laravel 11 + Livewire UI
    |
    |-- Senior citizen profile management
    |-- QoL survey data collection
    |-- Dashboard, reports, and recommendations
    |
    v
App\Services\MlService
    |
    |-- Preprocess request -> Python service on port 5001
    |-- Inference request  -> Python service on port 5002
    |-- Local Python runner fallback
    |-- Heuristic fallback if Python services are unavailable
    |
    v
Database
    |
    |-- senior_citizens
    |-- qol_surveys
    |-- ml_results
    |-- recommendations
    |-- cluster_snapshots
```

## Technology Stack

| Layer | Technology |
|---|---|
| Web framework | Laravel 11 |
| Reactive components | Livewire 3, Volt |
| Frontend utilities | Alpine.js |
| Styling | Tailwind CSS 3 |
| Charts | Chart.js 4 |
| Backend language | PHP 8.2+ |
| ML services | Python 3.11+ |
| Python API | Flask, Flask-CORS |
| Machine learning | scikit-learn, UMAP, pandas, numpy, scipy |
| Export tools | `barryvdh/laravel-dompdf`, `maatwebsite/excel` |
| Auth/session/cache queue support | Laravel database drivers |
| Current local database option | SQLite |
| Supported configured database option | MySQL |

## Repository Structure

```text
osca-system/
|-- app/
|   |-- Http/
|   |-- Livewire/
|   |-- Models/
|   |-- Providers/
|   `-- Services/
|-- bootstrap/
|-- config/
|-- database/
|   |-- migrations/
|   |-- seeders/
|   `-- database.sqlite
|-- docs/
|-- public/
|-- python/
|   |-- models/
|   |-- services/
|   |-- requirements.txt
|   |-- start_services.ps1
|   `-- start_services.sh
|-- resources/
|   |-- css/
|   |-- js/
|   `-- views/
|-- routes/
|-- storage/
|-- tests/
|-- artisan
|-- composer.json
|-- package.json
`-- README.md
```

### Important Directories

- `app/Models` contains the core domain models such as senior citizens, QoL surveys, ML results, and recommendations.
- `app/Services` contains the PHP orchestration layer, including `MlService.php`.
- `app/Livewire` contains interactive survey, dashboard, and report components.
- `database/migrations` defines the application schema.
- `database/seeders` contains data import and seeding logic, including CSV-based population of records.
- `python/services` contains the preprocessing and inference services used by the Laravel app.
- `python/models` is the expected location for trained model artifacts when stored inside the project.
- `resources/views` contains Blade and Livewire UI templates.
- `routes/web.php` defines the main web routes for dashboards, surveys, ML actions, reports, and recommendations.

## Dataset Coverage

The project is based on two main data collection instruments:

### 1. Senior Citizen Profile Data

This includes:

- demographic information
- contact and barangay information
- family composition and social support
- education and skills
- community participation
- living arrangement and dependency profile
- economic status and assets
- health conditions and sensory concerns
- psychosocial concerns
- healthcare access and preventive behavior

### 2. Quality-of-Life Questionnaire

The quality-of-life instrument is organized into eight domains:

- overall quality of life
- physical health
- psychological and emotional well-being
- independence and autonomy
- social relationships and participation
- home and neighborhood environment
- financial situation
- spirituality and personal beliefs

These variables are later mapped into the WHO Healthy Ageing domains:

- Intrinsic Capacity
- Environment
- Functional Ability

## Setup Guide

## Prerequisites

Before setting up the project, make sure the following tools are installed:

- PHP 8.2 or later
- Composer
- Node.js and npm
- Python 3.11 or later
- Git

Optional depending on your database choice:

- SQLite
- MySQL 8+

## Clone the Repository

```bash
git clone <your-repository-url>
cd osca-system
```

If your downloaded folder contains another nested `osca-system` application directory, enter that folder before running Laravel commands:

```bash
cd osca-system
```

## Install PHP Dependencies

```bash
composer install
```

## Install Frontend Dependencies

```bash
npm install
```

## Create the Environment File

On Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

On Git Bash or Linux/macOS:

```bash
cp .env.example .env
```

Then generate the application key:

```bash
php artisan key:generate
```

## Configure the Database

This project currently works well with SQLite for local development.

### Option A: SQLite

1. Ensure `database/database.sqlite` exists.
2. Update `.env`:

```env
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
```

Recommended local values for session and cache:

```env
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=database
```

### Option B: MySQL

Update `.env` with your MySQL connection:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=osca_db
DB_USERNAME=root
DB_PASSWORD=
```

## Run Migrations

```bash
php artisan migrate
```

## Seed the Database

The default `DatabaseSeeder` calls `OscaCsvSeeder`, which imports data from the root-level `osca.csv` file and also triggers ML processing for imported records.

```bash
php artisan db:seed
```

If you want a simpler seed path for demo records, inspect or run other available seeders manually as needed.

## Install Python Dependencies

Create a Python virtual environment inside the `python` directory if needed:

### Windows PowerShell

```powershell
cd python
python -m venv venv
.\venv\Scripts\Activate.ps1
pip install -r requirements.txt
cd ..
```

### Git Bash or Linux/macOS

```bash
cd python
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
cd ..
```

## Build Frontend Assets

For development:

```bash
npm run dev
```

For production build:

```bash
npm run build
```

## Running the System

Start the Laravel app:

```bash
php artisan serve
```

By default, the app will be available at:

```text
http://127.0.0.1:8000
```

## Start the Python ML Services

### Windows PowerShell

```powershell
.\python\start_services.ps1
```

This script starts:

- preprocessing service on port `5001`
- inference service on port `5002`

### Linux/macOS or Git Bash

```bash
bash python/start_services.sh
```

### Run Services Individually

Preprocessing service:

```bash
python python/services/preprocess_service.py
```

Inference service:

```bash
python python/services/inference_service.py
```

## Model Files

The Python layer may use trained artifacts such as:

- `scaler.pkl`
- `umap_nd.pkl` or `umap_reducer.pkl`
- `kmeans.pkl` or `kmeans_k3.pkl`
- `cluster_mapping.json` or similar mapping files
- ensemble/regression model files for domain risk estimation

Depending on your environment, model artifacts may be loaded from:

- `python/models/`
- a configured `ML_MODELS_PATH`
- a stable application data directory used by the local service scripts

If trained models are unavailable, the application can still operate using local or heuristic fallback behavior.

## Machine Learning Workflow

The system follows this high-level workflow:

1. A senior citizen profile is encoded and stored.
2. A quality-of-life survey is submitted.
3. Laravel computes QoL-related scores and prepares the payload.
4. The preprocessing service transforms the raw profile into model-ready features.
5. The inference service performs clustering and risk estimation.
6. Results are stored in `ml_results`.
7. Generated recommendations are stored in `recommendations`.
8. Dashboards and reports display profile groups, risk summaries, and intervention guidance.

### Core Analytical Methods

- K-Means clustering as the primary clustering approach
- Hierarchical clustering and Gaussian Mixture Models as comparison methods in the study design
- dimensionality reduction through PCA and or UMAP
- supervised learning for quality-of-life or risk estimation
- explainable analysis using feature importance and interpretable profile summaries

### WHO-Aligned Analytical Domains

- Intrinsic Capacity
- Environment
- Functional Ability

## Application Modules

### Senior Citizen Records

- create, view, update, and archive senior citizen records
- maintain profile and barangay-level information
- support PDF or data export workflows

### Profile Survey

- collect demographic, socioeconomic, environmental, and health indicators
- organize records into structured sections for consistent preprocessing

### Quality-of-Life Survey

- collect Likert-scale responses across eight domains
- compute normalized domain and summary scores
- trigger the ML pipeline after submission

### Dashboard

- display total seniors, survey coverage, and risk summaries
- visualize cluster and barangay distributions
- surface pending recommendations and service alerts

### Reports

- cluster analysis summaries
- risk reports
- barangay-level reporting
- exportable outputs for administration and research use

### Recommendations

- list intervention suggestions per senior citizen or risk group
- track recommendation status and urgency

## Important Routes

The main web routes currently include:

- `/dashboard`
- `/seniors`
- `/surveys/profile/create/{senior?}`
- `/surveys/qol`
- `/ml/status`
- `/ml/batch`
- `/reports/cluster`
- `/reports/risk`
- `/recommendations`

Note that these routes are placed inside the `auth` middleware group, so authentication must be available before accessing them.

## Configuration Notes

Relevant `.env` settings include:

```env
APP_NAME="OSCA Senior Citizen System"
APP_TIMEZONE=Asia/Manila
APP_URL=http://localhost

DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite

PYTHON_SERVICE_URL=http://127.0.0.1
PYTHON_PREPROCESS_PORT=5001
PYTHON_INFERENCE_PORT=5002
PYTHON_TIMEOUT=120
PYTHON_COLD_START_TIMEOUT=120

ML_MODELS_PATH=storage/app/ml_models
MUNICIPALITY_NAME="Pagsanjan"
PROVINCE_NAME="Laguna"
```

Important note: the code reads Python service ports from `config/services.php`, so using `PYTHON_PREPROCESS_PORT` and `PYTHON_INFERENCE_PORT` is more accurate than relying on a single `PYTHON_SERVICE_URL` with port `5000`.

## Outputs and Reports

Expected outputs of the system include:

- clustered senior citizen profiles
- domain-level risk scores
- overall risk levels
- recommendation lists
- dashboard summaries
- exported reports for planning and documentation

These outputs support:

- community-level analysis
- service prioritization
- targeting of interventions
- academic research and validation

## Fallback Behavior

The application is designed to remain usable even when the Python HTTP services are unavailable.

Fallback sequence:

1. Try the HTTP preprocessing and inference services.
2. If unavailable, try the local Python runner.
3. If that also fails, use heuristic preprocessing and inference in PHP.

This means the system can still:

- save survey responses
- produce approximate risk outputs
- generate placeholder recommendations
- preserve continuity for testing and offline development

## Target Beneficiaries

The main beneficiaries of the project are:

- Offices for Senior Citizens Affairs
- local government units
- senior citizens and their families
- researchers and academic institutions
- public health and social welfare planners

## References

The conceptual and methodological basis of this project draws from the following themes and studies:

- World Health Organization Healthy Ageing Framework
- OSCA-related records management and senior citizen information systems
- clustering-based elderly profiling studies
- explainable machine learning studies in ageing and health research

Selected references from the concept paper:

- Andrade, S. C. V., Marcucci, R. M. B., Faria, L. F. C., Paschoal, S. M. P., Rebustini, F., and Melo, R. C. (2020). Health profile of older adults assisted by the Elderly Caregiver Program of the Health Care Network of the City of Sao Paulo.
- Bandeen-Roche, K. et al. (2006). Phenotype of frailty: Characterization in the women’s health and aging studies.
- Goh, C. H. et al. (2022). Development of an effective clustering algorithm for older fallers.
- Sarah, M. B., Lasekan, O., and Godoy, M. (2024). Identifying elderly health-risk profiles in Kerala using machine learning.
- World Health Organization. (2020). Decade of healthy ageing: Baseline report.
- World Health Organization. (2022). Ageing and health.

## Summary

AgeSense is both a research-driven and implementation-ready system. It combines survey-based community data, WHO-aligned healthy ageing domains, explainable analytics, and web-based records management into one integrated platform for senior citizen profiling and prescriptive recommendations.
