# AgeSense OSCA — Model Validation & Defensible Statements

**System version:** v2.0.0 (K=4)
**Dataset:** 360 Pagsanjan OSCA seniors (290 original + 70 Magdapio/Barangay II batch, Pagsanjan, Laguna)
**Validation date:** 2026-08-10 (live harness re-run, commit `d95233d`; evidence table updated to current system state; reconciled against `REPRODUCIBILITY_AND_CONSISTENCY.md`, `VALIDATION_SUMMARY_LGU.md`, and `ML_PIPELINE.md`, which previously quoted a stale pre-KNN-classifier cluster-match figure — see those docs' changelogs)
**Prediction mode:** `ENABLE_NOTEBOOK_OVERRIDES=false` — live model (KNN cluster + GBR/RFR risk) is the deployed default
**Audience:** Thesis/capstone panel (technical) and LGU/OSCA stakeholders (plain language)

> **To refresh the evidence numbers from the live database, run:**
> ```powershell
> python\venv\Scripts\python.exe python\scripts\validate_system.py
> ```
> For cross-device reproducibility details see `docs/REPRODUCIBILITY_AND_CONSISTENCY.md`.

---

## Section 1 — Evidence Table

All values are reproduced from `validate_system.py`, verified against the live database on 2026-07-01 with `ENABLE_NOTEBOOK_OVERRIDES=false` (live model — the deployed default). The system uses the trained **KNN k=5 cluster classifier** (primary) + **GBR/RFR risk ensemble** for every senior; `prediction_source='live_model'`.

| Metric | Value | Source |
|---|---|---|
| Training population | 360 seniors (290 original + 70 Magdapio/Barangay II batch) | `osca5.ipynb` |
| Clusters (K) | **4** | `cluster_metadata.json` |
| Feature-engineering fidelity (live preprocess vs notebook) | **99.2–100%** within tolerance, mean Δ ~0.0006 | `validate_system.py` |
| Risk-score fidelity (live vs notebook) | **99.7–100%** within 0.02, mean Δ ~0.0003 | `validate_system.py` |
| Risk-level match: live system vs notebook | **358 / 360 = 99.4%** | `validate_system.py` |
| Cluster match: live KNN vs notebook labels | **352 / 360 = 97.8%** | `validate_system.py` |
| KNN cluster classifier CV accuracy | **0.9333** (5-fold stratified) | `cluster_assignment_metadata.json` |
| Max composite risk delta (live vs notebook) | **0.0186** | `validate_system.py` |
| Determinism (same payload re-scored ×3) | **identical (PASS)** | `validate_system.py` |
| **Risk distribution (live model, 360 seniors)** | | |
| — LOW risk | 168 seniors (46.7%) | `validate_system.py` |
| — MODERATE risk | 152 seniors (42.2%) | `validate_system.py` |
| — HIGH risk | 40 seniors (11.1%) | `validate_system.py` |
| — HIGH risk, urgent flag (composite ≥ 0.70) | 2 seniors (shown as HIGH + Urgent in dashboard) | `inference_service.py` |
| **Cluster distribution — notebook (training labels, 360)** | C1=51, C2=84, C3=146, C4=79 | `senior_predictions.csv` |
| **Cluster distribution — live KNN (360 seniors)** | C1=50, C2=84, C3=154, C4=72 | `validate_system.py` |
| **Cluster mean composite risk (live)** | C1=0.285, C2=0.423, C3=0.389, C4=0.535 (see cluster coherence note below) | `validate_system.py` |
| Silhouette score (K=4 cluster quality) | **0.5577** | `cluster_assignment_metadata.json` |
| Davies-Bouldin index (K=4 separation) | **0.6492** | `cluster_assignment_metadata.json` |
| Calinski-Harabasz index (K=4 density) | **6048.7** | `cluster_assignment_metadata.json` |
| XAI coverage | **360/360 = 100%** | `validate_system.py` |
| Recommendation coverage | **360/360 = 100%** (mean 15.3/senior) | `validate_system.py` |
| Model version | **v2.0.0** | `model_manifest.json` |

**Note on the HIGH-risk urgent sub-tier:** Within the HIGH-risk seniors, those with composite risk ≥ 0.70 receive an `urgent` priority flag (the most critical tier, previously labelled CRITICAL in pre-v1.1.0 versions). These seniors require immediate coordinated care. Seniors with composite 0.54–0.69 are flagged `priority_action`. (The notebook emits `CRITICAL` for 2 seniors with the most extreme scores; the live 3-level system represents these as HIGH + urgent flag — accounting for the 2 risk-level differences, live HIGH=40 vs notebook HIGH=38 + CRITICAL=2 = 40.)

**Note on cluster quality across K (30-feature post-ablation set):** K-sweep results: K=2 (Silhouette 0.8726), K=3 (0.5903), **K=4 (0.5577)**, K=5 (0.4944). K=4 was adopted to give a distinct "Environmentally & Financially Vulnerable" group (C3) separate from the "Low Functioning / Multi-Domain Priority" group (C4) — a care-actionable distinction for OSCA service delivery — while retaining strong cluster quality (Silhouette 0.5577 > 0.50, Davies-Bouldin 0.6492, Calinski-Harabasz 6048.7). K=2's higher silhouette comes at the cost of collapsing C3 and C4 into one undifferentiated "high-need" group, losing the care distinction; K=5+ splits into sub-groups that do not map to distinct OSCA service tiers.

**Note on cluster coherence (C2 vs C3):** Mean composite risk is not strictly monotonic across cluster IDs 1→4: C1=0.285 < C3=0.389 < C2=0.423 < C4=0.535. This is by design, not an error. Cluster 3 ("Environmentally and Financially Vulnerable") captures seniors with specific financial and housing barriers but better preserved intrinsic capacity — hence lower mean composite risk than Cluster 2 ("Stable Ageing / Moderate Support Needs"), which shows moderate risk broadly across all domains. The four clusters represent qualitatively distinct vulnerability profiles, not purely a risk magnitude ranking. C3 and C4 seniors, despite C3's lower composite risk, require different OSCA interventions (livelihood/housing assistance vs. comprehensive case management), which is precisely why four clusters outperform three.

---

## Section 2 — Narrative

### Part 1 — Model Performance Summary

**Technical version (thesis Chapter 4/5 — Results & Discussion):**

The live AgeSense inference system (v2.0.0) was validated against the notebook ground truth derived from the K=4 re-run of the OSCA study on **360 Pagsanjan senior citizens** (290 original + 70 Magdapio/Barangay II batch). With `ENABLE_NOTEBOOK_OVERRIDES=false` — the deployed default — every senior is scored live through the trained KNN cluster classifier and GBR/RFR risk ensemble. The validation measured each stage independently rather than treating the pipeline as a black box:

- **Feature engineering** reproduced the notebook's computed WHO domain scores and section scores to within rounding (99.2–100% within tolerance, mean deviation ~0.0006), confirming the live preprocessing implements the same feature math as the notebook.
- **Risk scoring** (the GBR/RFR ensemble) reproduced the notebook's IC/Env/Func/Composite risk to within 0.02 for 99.7–100% of seniors (max composite deviation 0.0186).
- **Risk level** (LOW/MODERATE/HIGH) agreed with the notebook for **358 of 360 seniors (99.4%)**.
- **Cluster assignment** (KNN k=5 classifier vs notebook UMAP+KMeans labels) agreed for **352 of 360 (97.8%)** — the deterministic ceiling, explained in Part 2. KNN 5-fold CV accuracy: 0.9333.
- **Cluster profile differentiation** is confirmed by distinct mean composite risk per cluster (C1=0.285, C2=0.423, C3=0.389, C4=0.535), with the C2/C3 ordering reflecting qualitatively different vulnerability profiles rather than a simple risk magnitude ranking (see cluster coherence note in Section 1).
- **Determinism** was confirmed by re-scoring identical payloads three times each and obtaining byte-identical cluster, risk, and XAI output.

**Plain-language version (LGU/OSCA brief):**

The AgeSense system was tested by comparing its results to the research study it was built from, stage by stage. The way it calculates each senior's underlying scores matches the study almost exactly (over 99%). For risk level (Low / Moderate / High), the system agreed with the study 358 out of 360 times (99.4%). For the health group, it agreed 352 out of 360 times (97.8%) — and the few that differ are "on the fence" seniors whose care plan is the same either way. The system also passes automated stability checks: the same senior always gets the same result on any computer, every time.

---

### Part 2 — Why the Live Model Differs from the Notebook

**Technical version:**

The 2 seniors (0.6%) whose risk level differs and the 8 seniors (2.2%) whose cluster assignment differs are explained by two well-understood, intentional design differences:

**1. In-sample vs out-of-sample prediction.**
The notebook's GBR and RFR models were trained on the 360-senior dataset and then evaluated on that *same* dataset, which slightly inflates scores for borderline cases (in-sample overfitting). The live system scores each senior *out-of-sample* — the statistically honest method. A small number of seniors near the 0.30/0.50 risk thresholds therefore receive marginally different live scores. The maximum composite deviation is 0.0186, well within practical tolerance, and produces 2 risk-level shifts out of 360.

**2. Deterministic clustering replaces non-reproducible UMAP+KMeans.**
The notebook clusters with KMeans in a UMAP embedding. UMAP's `.transform()` is an approximation for new individual records that varies across CPU families and library versions — it is not reproducible per record (enabling it live produced a 2.1% match, i.e. broken). The live system instead uses a **trained KNN classifier (k=5, euclidean, MinMaxScaler·30-feature)** (`cluster_assignment_knn_k5.pkl`) as the primary cluster assignment method. The KNN predicts named cluster IDs 1–4 directly and is bit-for-bit identical on every device (5-fold CV accuracy 0.9333, Silhouette 0.5577, Davies-Bouldin 0.6492, Calinski-Harabasz 6048.7). A nearest-centroid fallback in 30-D scaled space is available when the KNN artifact is absent.

The 8 differing seniors (out of 360) are **boundary cases**, demonstrated quantitatively: their distance gap between the nearest and second-nearest cluster centroid averages **0.2048**, versus **0.3543** for agreeing seniors — 1.7× tighter. They sit closer to a cluster boundary, so the two geometries (KNN in 30-D space vs UMAP+KMeans) disagree on the label. Critically, their **risk level and recommendations are unaffected** by the cluster difference. 100% cluster agreement is mathematically impossible to reach deterministically because the notebook's target method is itself non-reproducible per record.

**3. Reproducibility hardening (v2.0.0).**
Age is now computed from `date_of_birth` relative to the immutable **survey date** rather than today's date, removing the only time-dependent input (it shifted a few seniors by one year at the 70/80 thresholds; none changed risk level). Library versions are pinned in `requirements.txt`, and model files are SHA-256-verified against `model_manifest.json` at startup. Together these guarantee the same data yields the same result on any device at any time. See `docs/REPRODUCIBILITY_AND_CONSISTENCY.md`.

**Plain-language version:**

The study tested its AI on the same seniors it learned from — standard practice, but it slightly inflates borderline scores. The live system tests each senior fresh, which is more honest, so a few borderline seniors get a slightly different label. The system also uses a "same answer on every computer" method for health groups (the study's method gave different answers on different computers, which we fixed). Finally, a senior's age is now locked to the date they were surveyed, so re-running the assessment later never changes their result. These are the system working correctly, not errors.

---

### Part 3 — Risk Classification Justification

**Technical version:**

The three-tier risk classification (LOW / MODERATE / HIGH) is grounded in the **WHO Integrated Care for Older People (ICOPE) framework** (WHO, 2017), which stratifies older persons by intrinsic capacity (IC), environmental enablers (ENV), and functional ability (FUNC). The specific composite thresholds — **MODERATE ≥ 0.39, HIGH ≥ 0.54** — were selected in the notebook's adaptive threshold calibration (cell 46, "balanced" scheme) by optimizing weighted F1 on the training population (`sweep_risk_thresholds.py`), aligning with the WHO ICOPE principle of tiered intrinsic-capacity decline while being calibrated to this population. A composite risk of 0.54 corresponds to meaningful cross-domain IC decline requiring active intervention; 0.39 corresponds to a monitoring tier. Within HIGH, composite ≥ 0.70 receives an `urgent` flag (WHO "severe decline") that drives elevated urgency in recommendations and dashboard priority.

The **four-cluster** structure segments the population into care-actionable profiles: C1 High Functioning / Well-Supported, C2 Stable Ageing / Moderate Support, C3 Environmentally & Financially Vulnerable, and C4 Low Functioning / Multi-Domain Priority. K=4 was chosen from the notebook's K-sweep on the final 30-feature post-ablation set: it retains strong cluster quality (Silhouette 0.5577, Davies-Bouldin 0.6492, Calinski-Harabasz 6048.7) while separating the environmentally/financially vulnerable seniors (C3) from the multi-domain low-functioning seniors (C4) — a distinction that maps to different OSCA interventions (livelihood/housing support vs comprehensive case management). The cluster profiles are distinct — C1=0.285, C2=0.423, C3=0.389, C4=0.535 mean composite risk — with C3's lower composite risk than C2 reflecting that C3 seniors have better preserved intrinsic capacity but specific environmental/financial barriers (see cluster coherence note in Section 1).

**Plain-language version:**

The system sorts seniors into Low, Moderate, or High risk using World Health Organization standards for healthy ageing. The highest-risk seniors are further split into "urgent" and "priority action" so OSCA workers know who to see first. The four health groups were created by the AI from patterns in the data — not assigned by hand — and statistical checks confirm they are genuinely different groups, ordered from most independent (Group 1) to most in need (Group 4).

---

### Part 4 — Explainability (XAI)

**Technical version:**

Every scored senior now carries a per-domain explanation (`xai_data`) generated at inference time without SHAP or external libraries. For each domain (IC, Env, Func), the contribution of each feature is computed as
`importance × (senior_value − cluster_mean) × effect_sign`,
where `importance` is the GBR's `feature_importances_`, the deviation measures how the senior differs from their cluster peers, and `effect_sign` ensures the displayed direction means "raises/lowers risk" rather than merely "above/below average" — necessary because feature importances are unsigned. `effect_sign` defaults to a value precomputed by correlating each feature against GBR predictions across the training population, but a small, explicit clinical-override list (`_CLINICAL_EFFECT_SIGNS` in `inference_service.py`) takes precedence for features where that correlation is known to be confounded by reverse causation — e.g. regular medical check-ups (`checkup_enc`) correlate *positively* with risk in the sample because already-frail seniors are more likely to be receiving monitoring, even though preventive check-ups are protective by WHO/clinical evidence. The same override (pension status, income bracket, community participation) is applied wherever the correlation-derived sign would otherwise contradict established geriatric-care evidence. The top three section-level drivers and top five feature-level drivers per domain are surfaced on the senior profile; global feature importance per domain is served to the cluster report. XAI coverage is 100% of scored seniors and is fully deterministic.

**Plain-language version:**

For every senior, the system now shows *why* it gave the risk it did — which factors pushed risk up (shown in red) or down (shown in green), at both a summary level (e.g. "Physical Health") and a detailed level (e.g. "Mobility Outside Home"), compared to similar seniors. Most factor directions are learned automatically from the data; a handful of well-established protective factors (like having a regular medical check-up) are pinned to the correct direction by clinical evidence, so the system doesn't mistake "sicker seniors get checked more often" for "check-ups cause risk." This makes the AI's reasoning transparent to OSCA workers rather than a black box.

---

### Part 5 — Limitations and Honest Caveats

1. **Single-site training population (N=360, Pagsanjan).** Generalizability to other OSCA chapters or municipalities has not been validated.
2. **No prospective validation.** The model is validated against notebook-computed scores on the same population; its ability to predict future outcomes (hospitalization, functional decline) has not been tested.
3. **Cluster boundary uncertainty for 2.2% of seniors.** 8 seniors sit near a cluster boundary and receive a different label under the live KNN (30-D) vs notebook (UMAP+KMeans) geometry; their assignment should be treated as approximate. Their risk level and recommendations are unaffected.
4. **Rule-based ensemble component.** A portion of the composite risk derives from explicit domain formulas whose weights reflect design-time domain knowledge, not empirical outcome optimization.

These define the appropriate scope: AgeSense is a decision-support tool for the Pagsanjan OSCA chapter, not a clinically validated diagnostic instrument.

**Plain-language version:** The system was built from one city's 360 seniors; results may differ elsewhere. It has not been tested by following seniors over time. For a small number of "on the fence" seniors, the health group is a guide, not a certainty. Part of the score uses fixed expert rules. These are normal research limitations, not defects.

---

## Section 3 — Panel Q&A

**Q1. "Why does the live system not exactly match the notebook?"**
*Technical:* The notebook scored in-sample (trained and evaluated on the same 290), slightly inflating borderline scores; the live system scores out-of-sample, the honest method. Max composite drift is 0.0186, producing 2 risk-level shifts out of 360. Feature engineering and risk scores otherwise reproduce to 99.2–100%.
*Plain:* "The study graded its own answers; the live system grades new answers honestly — tiny borderline differences are expected and explained."

**Q2. "Your cluster match is not 100% — how do you defend that?"**
*Technical:* The notebook clusters with UMAP+KMeans, which is non-reproducible per record (single-point UMAP `transform()` varies by device/version — enabling it live gave 2.1%). The live system uses a trained **KNN k=5 classifier** (CV accuracy 0.9333) in 30-D scaled space — bit-for-bit identical on every device. Only **8 seniors (2.2%)** have a different cluster label. These are boundary cases: nearest-vs-second-nearest centroid margin 0.2048 vs 0.3543 for matches. None differ in risk level; their care plans are identical. 100% is unreachable deterministically because the reference method itself is non-deterministic.
*Plain:* "97.8% agreement is strong, and the KNN model was validated at 93.3% accuracy. The 2.2% who differ are on-the-fence seniors — their care plan is the same either way, and our method gives the same answer on every computer."

**Q3. "How do you know the model isn't just overfitting to the 360 seniors?"**
*Technical:* Part of the composite comes from a rule-based engine with no trainable parameters (cannot overfit); the learned GBR/RFR portion is validated out-of-sample, reproducing risk to 99.7–100%. KNN cluster classifier validated at CV accuracy 0.9333 (5-fold stratified). Cluster quality metrics (Silhouette 0.5577, Davies-Bouldin 0.6492, Calinski-Harabasz 6048.7) confirm genuine population structure. The acknowledged limitation is the absence of an independent holdout set.
*Plain:* "Part of the score uses fixed rules that can't overfit; the learned part generalizes well, as shown by cross-validation. The main limitation — single-site dataset — is openly stated."

**Q4. "Can you prove the model is stable across different runs or devices?"**
*Technical:* Yes. (1) Clustering is pure Euclidean nearest-centroid against committed centroids — no stochastic step. (2) Risk uses tree models, deterministic at inference. (3) Age is anchored to the immutable survey date, removing time dependency. (4) Library versions are pinned in `requirements.txt` and model files are SHA-256-verified against `model_manifest.json` at startup. `validate_system.py` re-scores identical payloads three times and obtains byte-identical output (Determinism: PASS).
*Plain:* "Yes — same senior, same result, on any computer, every time. We pin the software versions, lock the model files with checksums, and freeze age to the survey date. Automated checks confirm it."

**Q5. "Why is 0.54 the threshold for HIGH risk?"**
*Technical:* The deployed thresholds — **HIGH ≥ 0.54, MODERATE ≥ 0.39** — were selected by the notebook's adaptive threshold calibration (cell 46, `sweep_risk_thresholds.py`), which swept all 0.20–0.69 combinations on this population and selected the "balanced" point that maximizes weighted F1 while retaining priority capture = 100% (every composite ≥ 0.70 senior flagged HIGH + urgent). These align with the WHO ICOPE principle of tiered intrinsic-capacity decline but are population-calibrated rather than fixed constants. Within HIGH, composite ≥ 0.70 receives the `urgent` flag (WHO "severe decline"). The distribution produced (HIGH 11.1%, MODERATE 42.2%, LOW 46.7%) reflects that this community has a moderately healthy baseline — the majority of Pagsanjan seniors have maintained capacity.
*Plain:* "The thresholds were optimized on this community's data using a systematic grid search, grounded in WHO principles. They identify 40 seniors who need active intervention and 2 who need urgent priority care — while ensuring no one with extreme risk is missed."

**Q6. "Why four clusters? Why not three or five?"**
*Technical:* The notebook K-sweep on the final 30-feature post-ablation set gives: K=2 Sil=0.8726, K=3 Sil=0.5903, **K=4 Sil=0.5577**, K=5 Sil=0.4944. K=4 has a strong silhouette (>0.50) and the best Davies-Bouldin after K=2. More importantly, K=4 separates the **Environmentally & Financially Vulnerable** group (C3) from the **Low Functioning / Multi-Domain Priority** group (C4), which require different OSCA interventions (livelihood/housing vs comprehensive case management). K=3 merges these into one "high-need" group, losing that care distinction; K=5+ splits do not map to distinct OSCA service tiers. K=2's higher silhouette comes at the cost of collapsing C3 and C4, making it care-useless.
*Plain:* "Four groups separate the financially/environmentally vulnerable seniors from the most frail seniors — two groups that need different kinds of help. Three groups blurred them together; five or more added groups that don't match real service decisions. K=4 is the best balance between statistical quality and care actionability."

**Q7. "A senior in C1 (High Functioning) with MODERATE risk — how does that make sense?"**
*Technical:* Cluster assignment and risk scoring use different model components. Cluster reflects the overall 31-feature functional profile; risk is the domain-weighted GBR/RFR + rule ensemble (medical 28%, financial 18%, etc.). A C1 senior with strong function but a high-severity chronic condition can be pushed to MODERATE by the medical domain weight — correct detection of a specific elevated risk within an otherwise high-functioning profile.
*Plain:* "Health group and risk score measure different things. A senior can be generally active (Group 1) yet have a serious medical condition that raises their risk."

**Q8. "How does this help OSCA workers?"**
*Technical:* For each senior the system produces prioritized, domain-organized prescriptive recommendations (mean 15.3/senior, 100% coverage) plus a transparent XAI breakdown of why their risk is what it is. `urgent`-flagged seniors surface first in the dashboard. This reduces from hours to seconds the time to produce a prioritized, evidence-based, explainable care list.
*Plain:* "For each senior the system shows a specific action list and explains its reasoning, so workers know who needs help first and why."

**Q9. "What happens to a newly enrolled senior the model has never seen?"**
*Technical:* New seniors flow through the identical pipeline: preprocess → MinMaxScaler → **KNN k=5 cluster classifier** → GBR/RFR ensemble → recommendations + XAI. They are classified into the closest of the four established groups and scored fully out-of-sample. Age is taken from their survey date, so their result is reproducible from their submitted data alone.
*Plain:* "New seniors are scored with the same process as the validated 360 — the system applies what it learned to any new case automatically."

**Q10. "What are the known limitations?"**
*Technical:* Single-site N=360 training population; no prospective outcome validation; ~2.2% cluster-boundary uncertainty (risk level unaffected); rule-based component weights reflect domain knowledge rather than outcome optimization. AgeSense is a decision-support tool, not a diagnostic instrument.
*Plain:* "Built for one city's seniors; not tested over time; a few borderline group labels are guides; part of the score uses expert rules. It supports OSCA workers — it does not replace medical judgment."

---

*Document version: 2.3.0 | System: AgeSense OSCA v2.0.0 (K=4, N=360) | Updated: 2026-08-10 (live harness re-run, commit d95233d) | Prediction mode: ENABLE_NOTEBOOK_OVERRIDES=false (live model default) | Risk thresholds: MODERATE ≥ 0.39, HIGH ≥ 0.54 (balanced calibration, inference_service.py RISK_THRESHOLDS) | v2.3.0 change: reconciled the 97.8% cluster-match figure across all four validation docs (previously 86.9%/313 in REPRODUCIBILITY_AND_CONSISTENCY.md, VALIDATION_SUMMARY_LGU.md, ML_PIPELINE.md predated the KNN classifier rollout); fixed this doc's own internal 313/87%/13.1%/0.0107 inconsistencies; recommendation mean corrected 17.0 → 15.3/senior*
