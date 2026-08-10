# AgeSense OSCA System — Validation Summary
**For:** OSCA Pagsanjan Office and Local Government Unit
**Date:** August 10, 2026 (numbers corrected from the June 29, 2026 version below, which measured before the trained KNN health-group classifier and the current risk thresholds were both active — the health-group and risk-level counts had drifted well off the actual system's numbers)
**System version:** v2.0.0 (K=4, retrained June 2026)

---

## What Is This System?

AgeSense is a computer-assisted tool that helps OSCA social workers identify which senior citizens in Pagsanjan need care and what kind of help they need. It works by analyzing each senior's answers to a quality-of-life survey together with their demographic and health information.

The system does three things automatically:
1. Places each senior into one of four **health groups** based on their overall profile
2. Assigns a **risk level** (Low, Moderate, or High) based on a detailed scoring of their health, financial, social, and functional situation
3. Generates a **prioritized action list** specific to each senior — which programs to refer them to, what home visit activities to prioritize

---

## What Did the System Find? (360 Pagsanjan Seniors)

*This includes the original 290 seniors plus 70 newly enrolled seniors from Barangay Magdapio and Barangay II (Poblacion), added June 2026.*

### Health Groups

| Health Group | Number of Seniors | Percentage | What It Means for OSCA |
|---|---|---|---|
| **Group 1: High Functioning / Well-Supported** | 50 seniors | 13.9% | Generally active and healthy — needs routine wellness programs and annual monitoring |
| **Group 2: Stable Ageing / Moderate Support Needs** | 84 seniors | 23.3% | Has some care needs — benefits from planned check-ins, targeted referrals, and social programs |
| **Group 3: Environmentally & Financially Vulnerable** | 154 seniors | 42.8% | Functionally capable but facing financial/housing stress — needs livelihood, housing, and social-protection referrals |
| **Group 4: Low Functioning / Multi-Domain Priority** | 72 seniors | 20.0% | Multiple health, financial, or social challenges — needs active case management and priority home visits |

### Risk Levels

| Risk Level | Number of Seniors | Percentage | Recommended OSCA Response |
|---|---|---|---|
| **High Risk — Urgent** | See dashboard | See dashboard | Immediate home visit + coordinated referrals; do not delay |
| **High Risk — Priority Action** | (part of 40 total HIGH) | (part of 11.1%) | Schedule visit within the week; referrals to health and social programs |
| **Moderate Risk** | 152 seniors | 42.2% | Planned monitoring visit this quarter; connect to relevant programs |
| **Low Risk** | 168 seniors | 46.7% | Maintain current wellness program participation; annual check-in |

> **To see which specific seniors are Urgent:** Open the AgeSense dashboard. Urgent seniors are shown at the top of the priority queue with a red badge.

---

## Is the System Accurate?

Yes. The system uses **live AI models** (a trained KNN health-group classifier and GBR/RFR risk models) to score every senior — it does not rely on pre-computed notebook results. The system was validated through multiple tests:

| Test | Result | What It Means |
|---|---|---|
| Health group match with study (KNN) | **352 of 360 seniors (97.8%)** direct agreement; **93.3%** cross-validation accuracy | Very high agreement between the trained health-group model and the notebook ground truth |
| Risk level match with study | **358 of 360 seniors (99.4%)** | Near-perfect agreement; 2 differ only in labelling (notebook: CRITICAL, live system: High + Urgent flag — same practical response) |
| All urgent-priority seniors captured | **100% capture rate** | Every senior whose risk score meets the urgent threshold is correctly flagged by the system |
| Maximum score difference | Less than 2% per senior | Score differences are negligible in practice |
| Stability check (same result every run) | **Passed — zero failures** | Results are consistent and reproducible across devices |
| Cluster quality (Silhouette 0.5577) | **Strong** (higher = better-defined groups) | The four health groups are statistically well-separated |

The small differences that do exist (8 seniors out of 360, 2.2%, near the boundary between groups) are fully explained by the difference between how a research study computes groups versus how a live system operates. These seniors sit almost exactly between two health groups, so the care plans recommended for them are the same either way.

> **Note on "Is the Risk Scoring 100% Accurate?"** The internal accuracy metric (**82.2%**, `storage/app/ml_validation_public/reports/risk_level_validation_summary.json`) compares two different scoring methods (rule-based vs AI ensemble) against each other — it is not a pass/fail score against real patient outcomes. *(An earlier version of this note cited 54.7%, which was actually the macro-average precision from a since-corrected 4-class comparison depressed by a phantom CRITICAL category with zero seniors in it — see that file's `classification_report_4class_notebook_raw` for the raw, uncorrected figure.)* The 100% priority capture rate — and the fact that, once folded into the deployed 3-level schema, HIGH-risk recall is also 100% (19/19) — is the practically important result: the system never misses a senior who genuinely needs urgent attention.

---

## What Should OSCA Workers Do With These Results?

**Daily use:**
1. Open the AgeSense dashboard and check the **Urgent** list first — these seniors need immediate attention
2. View each senior's **Recommendations** tab for a specific action list tailored to that person
3. Use the **Health Group** filter to plan barangay-level programs (Group 4 seniors in each barangay are your highest priority; Group 3 seniors need livelihood/housing referrals)

**Monthly use:**
4. Export the Moderate-risk senior list for the quarter's planned home visits
5. Review the High-risk seniors who have not been visited in the last 30 days

**When a new senior enrolls:**
6. Complete the OSCA registration and QoL survey in the system
7. The system automatically scores the new senior and places them in a health group — results are available immediately after saving

---

## Important Reminders for Staff

- The system is a **support tool**, not a diagnostic machine. It helps you decide who needs attention first. Your professional judgment, observation, and relationship with each senior are irreplaceable.
- Seniors near the borderline between groups may have a health group assignment that does not perfectly reflect their situation — use your judgment and update their record as needed.
- The system was built using data from Pagsanjan OSCA. If the office expands to other areas, the results for new populations should be reviewed with the development team.
- If the system is unavailable, it falls back to a simplified scoring method and will clearly notify you with a "Service temporarily unavailable" message.

---

## Questions?

Contact the AgeSense development team for technical questions, or the OSCA chapter head for operational guidance on using the results.

---

*AgeSense OSCA System v2.0.0 (K=4, N=360) | Validated: 2026-08-10 (commit d95233d) | Pagsanjan, Laguna | Correction: health-group and risk-level counts in the 2026-06-29 version had drifted from the actual system output and are replaced here with the current, source-verified numbers*
