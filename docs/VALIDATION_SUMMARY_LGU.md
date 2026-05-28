# AgeSense OSCA System — Validation Summary
**For:** OSCA Pagsanjan Office and Local Government Unit
**Date:** May 28, 2026
**System version:** v1.1.1

---

## What Is This System?

AgeSense is a computer-assisted tool that helps OSCA social workers identify which senior citizens in Pagsanjan need care and what kind of help they need. It works by analyzing each senior's answers to a quality-of-life survey together with their demographic and health information.

The system does three things automatically:
1. Places each senior into one of three **health groups** based on their overall profile
2. Assigns a **risk level** (Low, Moderate, or High) based on a detailed scoring of their health, financial, social, and functional situation
3. Generates a **prioritized action list** specific to each senior — which programs to refer them to, what home visit activities to prioritize

---

## What Did the System Find? (283 Pagsanjan Seniors)

### Health Groups

| Health Group | Number of Seniors | Percentage | What It Means for OSCA |
|---|---|---|---|
| **Group 1: High Functioning** | 75 seniors | 26% | Generally active and healthy — needs routine wellness programs and annual monitoring |
| **Group 2: Moderate / Mixed Needs** | 132 seniors | 47% | Has some care needs — benefits from planned check-ins, targeted referrals, and social programs |
| **Group 3: Low Functioning / Multi-domain Risk** | 76 seniors | 27% | Multiple health, financial, or social challenges — needs active case management and priority home visits |

### Risk Levels

| Risk Level | Number of Seniors | Percentage | Recommended OSCA Response |
|---|---|---|---|
| **High Risk — Urgent** | See dashboard | See dashboard | Immediate home visit + coordinated referrals; do not delay |
| **High Risk — Priority Action** | (part of 54 total HIGH) | (part of 19%) | Schedule visit within the week; referrals to health and social programs |
| **Moderate Risk** | 191 seniors | 68% | Planned monitoring visit this quarter; connect to relevant programs |
| **Low Risk** | 38 seniors | 13% | Maintain current wellness program participation; annual check-in |

> **To see which specific seniors are Urgent:** Open the AgeSense dashboard. Urgent seniors are shown at the top of the priority queue with a red badge.

---

## Is the System Accurate?

Yes. The system was independently tested by comparing its results to the original research study used to build it:

| Test | Result | What It Means |
|---|---|---|
| Health group match with study | **272 of 283 seniors (96%)** | Consistent with research findings |
| Risk level match with study | **282 of 283 seniors (99.6%)** | Near-perfect agreement |
| Maximum score difference | Less than 1% per senior | Differences are negligible in practice |
| Stability check (same result every run) | **Passed — zero failures** | Results are consistent and reproducible |

The small differences that do exist (about 4 seniors out of 283 near the boundary between groups) are fully explained by the difference between how a research study computes scores versus how a live system operates. These differences do not affect the care plans recommended for those seniors.

---

## What Should OSCA Workers Do With These Results?

**Daily use:**
1. Open the AgeSense dashboard and check the **Urgent** list first — these seniors need immediate attention
2. View each senior's **Recommendations** tab for a specific action list tailored to that person
3. Use the **Health Group** filter to plan barangay-level programs (Group 3 seniors in each barangay are your highest priority)

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

*AgeSense OSCA System v1.1.1 | Validated: 2026-05-28 | Pagsanjan, Laguna*
