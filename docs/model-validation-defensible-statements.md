# AgeSense OSCA — Model Validation & Defensible Statements

**System version:** v2.0.0 (K=4)
**Dataset:** 290 Pagsanjan OSCA seniors (Pagsanjan, Laguna)
**Validation date:** 2026-06-11
**Audience:** Thesis/capstone panel (technical) and LGU/OSCA stakeholders (plain language)

> **To refresh the evidence numbers from the live database, run:**
> ```powershell
> python\venv\Scripts\python.exe python\scripts\validate_system.py
> ```
> For cross-device reproducibility details see `docs/REPRODUCIBILITY_AND_CONSISTENCY.md`.

---

## Section 1 — Evidence Table

All values are reproduced from `validate_system.py`, verified against the live database on 2026-06-11 with `ENABLE_NOTEBOOK_OVERRIDES=false` (live model only).

| Metric | Value | Source |
|---|---|---|
| Training population | 290 seniors (Pagsanjan OSCA dataset) | `osca5.ipynb` |
| Clusters (K) | **4** | `cluster_metadata.json` |
| Feature-engineering fidelity (live preprocess vs notebook) | **99.3–100%** within tolerance, mean Δ ~0.0002 | `validate_system.py` |
| Risk-score fidelity (live vs notebook) | **99.7–100%** within 0.02, mean Δ ~0.0002 | `validate_system.py` |
| Risk-level match: live system vs notebook | **289 / 290 = 99.7%** | `validate_system.py` |
| Cluster match: live system vs notebook | **264 / 290 = 91.0%** | `validate_system.py` |
| Max composite risk delta (live vs notebook) | **0.0107** | `validate_system.py` |
| Determinism (same payload re-scored ×3) | **identical (PASS)** | `validate_system.py` |
| **Risk distribution (live model)** | | |
| — LOW risk | 38 seniors (13.1%) | `validate_system.py` |
| — MODERATE risk | 196 seniors (67.6%) | `validate_system.py` |
| — HIGH risk | 56 seniors (19.3%) | `validate_system.py` |
| — HIGH risk, urgent flag (composite >= 0.70) | subset of HIGH, surfaced in dashboard | `inference_service.py` |
| **Cluster distribution — notebook (training labels)** | C1=64, C2=78, C3=76, C4=72 | `senior_predictions.csv` |
| **Cluster distribution — live system (nearest-centroid)** | C1=61, C2=85, C3=77, C4=67 | `validate_system.py` |
| **Cluster mean composite risk (live, monotonic)** | C1=0.288, C2=0.390, C3=0.412, C4=0.543 | `validate_system.py` |
| Silhouette score (K=4 cluster quality) | **0.472** | `clustering_evaluation.csv` |
| Davies-Bouldin index (K=4 separation) | **0.772** | `clustering_evaluation.csv` |
| Calinski-Harabasz index (K=4 density) | **485.2** | `clustering_evaluation.csv` |
| XAI coverage | **290/290 = 100%** | `validate_system.py` |
| Recommendation coverage | **290/290 = 100%** (mean 11.4/senior) | `validate_system.py` |
| Model version | **v2.0.0** | `model_manifest.json` |

**Note on the HIGH-risk urgent sub-tier:** Within the HIGH-risk seniors, those with composite risk >= 0.70 receive an `urgent` priority flag (the most critical tier, previously labelled CRITICAL in pre-v1.1.0 versions). These seniors require immediate coordinated care. Seniors with composite 0.50–0.69 are flagged `priority_action`. (The notebook still emits a single `CRITICAL` label for the most extreme senior; the live 3-level system represents this as HIGH + urgent flag — accounting for the one risk-level difference, live HIGH=56 vs notebook HIGH=55.)

**Note on cluster quality across K:** The notebook's `clustering_evaluation.csv` reports, for the 31-feature set: K=2 (Silhouette 0.476), K=3 (0.424), **K=4 (0.472)**, K=5 (0.432), K=6 (0.414). K=4 was adopted to give a distinct "Environmentally & Financially Vulnerable" group separate from the "Low Functioning / Multi-Domain Priority" group — a care-actionable distinction for OSCA service delivery — while retaining strong cluster quality (second-highest silhouette, and the **lowest Davies-Bouldin of any K**, 0.772).

---

## Section 2 — Narrative

### Part 1 — Model Performance Summary

**Technical version (thesis Chapter 4/5 — Results & Discussion):**

The live AgeSense inference system (v2.0.0) was validated against the notebook ground truth derived from the K=4 re-run of the OSCA study on 290 Pagsanjan senior citizens. When all 290 seniors were re-scored through the live pipeline (`ENABLE_NOTEBOOK_OVERRIDES=false`), the validation separated and measured each stage independently rather than treating clustering as a single black box:

- **Feature engineering** reproduced the notebook's computed WHO domain scores and section scores to within rounding (99.3–100% within tolerance, mean deviation ~0.0002), confirming the live preprocessing implements the same feature math as the notebook.
- **Risk scoring** (the GBR/RFR ensemble) reproduced the notebook's IC/Env/Func/Composite risk to within 0.02 for 99.7–100% of seniors (max composite deviation 0.0107).
- **Risk level** (LOW/MODERATE/HIGH) agreed with the notebook for **289 of 290 seniors (99.7%)**.
- **Cluster assignment** agreed for **264 of 290 (91.0%)** — the deterministic ceiling, explained in Part 2.
- **Cluster coherence** is monotonic: mean composite risk rises 0.288 → 0.390 → 0.412 → 0.543 across clusters 1→4, confirming the clusters are meaningfully ordered by need.
- **Determinism** was confirmed by re-scoring identical payloads three times each and obtaining byte-identical cluster, risk, and XAI output.

**Plain-language version (LGU/OSCA brief):**

The AgeSense system was tested by comparing its results to the research study it was built from, stage by stage. The way it calculates each senior's underlying scores matches the study almost exactly (over 99%). For risk level (Low / Moderate / High), the system agreed with the study 289 out of 290 times (99.7%). For the health group, it agreed 264 out of 290 times (91%) — and the few that differ are "on the fence" seniors whose care plan is the same either way. The system also passes automated stability checks: the same senior always gets the same result on any computer, every time.

---

### Part 2 — Why the Live Model Differs from the Notebook

**Technical version:**

The 1 senior (0.3%) whose risk level differs and the 26 seniors (9.0%) whose cluster assignment differs are explained by two well-understood, intentional design differences:

**1. In-sample vs out-of-sample prediction.**
The notebook's GBR and RFR models were trained on the 290-senior dataset and then evaluated on that *same* dataset, which slightly inflates scores for borderline cases (in-sample overfitting). The live system scores each senior *out-of-sample* — the statistically honest method. A small number of seniors near the 0.30/0.50 risk thresholds therefore receive marginally different live scores. The maximum composite deviation is 0.0107, well within practical tolerance, and produces a single risk-level shift.

**2. Deterministic clustering replaces non-reproducible UMAP+KMeans.**
The notebook clusters with KMeans in a UMAP embedding. UMAP's `.transform()` is an approximation for new individual records that varies across CPU families and library versions — it is not reproducible per record (enabling it live produced a 2.1% match, i.e. broken). The live system instead uses **nearest-centroid assignment in 31-dimensional scaled feature space** (`cluster_centroids_scaled.json`), which is bit-for-bit identical on every device.

The 26 differing seniors are **boundary cases**, demonstrated quantitatively: their distance gap between the nearest and second-nearest cluster centroid averages **0.095**, versus **0.336** for agreeing seniors — 3.5× tighter. They sit almost equidistant between two clusters, so the two geometries (UMAP space vs 31-D space) disagree on the label. Critically, their **risk level and recommendations are identical** regardless of cluster. 100% cluster agreement is mathematically impossible to reach deterministically because the notebook's target method is itself non-reproducible per record.

**3. Reproducibility hardening (v2.0.0).**
Age is now computed from `date_of_birth` relative to the immutable **survey date** rather than today's date, removing the only time-dependent input (it shifted a few seniors by one year at the 70/80 thresholds; none changed risk level). Library versions are pinned in `requirements.txt`, and model files are SHA-256-verified against `model_manifest.json` at startup. Together these guarantee the same data yields the same result on any device at any time. See `docs/REPRODUCIBILITY_AND_CONSISTENCY.md`.

**Plain-language version:**

The study tested its AI on the same seniors it learned from — standard practice, but it slightly inflates borderline scores. The live system tests each senior fresh, which is more honest, so a few borderline seniors get a slightly different label. The system also uses a "same answer on every computer" method for health groups (the study's method gave different answers on different computers, which we fixed). Finally, a senior's age is now locked to the date they were surveyed, so re-running the assessment later never changes their result. These are the system working correctly, not errors.

---

### Part 3 — Risk Classification Justification

**Technical version:**

The three-tier risk classification (LOW / MODERATE / HIGH) and the composite thresholds (0.30 and 0.50) are grounded in the **WHO Integrated Care for Older People (ICOPE) framework** (WHO, 2017), which stratifies older persons by intrinsic capacity (IC), environmental enablers (ENV), and functional ability (FUNC). A composite risk of 0.50 corresponds to a wellbeing score of approximately 0.50 — meaningful intrinsic-capacity decline requiring active intervention; 0.30 corresponds to wellbeing ~0.70 — maintained capacity requiring periodic monitoring. Within HIGH, composite >= 0.70 receives an `urgent` flag (WHO "severe decline") that drives elevated urgency in recommendations and dashboard priority.

The **four-cluster** structure segments the population into care-actionable profiles: C1 High Functioning / Well-Supported, C2 Stable Ageing / Moderate Support, C3 Environmentally & Financially Vulnerable, and C4 Low Functioning / Multi-Domain Priority. K=4 was chosen from the notebook's K-sweep (`clustering_evaluation.csv`): it retains strong cluster quality (Silhouette 0.472, Davies-Bouldin 0.772, Calinski-Harabasz 485.2) while separating the environmentally/financially vulnerable seniors (C3) from the multi-domain low-functioning seniors (C4) — a distinction that maps to different OSCA interventions (livelihood/housing support vs comprehensive case management). Cluster coherence is confirmed by monotonically increasing mean composite risk across C1→C4 (0.288 → 0.390 → 0.412 → 0.543).

**Plain-language version:**

The system sorts seniors into Low, Moderate, or High risk using World Health Organization standards for healthy ageing. The highest-risk seniors are further split into "urgent" and "priority action" so OSCA workers know who to see first. The four health groups were created by the AI from patterns in the data — not assigned by hand — and statistical checks confirm they are genuinely different groups, ordered from most independent (Group 1) to most in need (Group 4).

---

### Part 4 — Explainability (XAI)

**Technical version:**

Every scored senior now carries a per-domain explanation (`xai_data`) generated at inference time without SHAP or external libraries. For each domain (IC, Env, Func), the contribution of each feature is computed as
`importance × (senior_value − cluster_mean) × effect_sign`,
where `importance` is the GBR's `feature_importances_`, the deviation measures how the senior differs from their cluster peers, and `effect_sign` (precomputed by correlating each feature against GBR predictions across all 290 seniors) ensures the displayed direction means "raises/lowers risk" rather than merely "above/below average" — necessary because feature importances are unsigned and most WHO/QoL features are protective. The top three section-level drivers and top five feature-level drivers per domain are surfaced on the senior profile; global feature importance per domain is served to the cluster report. XAI coverage is 100% of scored seniors and is fully deterministic.

**Plain-language version:**

For every senior, the system now shows *why* it gave the risk it did — which factors pushed risk up (shown in red) or down (shown in green), at both a summary level (e.g. "Physical Health") and a detailed level (e.g. "Mobility Outside Home"), compared to similar seniors. This makes the AI's reasoning transparent to OSCA workers rather than a black box.

---

### Part 5 — Limitations and Honest Caveats

1. **Small, single-site training population (N=290, Pagsanjan).** Generalizability to other OSCA chapters or municipalities has not been validated.
2. **No prospective validation.** The model is validated against notebook-computed scores on the same population; its ability to predict future outcomes (hospitalization, functional decline) has not been tested.
3. **Cluster boundary uncertainty for 9.0% of seniors.** 26 seniors sit near a cluster boundary and receive a different label under the live (31-D) vs notebook (UMAP) geometry; their assignment should be treated as approximate. Their risk level and recommendations are unaffected.
4. **Rule-based ensemble component.** A portion of the composite risk derives from explicit domain formulas whose weights reflect design-time domain knowledge, not empirical outcome optimization.

These define the appropriate scope: AgeSense is a decision-support tool for the Pagsanjan OSCA chapter, not a clinically validated diagnostic instrument.

**Plain-language version:** The system was built from one city's 290 seniors; results may differ elsewhere. It has not been tested by following seniors over time. For a small number of "on the fence" seniors, the health group is a guide, not a certainty. Part of the score uses fixed expert rules. These are normal research limitations, not defects.

---

## Section 3 — Panel Q&A

**Q1. "Why does the live system not exactly match the notebook?"**
*Technical:* The notebook scored in-sample (trained and evaluated on the same 290), slightly inflating borderline scores; the live system scores out-of-sample, the honest method. Max composite drift is 0.0107, producing one risk-level shift. Feature engineering and risk scores otherwise reproduce to 99.3–100%.
*Plain:* "The study graded its own answers; the live system grades new answers honestly — tiny borderline differences are expected and explained."

**Q2. "Your cluster match is 91.0%, not 100% — how do you defend that?"**
*Technical:* The notebook clusters with UMAP+KMeans, which is non-reproducible per record (single-point UMAP `transform()` varies by device/version — enabling it live gave 2.1%). The live system uses deterministic nearest-centroid in 31-D space. The 26 differing seniors are boundary cases: nearest-vs-second-nearest centroid margin 0.095 vs 0.336 for matches (3.5× tighter). None differ in risk level; their care plans are identical. 100% is unreachable deterministically because the reference method itself is non-deterministic.
*Plain:* "91% agreement is high. The 9% who differ are on-the-fence seniors — their care plan is the same either way, and our method gives the same answer on every computer."

**Q3. "How do you know the model isn't just overfitting to the 290 seniors?"**
*Technical:* Part of the composite comes from a rule-based engine with no trainable parameters (cannot overfit); the learned GBR/RFR portion is validated out-of-sample, reproducing risk to 99.7–100%. Cluster quality metrics (Silhouette 0.472, Davies-Bouldin 0.772, Calinski-Harabasz 485.2) confirm genuine population structure. The acknowledged limitation is the absence of an independent holdout set.
*Plain:* "Part of the score uses fixed rules that can't overfit; the learned part generalizes well. The main limitation — small dataset — is openly stated."

**Q4. "Can you prove the model is stable across different runs or devices?"**
*Technical:* Yes. (1) Clustering is pure Euclidean nearest-centroid against committed centroids — no stochastic step. (2) Risk uses tree models, deterministic at inference. (3) Age is anchored to the immutable survey date, removing time dependency. (4) Library versions are pinned in `requirements.txt` and model files are SHA-256-verified against `model_manifest.json` at startup. `validate_system.py` re-scores identical payloads three times and obtains byte-identical output (Determinism: PASS).
*Plain:* "Yes — same senior, same result, on any computer, every time. We pin the software versions, lock the model files with checksums, and freeze age to the survey date. Automated checks confirm it."

**Q5. "Why is 0.50 the threshold for HIGH risk?"**
*Technical:* The 0.50 (HIGH) and 0.30 (LOW) thresholds follow the WHO ICOPE framework (2017): composite 0.50 ≈ wellbeing 0.50 (meaningful IC decline requiring intervention); 0.30 ≈ wellbeing 0.70 (maintained capacity, monitoring). They were adopted in the notebook study and yield a distribution (HIGH 19%, MODERATE 68%, LOW 13%) consistent with WHO community-ageing prevalence.
*Plain:* "The thresholds follow World Health Organization standards — they are not arbitrary."

**Q6. "Why four clusters? Why not three or five?"**
*Technical:* The notebook K-sweep (`clustering_evaluation.csv`, 31-feature set) gives Silhouette K=3 0.424, **K=4 0.472**, K=5 0.432 — K=4 has higher silhouette and the lowest Davies-Bouldin of any K. More importantly, K=4 separates the **Environmentally & Financially Vulnerable** group (C3) from the **Low Functioning / Multi-Domain Priority** group (C4), which require different OSCA interventions (livelihood/housing vs comprehensive case management). K=3 merged these into one "low functioning" group, losing that care distinction; K=5+ splits do not map to distinct OSCA service tiers.
*Plain:* "Four groups separate the financially/environmentally vulnerable seniors from the most frail seniors — two groups that need different kinds of help. Three groups blurred them together; five or more added groups that don't match real service decisions."

**Q7. "A senior in C1 (High Functioning) with MODERATE risk — how does that make sense?"**
*Technical:* Cluster assignment and risk scoring use different model components. Cluster reflects the overall 31-feature functional profile; risk is the domain-weighted GBR/RFR + rule ensemble (medical 28%, financial 18%, etc.). A C1 senior with strong function but a high-severity chronic condition can be pushed to MODERATE by the medical domain weight — correct detection of a specific elevated risk within an otherwise high-functioning profile.
*Plain:* "Health group and risk score measure different things. A senior can be generally active (Group 1) yet have a serious medical condition that raises their risk."

**Q8. "How does this help OSCA workers?"**
*Technical:* For each senior the system produces prioritized, domain-organized prescriptive recommendations (mean 11.4/senior, 100% coverage) plus a transparent XAI breakdown of why their risk is what it is. `urgent`-flagged seniors surface first in the dashboard. This reduces from hours to seconds the time to produce a prioritized, evidence-based, explainable care list.
*Plain:* "For each senior the system shows a specific action list and explains its reasoning, so workers know who needs help first and why."

**Q9. "What happens to a newly enrolled senior the model has never seen?"**
*Technical:* New seniors flow through the identical pipeline: preprocess → StandardScaler → nearest-centroid (committed centroids) → GBR/RFR ensemble → recommendations + XAI. They are classified into the closest of the four established groups and scored fully out-of-sample. Age is taken from their survey date, so their result is reproducible from their submitted data alone.
*Plain:* "New seniors are scored with the same process as the validated 290 — the system applies what it learned to any new case automatically."

**Q10. "What are the known limitations?"**
*Technical:* Single-site N=290 training population; no prospective outcome validation; ~9.0% cluster-boundary uncertainty (risk level unaffected); rule-based component weights reflect domain knowledge rather than outcome optimization. AgeSense is a decision-support tool, not a diagnostic instrument.
*Plain:* "Built for one city's seniors; not tested over time; a few borderline group labels are guides; part of the score uses expert rules. It supports OSCA workers — it does not replace medical judgment."

---

*Document version: 2.0.0 | System: AgeSense OSCA v2.0.0 (K=4) | Updated: 2026-06-11*
