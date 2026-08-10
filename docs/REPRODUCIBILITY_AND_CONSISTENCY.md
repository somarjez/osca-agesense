# AgeSense OSCA — Reproducibility & Cross-Device Consistency

**System version:** v2.0.0 (K=4)
**Last validated:** 2026-08-10 (360 seniors — 290 original + 70 Magdapio/Barangay II batch, commit `d95233d`)
**Audience:** developers, deployers, thesis panel

> **v1.3.0 correction (2026-08-10):** the 86.9% (313/360) cluster-match figure below was
> measured before the trained KNN k=5 classifier (`cluster_assignment_knn_k5.pkl`) replaced
> nearest-centroid as the primary live cluster-assignment method. With the KNN classifier active
> — the current deployed default — the figure is **97.8% (352/360)**. See Section 5. This doc's
> other guarantees (deterministic algorithms, frozen age, version lock) are unaffected and remain
> accurate. Kept for historical record; do not quote the 86.9% figure going forward — use
> `docs/model-validation-defensible-statements.md`, which already reflected the correct number.

> **Goal of this document:** explain *why* the live AgeSense system produces the
> **same result for the same data on any device, at any time**, and *how* to set
> up a second device so its results are provably identical.

---

## 1. The three guarantees of result stability

A senior's result (cluster, risk score, risk level, XAI, recommendations) is a
pure function of their input data. Three independent properties make this true:

| Guarantee | What it removes | Mechanism |
|---|---|---|
| **1. Deterministic algorithms** | randomness | Cluster = KNN k=5 classifier (primary) → nearest-centroid fallback (30-D scaled space); risk = Gradient Boosting + Random Forest tree models. No stochastic step at inference. |
| **2. Frozen age** | *time* dependency | Age is computed from `date_of_birth` relative to the **survey date**, never "today". |
| **3. Version lock** | *environment* dependency | Pinned library versions (`requirements.txt`) + SHA-256 verification of model files at startup. |

Together they mean: **same input data → same output**, regardless of which
computer runs it, or when.

---

## 2. Frozen age (v2.0.0)

**Before:** age was recomputed every run as `date_of_birth → today`. Re-running
the same assessment months later could change a senior's risk if they crossed
an age threshold (70 or 80) in the meantime — even though their data never changed.

**Now:** `MlService::ageAtSurvey()` computes age as `date_of_birth → survey_date`.
The survey date is immutable input data, so:

- A given assessment permanently reflects the senior's age **on the day they were surveyed**.
- It never drifts as the calendar advances.
- A **new** survey next year automatically reflects the new (older) age, because that survey carries a later survey date.

Age is *not* one of the 31 clustering features — it only affects risk via the
`sec1_age_risk` bucket (thresholds at 70/80, section weight 0.05). The change
shifted a few seniors by one year at a threshold, and **none changed risk level**.

The same logic is mirrored in the offline tools (`generate_xai_means.py`,
`validate_system.py`) so the cluster-mean baseline and validation harness match
the live path exactly.

---

## 3. Version lock & artifact integrity

**Pinned dependencies** — `python/requirements.txt` pins every ML-critical
library to an exact version:

```
scikit-learn==1.6.1   numpy==2.4.4    pandas==3.0.2
umap-learn==0.5.12    numba==0.65.0   scipy==1.17.1   joblib==1.5.3
```

Two devices that both run `pip install -r requirements.txt` get identical
numerical behaviour from the models.

**SHA-256 manifest check** — `python/models/model_manifest.json` stores the
SHA-256 hash of every `.pkl`. On startup, `inference_service._validate_artifacts_at_startup()`
recomputes each hash and compares. If a device's model files differ from the
training device, the log warns:

```
<file>.pkl SHA-256 mismatch: ... models on this device differ from the
training device. Copy python/models/ from the training machine ...
```

As of 2026-06-29 all 15 `.pkl` files match the manifest (model version 2.0.0, retrained on 360-senior dataset).

---

## 4. Why clustering is ~98% vs the notebook — and why that is correct

The notebook clusters with **UMAP (10-dim) → KMeans**. UMAP's `transform()` on a
*single new record* is an approximation that varies across CPU families and
library versions — it is **not reproducible per-record**. Enabling it in the live
system produced a 2.1% match (broken), not a better one.

The live system therefore uses a **trained KNN classifier (k=5, euclidean, MinMaxScaler·30-feature)**
(`cluster_assignment_knn_k5.pkl`) as the primary cluster assignment method — bit-for-bit identical
on every device (CV accuracy 0.9333, Silhouette 0.5577, Davies-Bouldin 0.6492).
A nearest-centroid fallback (`cluster_centroids_scaled.json`, 30-D scaled space) is available
when the KNN artifact is absent. UMAP and KMeans are **not called** at inference time.

The KNN's agreement with the notebook UMAP+KMeans labels is **97.8%** (352/360 seniors). The 2.2%
(8 seniors) that differ are **boundary-ambiguous seniors** — proven, not assumed: their distance
gap between the nearest and second-nearest cluster averages **0.2048**, versus **0.3543** for
agreeing seniors (1.7× tighter). For these borderline seniors, the **risk score
and recommendations are identical** regardless of which cluster label they get.

*(An earlier measurement of this figure — 86.9%, 313/360 — was taken before the KNN classifier
became the primary live method, when nearest-centroid alone was active; see the v1.3.0 correction
banner at the top of this document.)*

100% cluster agreement is mathematically impossible to reach deterministically,
because the target method (UMAP+KMeans) is itself non-reproducible per record.

---

## 5. Validation results (2026-08-10, `validate_system.py`, 360 seniors, commit `d95233d`)

Run with `ENABLE_NOTEBOOK_OVERRIDES=false` (live model only). Dataset expanded from 290 to 360 seniors (290 original + 70 Magdapio/Barangay II batch); model retrained June 2026.

| Category | Result | Verdict |
|---|---|---|
| Feature-engineering fidelity (WHO + section scores) | 99.2–100% within tolerance, mean Δ ~0.0006 | PASS |
| Risk-score fidelity (IC/Env/Func/Composite) | 99.7–100% within 0.02, mean Δ ~0.0006 | PASS |
| Risk-level match (LOW/MODERATE/HIGH) | 358/360 = **99.4%** | PASS |
| Cluster match vs notebook | 352/360 = **97.8%** | deterministic ceiling |
| Cluster coherence (risk rises with cluster id) | 0.285 → 0.423 → 0.389 → 0.535 | **not monotonic — by design**, see `model-validation-defensible-statements.md` §1 "cluster coherence note": C3 (Environmentally & Financially Vulnerable) has lower mean risk than C2 (Stable Ageing) despite the higher cluster ID, because the two clusters capture qualitatively different vulnerability profiles, not a strict risk ranking |
| XAI coverage | 360/360 = 100% | PASS |
| Recommendation coverage | 360/360 = 100% (mean 15.3/senior) | PASS |
| Determinism (same payload × 3 runs) | identical every time | PASS |

Re-run any time to re-confirm the whole system in one command:

```powershell
python\venv\Scripts\python.exe python\scripts\validate_system.py
```

---

## 6. Setting up another device for IDENTICAL results

Follow these steps so a second computer (panelist, co-developer, deployment
server) produces results bit-for-bit identical to the source device.

**Step 1 — Same code + same model files**
```powershell
git clone https://github.com/somarjez/osca-agesense.git
cd osca-agesense
git checkout <same-commit-or-tag-as-source>
```
Model artifacts (`python/models/*.pkl` and `*.json`) are committed to the repo,
so the clone already contains the exact K=4 models. Do **not** regenerate them.

**Step 2 — Same Python libraries (version lock)**
```powershell
python -m venv python\venv
python\venv\Scripts\pip.exe install -r python\requirements.txt
```
This installs the exact pinned versions. Different versions can change the last
digits of float math; pinning prevents that.

**Step 3 — Same database data**
The result depends on the senior input data. Either:
- Share the same MySQL database, **or**
- Import the same dataset — see `docs/DATABASE_SHARING_AND_TEAM_SETUP.md`.

**Step 4 — Same configuration (`.env`)**
```
ENABLE_NOTEBOOK_OVERRIDES=false
ENABLE_DETERMINISTIC_CLUSTER=true
```
These two settings keep the device on the deterministic live-model path.

**Step 5 — Start the services and confirm integrity**
```powershell
python\venv\Scripts\python.exe python\services\preprocess_service.py   # port 5001
python\venv\Scripts\python.exe python\services\inference_service.py     # port 5002
```
On startup the inference service prints `Artifact validation PASSED` if the model
files match the manifest. If you see a `SHA-256 mismatch` warning, the model files
differ — re-copy `python/models/` from the source device.

**Step 6 — Prove it**
```powershell
python\venv\Scripts\python.exe python\scripts\validate_system.py
```
Determinism should report **PASS**, and the fidelity/risk/cluster numbers should
match Section 5 above.

**What makes results differ (and is therefore expected):**
- Different senior input data, an edited survey, or a new survey (new survey date → updated age).
- Deliberately retrained models (new `.pkl` files) — the SHA-256 check will flag this.
- Different library versions — prevented by Step 2, detected by the startup check.

---

## 7. Related documents
- `docs/model-validation-defensible-statements.md` — full thesis/LGU validation narrative & Q&A
- `docs/ML_PIPELINE.md` — pipeline architecture
- `docs/DATABASE_SHARING_AND_TEAM_SETUP.md` — sharing data across devices
- `docs/UPDATING_THE_MODEL.md` — what to do when the model is retrained

*Document version 1.3.0 | System: AgeSense OSCA v2.0.0 (K=4, N=360) | 2026-08-10 | v1.3.0 change: corrected cluster-match figure 86.9%→97.8% (see banner), fixed the non-monotonic cluster-coherence row, recommendation mean 16.9→15.3/senior*
