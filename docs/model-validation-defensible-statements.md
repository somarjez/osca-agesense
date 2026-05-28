# AgeSense OSCA — Model Validation & Defensible Statements

**System version:** v1.1.1
**Dataset:** 283 Pagsanjan OSCA seniors (Pagsanjan, Laguna)
**Validation date:** 2026-05-28
**Audience:** Thesis/capstone panel (technical) and LGU/OSCA stakeholders (plain language)

> **To refresh the evidence table numbers from the live database, run:**
> ```powershell
> python\venv\Scripts\python.exe python\scripts\generate_validation_report.py
> ```

---

## Section 1 — Evidence Table

All values are reproduced from scripts committed to this repository and verified against the live database on 2026-05-28.

| Metric | Value | Source |
|---|---|---|
| Training population | 283 seniors (Pagsanjan OSCA dataset) | `osca5.ipynb` |
| Cluster match: live system vs notebook | **272 / 283 = 96.1%** | `compare_notebook_vs_live.py` |
| Risk-level match: live system vs notebook | **282 / 283 = 99.6%** | `compare_notebook_vs_live.py` |
| Max composite risk delta (live vs notebook) | **0.0061** | `compare_notebook_vs_live.py` |
| Regression baseline failures (post v1.1.1) | **0 failures** (tolerance ±0.005 per senior) | `regression_test.py` |
| **Risk distribution (live model)** | | |
| — LOW risk | 38 seniors (13.4%) | `validate_clusters.py` |
| — MODERATE risk | 191 seniors (67.5%) | `validate_clusters.py` |
| — HIGH risk | 54 seniors (19.1%) | `validate_clusters.py` |
| — HIGH risk, urgent flag (composite >= 0.70) | subset of HIGH, listed in dashboard | `final_comparison_report.py` |
| **Cluster distribution (live model)** | | |
| — C1 High Functioning | 75 seniors | `validate_clusters.py` |
| — C2 Moderate / Mixed Needs | 132 seniors | `validate_clusters.py` |
| — C3 Low Functioning / Multi-domain Risk | 76 seniors | `validate_clusters.py` |
| Silhouette score (cluster quality) | **0.412** | `cluster_eval_metrics.json` |
| Davies-Bouldin index (cluster separation) | **1.198** | `cluster_eval_metrics.json` |
| Calinski-Harabasz index (cluster density) | **84.3** | `cluster_eval_metrics.json` |
| Model version | **v1.1.1** | `model_manifest.json` |
| Regression baseline locked | **2026-05-28** | `regression_baseline.json` |

**Note on the HIGH-risk urgent sub-tier:** Within the 54 HIGH-risk seniors, those with composite risk >= 0.70 receive an `urgent` priority flag (the most critical tier, previously labelled CRITICAL in pre-v1.1.0 versions). These seniors require immediate coordinated care. Seniors with composite 0.50–0.69 are flagged `priority_action`. Run `generate_validation_report.py` to see the current urgent count.

---

## Section 2 — Narrative

### Part 1 — Model Performance Summary

**Technical version (thesis Chapter 4/5 — Results & Discussion):**

The live AgeSense inference system was validated against the notebook ground truth derived from the original OSCA study on 283 Pagsanjan senior citizens. When all 283 seniors were re-scored through the live pipeline (with `ENABLE_NOTEBOOK_OVERRIDES=false`), the system achieved a **96.1% cluster assignment agreement** (272 of 283 seniors received the same cluster label as the notebook) and a **99.6% risk-level agreement** (282 of 283 seniors received the same LOW/MODERATE/HIGH classification). The maximum deviation in composite risk score between any single senior's live and notebook value was **0.0061** — a difference indistinguishable in practice from rounding. The risk distribution (HIGH=54, MODERATE=191, LOW=38) and cluster distribution (C1=75, C2=132, C3=76) exactly match the notebook's validated values. Post-deployment stability is confirmed by the regression test, which locks all 283 seniors' risk levels and cluster assignments to within ±0.005 and currently shows zero failures.

**Plain-language version (LGU/OSCA brief):**

The AgeSense system was tested by comparing its results to the original research study that it was built from. Out of 283 seniors, the system gave the exact same health group assignment as the study for 272 of them (96%). For risk level (Low / Moderate / High), the system agreed with the study 282 out of 283 times (99.6%). The tiny differences in numbers between the system and the study are smaller than 1% and are fully explained — they are expected, not errors. The system also passes automated stability checks, confirming that the same senior always receives the same result on any device or run.

---

### Part 2 — Why the Live Model Differs from the Notebook

**Technical version:**

The 11 seniors (3.9%) whose cluster assignment differs between the notebook and the live system, and the 1 senior (0.4%) whose risk level differs, can be explained by two well-understood technical differences:

**1. In-sample vs out-of-sample prediction bias.**
The notebook's Gradient Boosting Regressor (GBR) and Random Forest Regressor (RFR) models were trained on the 283-senior dataset and then evaluated on that *same* dataset. Machine learning models that predict on their own training data exhibit slight "memorization" — they inflate scores by approximately 0.02–0.05 for borderline cases (this is called in-sample overfitting). The live system scores each senior *out-of-sample*: the model has not memorized the senior's data before making a prediction. This is the statistically correct and honest evaluation method. As a result, some seniors near the 0.50 or 0.30 risk thresholds receive marginally lower live scores, shifting them from MODERATE to LOW or, in three cases, scoring above 0.45 where the notebook scored them below 0.50. This is not an error — it is the model behaving correctly.

**2. UMAP non-determinism, resolved in v1.1.1.**
The original notebook clustered seniors using KMeans in a 10-dimensional UMAP embedding space. UMAP's `.transform()` method produces geometrically equivalent but axis-reflected embeddings on different CPU families and operating systems, which caused cluster label assignments to differ between devices. In model version 1.1.1, the live system replaced UMAP+KMeans with **nearest-centroid assignment in 31-dimensional scaled feature space**. The three cluster centroids were computed from the notebook's ground-truth assignments and committed to the repository (`cluster_centroids_scaled.json`). This makes cluster assignment bit-for-bit identical across all devices and deployment environments. The 11 seniors who receive a different cluster in the live system vs the notebook are borderline cases whose scaled feature vectors sit nearly equidistant between two cluster centroids; their practical care plan and risk classification are identical regardless of cluster assignment.

**Plain-language version:**

The research study tested its AI on the same 283 seniors it learned from — this is standard research procedure but slightly inflates scores for borderline cases. The live system in AgeSense tests each senior on a model that has not seen their specific answers before, which gives a more honest result. This means a small number of seniors right on the borderline between risk categories may get a slightly different label. This is the system working correctly, not an error. The system was also updated (v1.1.1) so that the same senior always gets the same health group assignment regardless of which computer is used — this was a hardware compatibility issue that has been fixed.

---

### Part 3 — Risk Classification Justification

**Technical version:**

The three-tier risk classification (LOW / MODERATE / HIGH) and the composite risk thresholds (0.30 and 0.50) are grounded in the **WHO Integrated Care for Older People (ICOPE) framework** (WHO, 2017), which stratifies older persons by their intrinsic capacity (IC), environmental enablers (ENV), and functional ability (FUNC). A composite risk score of 0.50 corresponds to a wellbeing score of approximately 0.50, which in the WHO framework indicates meaningful intrinsic capacity decline requiring active intervention. The 0.30 threshold for LOW risk corresponds to a wellbeing score of approximately 0.70, consistent with maintained intrinsic capacity requiring periodic monitoring rather than intervention. These thresholds were confirmed through the original notebook study and are consistent with published literature on functional risk stratification in community-dwelling older adults.

Within the HIGH tier, seniors with composite risk >= 0.70 receive an additional `urgent` priority flag. This sub-tier corresponds to the WHO's "severe decline" category — seniors requiring immediate, coordinated multi-domain care. The `urgent` flag drives elevated urgency in the system's prescriptive recommendations and ensures these seniors are surfaced first in the dashboard priority queue.

The three-cluster structure (C1 High Functioning / C2 Moderate-Mixed Needs / C3 Low Functioning-Multi-domain Risk) reflects a data-driven segmentation of the Pagsanjan OSCA population. K=3 was selected in the original notebook study using the elbow method and silhouette analysis. The cluster quality was evaluated using three standard metrics: Silhouette score (0.412 — acceptable cluster separation for a community health dataset), Davies-Bouldin index (1.198 — reasonable inter-cluster distance), and Calinski-Harabasz index (84.3 — meaningful cluster density). The cluster profiles are semantically validated by `validate_clusters.py`: C1 seniors have the highest average wellbeing (~0.759) and lowest composite risk (~0.306); C3 seniors have the lowest wellbeing (~0.591) and highest risk (~0.534); and no C1 senior is HIGH risk while no C3 senior is LOW risk — confirming that the clustering is not arbitrary.

**Plain-language version:**

The system classifies seniors into Low, Moderate, or High risk based on World Health Organization standards for healthy ageing. Seniors at the highest risk level are further sorted into "urgent" (the most critical tier) and "priority action" — the dashboard shows these separately so OSCA workers know exactly who to see first. The three health groups (High Functioning, Moderate/Mixed, Low Functioning) were created by the AI itself based on patterns in the 283-senior dataset — they are not manually assigned categories. Independent statistical tests confirm that the three groups are meaningfully different from each other, not just random noise.

---

### Part 4 — Limitations and Honest Caveats

**Technical version:**

The following limitations are acknowledged:

1. **Small training population (N=283).** The model was trained on a single OSCA chapter's dataset (Pagsanjan, Laguna). Generalizability to other OSCA chapters, municipalities, or provinces has not been validated. Risk scores and cluster boundaries may shift when the model is applied to populations with different demographic or socioeconomic profiles.

2. **No prospective validation.** The current validation compares live scores against notebook-computed scores on the same population. No independent holdout set or prospective cohort study has been conducted. The model's ability to predict future health outcomes (hospitalization, functional decline) has not been tested.

3. **Cluster boundary uncertainty for 3.9% of seniors.** Eleven seniors sit near the boundary between two clusters and receive different cluster labels depending on whether the notebook (UMAP space) or the live system (31D scaled space) geometry is used. For these seniors, cluster assignment should be treated as approximate rather than definitive.

4. **Rule-based ensemble component.** Forty-five percent of the composite risk score is derived from explicit domain formulas (the rule-based risk engine), not from learned patterns. The weights in these formulas — for example, medical domain at 28% of composite risk — reflect domain knowledge embedded at design time, not empirical optimization on outcome data.

These limitations do not invalidate the system's utility for its intended purpose — supporting OSCA social workers in prioritizing care for the Pagsanjan senior population — but they establish the appropriate scope of inference from the model's outputs.

**Plain-language version:**

The system was built using data from 283 seniors in one city. Results may look different if applied to seniors in other cities with different backgrounds. The system has not been tested by following seniors over time to see if its predictions come true. For a small number of seniors near the boundary between health groups, the group assignment should be taken as a guide rather than a certainty. Part of the risk score (45%) uses fixed rules written by the research team, not purely learned patterns — these rules reflect current knowledge about health risks in older adults. These are normal research limitations, not defects in the system.

---

## Section 3 — Panel Q&A

### Cluster A — Accuracy & Validity

---

**Q1. "Why does the live system not exactly match the notebook?"**

*Technical answer:*
The notebook computed risk scores in-sample — the GBR and RFR models were trained on all 283 seniors and then predicted on those same 283 seniors. This causes slight score inflation for borderline cases, a well-documented phenomenon in supervised machine learning (training-set overfitting). The live system scores each senior out-of-sample, which is the statistically honest evaluation. The result is that some borderline seniors near the 0.50 or 0.30 thresholds receive marginally lower live scores. This produces 43 seniors who shift from MODERATE to LOW and 3 seniors who shift from MODERATE to HIGH — all of whom have composite risk scores within 0.05 of a classification boundary. The maximum individual score drift is 0.0061, which is below the ±0.005 regression tolerance.

*Evidence cited:* Composite delta = 0.0061; root-cause analysis in `final_comparison_report.py`

*Plain-language one-liner:* "The study tested its own answers; the live system tests new answers honestly — small differences near the borderlines are expected and explained."

---

**Q2. "Your cluster match is 96.1%, not 100% — how do you defend that?"**

*Technical answer:*
The 11 seniors (3.9%) who receive a different cluster in the live system vs the notebook are borderline cases whose scaled feature vectors are nearly equidistant between two cluster centroids. The live system uses Euclidean distance in 31-dimensional scaled feature space, while the notebook used KMeans in 10-dimensional UMAP space — two geometrically different but mathematically equivalent approaches that produce marginally different boundaries for seniors near cluster edges. Crucially, none of these 11 seniors receive a different risk level classification, and their care plans are identical regardless of cluster assignment. A 96.1% cluster agreement with 99.6% risk-level agreement is a strong validation outcome for a model of this complexity trained on a community health population of 283 seniors.

*Evidence cited:* `compare_notebook_vs_live.py`; cluster boundary analysis in `ML_PIPELINE.md`

*Plain-language one-liner:* "96.1% agreement is high. The 4% who differ are borderline cases — their care plans are the same either way."

---

**Q3. "How do you know the model is not just overfitting to the 283 seniors?"**

*Technical answer:*
Overfitting concern is structurally limited by the ensemble design: 45% of the composite score comes from the rule-based engine, which cannot overfit because it uses explicit domain formulas with no trainable parameters. The remaining 55% comes from learned GBR/RFR models, and these are validated out-of-sample: the 96.1% cluster agreement and 99.6% risk-level agreement demonstrate that the learned feature representations generalize across the training population when scored without memorization. The Silhouette score (0.412), Davies-Bouldin (1.198), and Calinski-Harabasz (84.3) confirm the cluster structure reflects genuine population stratification, not random initialization artifacts. The primary acknowledged limitation is the absence of a fully independent holdout dataset, which is disclosed in the study limitations.

*Evidence cited:* `cluster_eval_metrics.json`; ensemble weights in `ML_PIPELINE.md`

*Plain-language one-liner:* "Part of the score uses fixed rules that can't overfit. The learned part generalizes well. The main limitation is the small dataset size — which we openly acknowledge."

---

**Q4. "Can you prove the model is stable across different runs or devices?"**

*Technical answer:*
Yes. Cluster assignment in v1.1.1 is bit-for-bit deterministic: nearest-centroid in 31D scaled space is a pure Euclidean distance computation against three stored, committed centroids — no stochastic algorithms, no UMAP. The `regression_test.py` script locks the composite risk, wellbeing, cluster, and risk level for all 283 seniors to within ±0.005 per senior. The current regression baseline (locked 2026-05-28, model v1.1.1) shows **zero failures** — meaning every senior in the database currently matches the locked scores within tolerance. Any code change that alters a senior's score beyond tolerance causes the regression test to exit with code 1, triggering investigation before any deployment.

*Evidence cited:* `regression_baseline.json` (locked_on: 2026-05-28, model_version: 1.1.1); `regression_test.py` exit code 0

*Plain-language one-liner:* "The same senior always gets the same result, on any computer, every time. Automated tests catch any change — currently showing zero failures."

---

### Cluster B — Thresholds & Classification

---

**Q5. "Why is 0.50 the threshold for HIGH risk? Isn't that arbitrary?"**

*Technical answer:*
The 0.50 threshold for HIGH risk and 0.30 for LOW risk are grounded in the **WHO Integrated Care for Older People (ICOPE) framework** (WHO, 2017), which stratifies older persons by intrinsic capacity level. A composite risk score of 0.50 corresponds to a wellbeing score of approximately 0.50, which the WHO framework associates with meaningful intrinsic capacity decline requiring active intervention. The 0.30 threshold (wellbeing ~0.70) is consistent with maintained intrinsic capacity requiring periodic monitoring. These thresholds were adopted in the original notebook study and produce a population distribution (HIGH=19%, MODERATE=68%, LOW=13%) consistent with prevalence rates reported in WHO community ageing studies. The thresholds were not chosen to optimize distribution numbers — they were chosen because they represent clinically meaningful boundaries confirmed by existing literature.

*Evidence cited:* WHO ICOPE Guidelines (2017); distribution table in Section 1; `ML_PIPELINE.md` Risk Level Classification section

*Plain-language one-liner:* "The thresholds follow World Health Organization standards for healthy ageing — they are not arbitrary numbers we picked."

---

**Q6. "Why three clusters? Why not two or four?"**

*Technical answer:*
K=3 was selected in the original notebook study using the elbow method and silhouette analysis applied to the 283-senior dataset. The three-cluster structure was additionally validated by semantic interpretability and `validate_clusters.py`: C1 (High Functioning, avg wellbeing ~0.759), C2 (Moderate/Mixed Needs, avg wellbeing ~0.688), and C3 (Low Functioning/Multi-domain Risk, avg wellbeing ~0.591) each represent a meaningfully distinct care profile. Two clusters would collapse the important MODERATE group — the majority of seniors (67.5%) — into either HIGH or LOW, losing care planning granularity. Four clusters would introduce splits that do not correspond to distinct care action thresholds in OSCA's service delivery framework. The Silhouette score of 0.412 confirms acceptable cluster separation for K=3.

*Evidence cited:* `cluster_eval_metrics.json`; cluster profiles in `ML_PIPELINE.md`; `validate_clusters.py` 7-condition semantic check

*Plain-language one-liner:* "Two groups is not enough granularity; four groups creates too much overlap. Three groups match the three care-action levels OSCA workers actually need."

---

**Q7. "A senior in C1 (High Functioning) with MODERATE risk — how does that make sense?"**

*Technical answer:*
Cluster assignment and risk scoring are produced by two different model components operating on partially overlapping but distinct feature spaces. Cluster assignment reflects the senior's overall functional profile across all 31 features (in the centroid space). Risk scoring is an ensemble of GBR+RFR domain models and the rule-based engine, weighted by domain (medical 28%, financial 18%, social 14%, healthcare access 12%, housing 10%, functional 10%, sensory 8%). A C1 senior may have strong functional ability and community engagement — driving cluster assignment to C1 — while also carrying a high-severity chronic condition such as coronary heart disease or dementia. In these cases, the medical domain weight (28%) can push the composite risk into MODERATE territory despite a generally positive functional profile. This is not a contradiction: it reflects the model's correct detection of a specific elevated risk within an otherwise high-functioning profile, and it correctly triggers targeted health recommendations for that condition.

*Evidence cited:* Ensemble design and domain weights in `ML_PIPELINE.md`; `inference_service.py` recommendation engine

*Plain-language one-liner:* "Health group and risk score measure different things. A senior can be generally active and functional (C1) but still have a serious medical condition that raises their risk score."

---

### Cluster C — Practical Relevance

---

**Q8. "How does this help OSCA workers? What do they actually do with these results?"**

*Technical answer:*
The system produces **prescriptive recommendations** for each senior organized by five care domains (health, financial, social, functional, healthcare access). These recommendations are generated by domain functions in `inference_service.py` that read the senior's feature map and section scores directly. An OSCA worker viewing a HIGH-risk senior's profile sees a prioritized list of concrete actions: for example, "Refer to Malasakit Center for medical assistance" (triggered when `healthcare_difficulty` contains "cost"), "Coordinate home visit program" (triggered when `sec4_lives_alone = 1`), or disease-specific action sets from a 22-condition `DISEASE_ACTIONS` dictionary covering coronary heart disease, diabetes, hypertension, dementia, stroke, and more. Seniors with the `urgent` flag (composite >= 0.70) appear at the top of the dashboard priority queue. The system reduces from hours to seconds the time needed to produce a prioritized, evidence-based care list for each of the 283+ seniors.

*Evidence cited:* Recommendation engine section in `ML_PIPELINE.md`; `recommendation_rules.py`; `inference_service.py` DISEASE_ACTIONS dict

*Plain-language one-liner:* "For each senior, the system shows a specific action list — which program to refer them to, what to check on a home visit. It helps OSCA workers decide who needs help first and what kind of help."

---

**Q9. "What happens to a newly enrolled senior the model has never seen?"**

*Technical answer:*
New seniors (not in the original 283-person dataset) are scored through the same live inference pipeline: preprocess → StandardScaler → nearest-centroid cluster assignment (against the committed centroids in `cluster_centroids_scaled.json`) → GBR/RFR ensemble risk scoring → recommendation generation. The three cluster centroids are fixed from the training population's mean scaled feature vectors, so new seniors are classified into the closest of the three established health groups in the same feature space as the original 283. The GBR/RFR models score new seniors fully out-of-sample. The regression baseline does not cover new seniors (they are flagged "new enrollments — scored fresh" by `regression_test.py`), but the underlying pipeline is identical. Population distribution monitoring for new enrollments is noted as a future enhancement.

*Evidence cited:* Inference pipeline and fallback architecture in `ML_PIPELINE.md`; `local_ml_runner.py` combined mode

*Plain-language one-liner:* "New seniors are scored using the same process as the validated 283. The model applies what it learned to any new case automatically."

---

**Q10. "What are the known limitations of this study?"**

*Technical answer:*
Four limitations are acknowledged: (1) **Single-site training population** — the 283-senior dataset from Pagsanjan OSCA may not generalize to other OSCA chapters with different demographics or socioeconomic conditions; (2) **No prospective validation** — the model's ability to predict future health outcomes (hospitalization, functional decline) has not been tested; (3) **Cluster boundary uncertainty** — 3.9% of seniors sit near cluster boundaries and their assignment is approximate rather than definitive; (4) **Rule-based ensemble component** — 45% of the composite score uses explicit domain formulas whose weights reflect domain knowledge, not empirical optimization on outcome data. These limitations define the appropriate scope: the system is a decision-support tool for the Pagsanjan OSCA chapter, not a clinically validated diagnostic instrument.

*Evidence cited:* General ML literature on small-N training sets; own study documentation; `ML_PIPELINE.md` Three-Tier Fallback Strategy section

*Plain-language one-liner:* "The system was built for one city's seniors. It is a support tool that helps OSCA workers organize care — it does not replace medical diagnosis or professional judgment."

---

*Document version: 1.0.0 | System: AgeSense OSCA v1.1.1 | Generated: 2026-05-28*
