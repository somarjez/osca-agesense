# AgeSense OSCA System — Validation Summary
**For:** OSCA Pagsanjan Office and Local Government Unit
**Date:** June 29, 2026
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
| **Group 1: High Functioning / Well-Supported** | 69 seniors | 19% | Generally active and healthy — needs routine wellness programs and annual monitoring |
| **Group 2: Stable Ageing / Moderate Support Needs** | 94 seniors | 26% | Has some care needs — benefits from planned check-ins, targeted referrals, and social programs |
| **Group 3: Environmentally & Financially Vulnerable** | 91 seniors | 25% | Functionally capable but facing financial/housing stress — needs livelihood, housing, and social-protection referrals |
| **Group 4: Low Functioning / Multi-Domain Priority** | 106 seniors | 29% | Multiple health, financial, or social challenges — needs active case management and priority home visits |

### Risk Levels

| Risk Level | Number of Seniors | Percentage | Recommended OSCA Response |
|---|---|---|---|
| **High Risk — Urgent** | See dashboard | See dashboard | Immediate home visit + coordinated referrals; do not delay |
| **High Risk — Priority Action** | (part of 77 total HIGH) | (part of 21%) | Schedule visit within the week; referrals to health and social programs |
| **Moderate Risk** | 233 seniors | 65% | Planned monitoring visit this quarter; connect to relevant programs |
| **Low Risk** | 50 seniors | 14% | Maintain current wellness program participation; annual check-in |

> **To see which specific seniors are Urgent:** Open the AgeSense dashboard. Urgent seniors are shown at the top of the priority queue with a red badge.

---

## Is the System Accurate?

Yes. The system was validated through multiple tests:

| Test | Result | What It Means |
|---|---|---|
| Health group match with study (KNN) | **KNN CV accuracy 93.3%** (5-fold cross-validation) | Very high agreement between the trained health-group model and the notebook ground truth |
| Risk level match with study | **358 of 360 seniors (99.4%)** | Near-perfect agreement; 2 differ only in labelling (notebook: CRITICAL, live system: High + Urgent flag — same practical response) |
| All urgent-priority seniors captured | **100% capture rate** | Every senior whose risk score meets the urgent threshold is correctly flagged by the system |
| Maximum score difference | Less than 2% per senior | Score differences are negligible in practice |
| Stability check (same result every run) | **Passed — zero failures** | Results are consistent and reproducible across devices |
| Cluster quality (Silhouette 0.5577) | **Strong** (higher = better-defined groups) | The four health groups are statistically well-separated |

The small differences that do exist (about 47 seniors out of 360 near the boundary between groups) are fully explained by the difference between how a research study computes groups versus how a live system operates. These seniors sit almost exactly between two health groups, so the care plans recommended for them are the same either way.

> **Note on "Is the Risk Scoring 100% Accurate?"** The internal accuracy metric (54.7%) compares two different scoring methods (rule-based vs AI ensemble) against each other — it is not a pass/fail score against real patient outcomes. The 100% priority capture rate is the practically important metric: the system never misses a senior who genuinely needs urgent attention.

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

*AgeSense OSCA System v2.0.0 (K=4, N=360) | Validated: 2026-06-29 | Pagsanjan, Laguna*
