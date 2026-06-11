"""
OSCA Recommendation Rules Library
Structured rule library keyed by recommendation_code.

These rules are decision-support suggestions only — not clinical predictions,
diagnoses, or eligibility guarantees. All recommendations require human
validation by OSCA/social worker/health professional before action.

Thesis framing:
- "estimated risk level" / "risk indicator" — NOT "clinical prediction"
- "refer," "verify," "assess eligibility," "coordinate," "assist with documents"
- For mental health: "reported emotional concern" or "possible psychosocial concern"

EVIDENCE_SOURCES: cited program/policy backing each recommendation.
Build via build_rec_from_rule() which merges rule + evidence source automatically.
"""

# ── Evidence source registry ──────────────────────────────────────────────────
# Each entry is keyed by source_id and supplies citation fields merged into
# every recommendation built from a rule that references this source_id.
EVIDENCE_SOURCES: dict = {
    "PHILHEALTH_YAKAP": {
        "source_type": "Philippine government health program",
        "evidence_source": "PhilHealth YAKAP / Konsulta Primary Care Benefit",
        "apa_reference": (
            "Philippine Health Insurance Corporation. (n.d.). "
            "PhilHealth YAKAP: Yaman ng Kalusugan Program."
        ),
        "service_provider": "PhilHealth YAKAP/Konsulta, RHU/BHC, OSCA",
    },
    "DOH_PHILPEN": {
        "source_type": "Philippine public-health protocol",
        "evidence_source": (
            "DOH PhilPEN protocol for hypertension and diabetes in primary care"
        ),
        "apa_reference": (
            "Department of Health. (2012). Philippine Package of Essential "
            "Noncommunicable Disease Interventions: PhilPEN protocol on the integrated "
            "management of hypertension and diabetes in primary health care facilities."
        ),
        "service_provider": "RHU/BHC, DOH primary care facilities",
    },
    "RA_9994": {
        "source_type": "Philippine law",
        "evidence_source": "Republic Act No. 9994 / Expanded Senior Citizens Act of 2010",
        "apa_reference": (
            "Republic Act No. 9994. (2010). Expanded Senior Citizens Act of 2010."
        ),
        "service_provider": "OSCA, LGU, accredited establishments, health providers",
    },
    "DSWD_AICS": {
        "source_type": "Philippine social welfare program",
        "evidence_source": "DSWD Assistance to Individuals in Crisis Situation",
        "apa_reference": (
            "Department of Social Welfare and Development. (n.d.). "
            "AICS Program: Assistance to Individuals in Crisis Situation."
        ),
        "service_provider": "DSWD, MSWDO/CSWDO",
    },
    "SOCIAL_PENSION": {
        "source_type": "Philippine senior citizen welfare program",
        "evidence_source": "Social Pension Program for Indigent Senior Citizens",
        "apa_reference": (
            "Department of Social Welfare and Development. (n.d.). "
            "Social Pension Program for Indigent Senior Citizens."
        ),
        "service_provider": "OSCA, MSWDO/CSWDO, DSWD/NCSC",
    },
    "PCSO_MAP": {
        "source_type": "Philippine medical assistance program",
        "evidence_source": "PCSO Medical Assistance Program",
        "apa_reference": (
            "Philippine Charity Sweepstakes Office. (n.d.). Medical Assistance Program."
        ),
        "service_provider": "PCSO, hospital social service, MSWDO/OSCA",
    },
    "MALASAKIT_CENTER": {
        "source_type": "Philippine law / medical assistance service",
        "evidence_source": "Republic Act No. 11463 / Malasakit Centers Act",
        "apa_reference": (
            "Republic Act No. 11463. (2019). Malasakit Centers Act."
        ),
        "service_provider": "Malasakit Center, DOH hospital, PGH, DSWD, PCSO, PhilHealth",
    },
    "RA_11982": {
        "source_type": "Philippine law",
        "evidence_source": "Republic Act No. 11982 / Expanded Centenarians Act",
        "apa_reference": (
            "Republic Act No. 11982. (2024). Expanded Centenarians Act."
        ),
        "service_provider": "NCSC, OSCA, LGU/MSWDO",
    },
    "DOLE_KABUHAYAN": {
        "source_type": "Philippine livelihood program",
        "evidence_source": "DOLE Integrated Livelihood Program / Kabuhayan Program",
        "apa_reference": (
            "Department of Labor and Employment. (n.d.). About Kabuhayan."
        ),
        "service_provider": "DOLE, PESO, LGU",
    },
    "TESDA_NCSC": {
        "source_type": "Philippine skills training service",
        "evidence_source": "TESDA-NCSC skills training for Filipino senior citizens",
        "apa_reference": (
            "Technical Education and Skills Development Authority. (2025). "
            "TESDA, NCSC sign Memorandum of Agreement."
        ),
        "service_provider": "TESDA, NCSC, LGU",
    },
    "WHO_HEALTHY_AGEING": {
        "source_type": "International public-health framework",
        "evidence_source": "WHO Healthy Ageing Framework",
        "apa_reference": (
            "World Health Organization. (2020). Healthy ageing and functional ability."
        ),
        "service_provider": "OSCA, LGU, RHU/BHC, MSWDO/CSWDO, barangay",
    },
    "RA_11036": {
        "source_type": "Philippine law",
        "evidence_source": "Republic Act No. 11036 / National Mental Health Act",
        "apa_reference": (
            "Republic Act No. 11036. (2018). National Mental Health Act of the Philippines."
        ),
        "service_provider": "RHU, mental health professional, NCMH (hotline 1553)",
    },
    "DSWD_SLP": {
        "source_type": "Philippine livelihood program",
        "evidence_source": "DSWD Sustainable Livelihood Program",
        "apa_reference": (
            "Department of Social Welfare and Development. (n.d.). "
            "Sustainable Livelihood Program."
        ),
        "service_provider": "DSWD SLP, MSWDO/CSWDO",
    },
    "PHILHEALTH_UHC": {
        "source_type": "Philippine government health program",
        "evidence_source": "PhilHealth Universal Health Care coverage and benefits",
        "apa_reference": (
            "Philippine Health Insurance Corporation. (n.d.). "
            "Universal Health Care Act (RA 11223): PhilHealth membership and benefits."
        ),
        "service_provider": "PhilHealth LHIO, OSCA, RHU/BHC",
    },
    "BHW_COMMUNITY": {
        "source_type": "Philippine public health mandate",
        "evidence_source": "Barangay Health Worker mandate (RA 7883)",
        "apa_reference": (
            "Republic Act No. 7883. (1995). Barangay Health Workers' Benefits and Incentives Act."
        ),
        "service_provider": "BHW, RHU, barangay, OSCA",
    },
    "LGU_SOCIAL_WELFARE": {
        "source_type": "Philippine local government mandate",
        "evidence_source": "LGU social welfare and DRRM vulnerable-sector mandate",
        "apa_reference": (
            "Republic Act No. 7160. (1991). Local Government Code of the Philippines. "
            "Section on social welfare services for vulnerable sectors."
        ),
        "service_provider": "MSWDO/CSWDO, barangay, MDRRMO, LGU",
    },
    "RA_7277": {
        "source_type": "Philippine law",
        "evidence_source": "Republic Act No. 7277 / Magna Carta for Persons with Disability (as amended)",
        "apa_reference": (
            "Republic Act No. 7277. (1992). Magna Carta for Persons with Disability "
            "(as amended by RA 9442 and RA 10524)."
        ),
        "service_provider": "PDAO, MSWDO/CSWDO, OSCA",
    },
}

RECOMMENDATION_RULES = {
    # ── Source-backed rules (new format: source_id links to EVIDENCE_SOURCES) ───
    "HEALTH_YAKAP_CHECKUP": {
        "code": "HEALTH_YAKAP_CHECKUP",
        "category": "health",
        "domain": "Intrinsic Capacity",
        "source_id": "PHILHEALTH_YAKAP",
        "action": (
            "Verify the senior's PhilHealth membership and selected YAKAP/Konsulta clinic, "
            "then assist in scheduling a primary-care consultation."
        ),
        "reason_template": (
            "Senior has no recorded regular check-up, reported chronic condition, "
            "high medical risk indicator, or advanced age."
        ),
        "trigger_summary": "No regular checkup, chronic condition, high medical risk, or advanced age.",
        "priority_base": 4,
        "requires_human_validation": True,
        "documents_needed": ["PhilHealth ID or MDR", "Senior Citizen ID"],
    },
    "HEALTH_HYPERTENSION_PHILPEN": {
        "code": "HEALTH_HYPERTENSION_PHILPEN",
        "category": "health",
        "domain": "Intrinsic Capacity",
        "source_id": "DOH_PHILPEN",
        "action": (
            "Refer the senior to the RHU/BHC for blood pressure monitoring "
            "and physician-led hypertension follow-up."
        ),
        "reason_template": "Senior has reported hypertension or high blood pressure.",
        "trigger_summary": "Reported hypertension or high blood pressure.",
        "priority_base": 5,
        "requires_human_validation": True,
        "documents_needed": None,
    },
    "HEALTH_DIABETES_PHILPEN": {
        "code": "HEALTH_DIABETES_PHILPEN",
        "category": "health",
        "domain": "Intrinsic Capacity",
        "source_id": "DOH_PHILPEN",
        "action": (
            "Refer the senior to the RHU/BHC or YAKAP provider for blood sugar monitoring "
            "and physician-led diabetes follow-up."
        ),
        "reason_template": "Senior has reported diabetes or blood sugar concern.",
        "trigger_summary": "Reported diabetes or blood sugar concern.",
        "priority_base": 5,
        "requires_human_validation": True,
        "documents_needed": None,
    },
    "FINANCIAL_SOCIAL_PENSION": {
        "code": "FINANCIAL_SOCIAL_PENSION",
        "category": "financial",
        "domain": "Environment",
        "source_id": "SOCIAL_PENSION",
        "action": (
            "Refer the senior for Social Pension validation through OSCA/MSWDO/NCSC "
            "or DSWD — assist with the required intake form and supporting documents."
        ),
        "reason_template": (
            "Senior has indicators of low income, no pension, frailty, disability, "
            "or limited regular family support."
        ),
        "trigger_summary": "Low income, no pension, frailty, disability, or limited regular family support.",
        "priority_base": 5,
        "requires_human_validation": True,
        "documents_needed": [
            "Senior Citizen ID", "barangay certificate of indigency", "birth certificate",
        ],
    },
    "FINANCIAL_AICS": {
        "code": "FINANCIAL_AICS",
        "category": "financial",
        "domain": "Environment",
        "source_id": "DSWD_AICS",
        "action": (
            "Refer the senior to MSWDO/DSWD AICS for social-worker assessment of possible "
            "medical, food, transportation, or emergency financial assistance."
        ),
        "reason_template": (
            "Senior presents indicators of crisis-related financial difficulty, "
            "medicine cost concern, or healthcare access difficulty."
        ),
        "trigger_summary": (
            "Crisis-related financial difficulty, medicine cost concern, or access difficulty."
        ),
        "priority_base": 5,
        "requires_human_validation": True,
        "documents_needed": ["valid ID", "barangay certification"],
    },
    "MEDICAL_PCSO_MAP": {
        "code": "MEDICAL_PCSO_MAP",
        "category": "healthcare_access",
        "domain": "Environment",
        "source_id": "PCSO_MAP",
        "action": (
            "Assist the senior in preparing documents for the PCSO Medical Assistance Program "
            "if there are major medical expenses (dialysis, surgery, chemotherapy, expensive medicines)."
        ),
        "reason_template": (
            "Senior presents indicators of hospital bill, major treatment, or medical expense difficulty."
        ),
        "trigger_summary": (
            "Hospital bill, dialysis, chemotherapy, surgery, expensive medicine, or major treatment need."
        ),
        "priority_base": 5,
        "requires_human_validation": True,
        "documents_needed": [
            "hospital bill or medical certificate", "PhilHealth MDR",
            "Senior Citizen ID", "DSWD certificate of indigency if applicable",
        ],
    },
    "MEDICAL_MALASAKIT_CENTER": {
        "code": "MEDICAL_MALASAKIT_CENTER",
        "category": "healthcare_access",
        "domain": "Environment",
        "source_id": "MALASAKIT_CENTER",
        "action": (
            "Refer the senior to a Malasakit Center for coordinated medical and financial "
            "assistance — covers PhilHealth, PCSO, DSWD, and DOH support for hospital-based care."
        ),
        "reason_template": (
            "Senior has indicators of hospital admission, high medical expense, "
            "or financially difficult medical care need."
        ),
        "trigger_summary": "Hospital admission, hospital bill, or financially difficult medical care.",
        "priority_base": 5,
        "requires_human_validation": True,
        "documents_needed": [
            "hospital bill", "PhilHealth MDR", "Senior Citizen ID", "valid ID",
        ],
    },
    "BENEFIT_CENTENARIAN": {
        "code": "BENEFIT_CENTENARIAN",
        "category": "financial",
        "domain": "Environment",
        "source_id": "RA_11982",
        "action": (
            "Check the senior's eligibility for the Expanded Centenarians Act cash gift "
            "and assist with document preparation through OSCA/MSWDO/NCSC."
        ),
        "reason_template": (
            "Senior is at or approaching a centenarian milestone age "
            "(80, 85, 90, 95, or 100 years old)."
        ),
        "trigger_summary": "Senior is 80, 85, 90, 95, or 100 years old.",
        "priority_base": 4,
        "requires_human_validation": True,
        "documents_needed": ["birth certificate", "Senior Citizen ID"],
    },
    "FUNCTIONAL_ADL_SUPPORT": {
        "code": "FUNCTIONAL_ADL_SUPPORT",
        "category": "functional",
        "domain": "Functional Ability",
        "source_id": "WHO_HEALTHY_AGEING",
        "action": (
            "Assess activities of daily living (ADL) limitations and coordinate "
            "caregiver, family, BHW, or MSWDO/CSWDO home-care support review."
        ),
        "reason_template": (
            "Senior shows indicators of limited functional independence, mobility difficulty, "
            "advanced age, or functional risk."
        ),
        "trigger_summary": (
            "Low independence, mobility difficulty, advanced age, or functional risk."
        ),
        "priority_base": 4,
        "requires_human_validation": True,
        "documents_needed": None,
    },
    "FUNCTIONAL_ASSISTIVE_DEVICE": {
        "code": "FUNCTIONAL_ASSISTIVE_DEVICE",
        "category": "functional",
        "domain": "Functional Ability",
        "source_id": "RA_9994",
        "action": (
            "Assess the senior's need for cane, walker, wheelchair, crutches, eyeglasses, "
            "hearing aid, dentures, or other assistive device through OSCA/MSWDO or "
            "PhilHealth benefit claim process."
        ),
        "reason_template": (
            "Senior shows mobility, visual, hearing, dental, sensory, or disability-related "
            "concern that may benefit from an assistive device."
        ),
        "trigger_summary": (
            "Mobility, visual, hearing, dental, sensory, or disability-related concern."
        ),
        "priority_base": 4,
        "requires_human_validation": True,
        "documents_needed": ["Senior Citizen ID", "physician prescription if available"],
    },
    "SOCIAL_PARTICIPATION": {
        "code": "SOCIAL_PARTICIPATION",
        "category": "social",
        "domain": "Environment",
        "source_id": "WHO_HEALTHY_AGEING",
        "action": (
            "Encourage participation in OSCA, Senior Citizen Association, barangay, "
            "faith-based, or community activities based on the senior's preference and capacity."
        ),
        "reason_template": (
            "Senior shows low social participation, loneliness, limited social support, "
            "or is not registered with the Senior Citizen Association."
        ),
        "trigger_summary": (
            "Loneliness, low social support, low participation, or not an association member."
        ),
        "priority_base": 3,
        "requires_human_validation": False,
        "documents_needed": None,
    },
    "SOCIAL_WELFARE_CHECK": {
        "code": "SOCIAL_WELFARE_CHECK",
        "category": "social",
        "domain": "Environment",
        "source_id": "WHO_HEALTHY_AGEING",
        "action": (
            "Assign BHW, OSCA caseworker, barangay official, or MSWDO representative "
            "for periodic welfare check-ins and situation monitoring."
        ),
        "reason_template": (
            "Senior lives alone, has low family support, limited social network, "
            "or poor mobility, increasing welfare risk."
        ),
        "trigger_summary": (
            "Living alone, low family support, low social support, or poor mobility."
        ),
        "priority_base": 4,
        "requires_human_validation": True,
        "documents_needed": None,
    },
    "ACCESS_TRANSPORT": {
        "code": "ACCESS_TRANSPORT",
        "category": "healthcare_access",
        "domain": "Environment",
        "source_id": "DSWD_AICS",
        "action": (
            "Coordinate barangay transport assistance, BHW follow-up, or mobile health clinic "
            "to reduce transportation and distance barriers to healthcare. Refer to AICS if "
            "the transport difficulty is crisis-related."
        ),
        "reason_template": (
            "Senior reports difficulty accessing health services due to distance, "
            "transportation, or related barriers."
        ),
        "trigger_summary": (
            "Distance, transportation difficulty, or difficulty accessing healthcare."
        ),
        "priority_base": 4,
        "requires_human_validation": False,
        "documents_needed": None,
    },
    "LIVELIHOOD_SAFE_REFERRAL": {
        "code": "LIVELIHOOD_SAFE_REFERRAL",
        "category": "livelihood",
        "domain": "Environment",
        "source_id": "DOLE_KABUHAYAN",
        "action": (
            "Assess the senior's willingness and physical capacity for livelihood support "
            "through DSWD SLP, DOLE Kabuhayan/DILP, or TESDA skills training — "
            "proceed only if the senior is physically and medically appropriate."
        ),
        "reason_template": (
            "Senior has low income, possible livelihood interest, and functional indicators "
            "that do not suggest contraindication to light livelihood activity."
        ),
        "trigger_summary": (
            "Low income, has skills or livelihood interest, and functional condition allows participation."
        ),
        "priority_base": 3,
        "requires_human_validation": True,
        "documents_needed": ["valid ID", "barangay clearance"],
    },
    "MENTAL_HEALTH_REFERRAL": {
        "code": "MENTAL_HEALTH_REFERRAL",
        "category": "mental_health",
        "domain": "Intrinsic Capacity",
        "source_id": "RA_11036",
        "action": (
            "Refer the senior to the RHU or a qualified mental health professional for "
            "assessment of reported emotional or psychosocial concern. "
            "If crisis indicators are present, provide NCMH crisis hotline information (1553)."
        ),
        "reason_template": (
            "Senior has reported emotional difficulty, possible psychosocial concern, or indicators "
            "associated with loneliness or low wellbeing — this is a decision-support flag, "
            "not a clinical diagnosis."
        ),
        "trigger_summary": (
            "Reported emotional concern, loneliness, depression, anxiety, or low psychosocial wellbeing."
        ),
        "priority_base": 5,
        "requires_human_validation": True,
        "documents_needed": None,
    },
    # ── Health ────────────────────────────────────────────────────────────────
    "HLT-001": {
        "code": "HLT-001",
        "trigger_summary": 'No recorded regular check-up, high medical risk, or age >= 70.',
        "category": "health",
        "source_id": "PHILHEALTH_YAKAP",
        "action": (
            "Verify the senior's PhilHealth membership and selected YAKAP/Konsulta clinic "
            "through OSCA or PhilHealth LHIO, then coordinate scheduling of a primary-care consultation."
        ),
        "reason_template": (
            "Senior has no recorded regular check-up or presents health risk indicators "
            "that warrant primary care review."
        ),
        "eligibility_trigger": "no_checkup OR high_medical_risk OR age >= 70",
        "requires_human_validation": True,
        "documents_needed": ["PhilHealth ID or MDR", "Senior Citizen ID"],
    },
    "HLT-002": {
        "code": "HLT-002",
        "trigger_summary": 'Reported hypertension or high blood pressure.',
        "category": "health",
        "source_id": "DOH_PHILPEN",
        "action": (
            "Refer the senior to the RHU/YAKAP clinic for blood pressure monitoring "
            "and physician-led medication review."
        ),
        "reason_template": (
            "Senior has reported hypertension or high blood pressure as a medical concern."
        ),
        "eligibility_trigger": "hypertension OR high_blood_pressure in medical_concern",
        "requires_human_validation": True,
        "documents_needed": None,
    },
    "HLT-003": {
        "code": "HLT-003",
        "trigger_summary": 'Reported diabetes or blood sugar concern.',
        "category": "health",
        "source_id": "DOH_PHILPEN",
        "action": (
            "Refer the senior for blood sugar monitoring and physician-led diabetes follow-up "
            "through RHU/YAKAP services."
        ),
        "reason_template": "Senior has reported diabetes as a medical concern.",
        "eligibility_trigger": "diabetes in medical_concern",
        "requires_human_validation": True,
        "documents_needed": None,
    },
    "HLT-004": {
        "code": "HLT-004",
        "trigger_summary": 'Difficulty with medicine costs or high medical financial burden.',
        "category": "health",
        "source_id": "PHILHEALTH_YAKAP",
        "action": (
            "Check YAKAP/GAMOT medicine availability and remind the senior to use the "
            "senior citizen medicine discount for prescribed medicines."
        ),
        "reason_template": (
            "Senior reports difficulty with medicine costs or high medical financial burden."
        ),
        "eligibility_trigger": "high_medicine_cost OR env_fin_medical <= 2",
        "requires_human_validation": True,
        "documents_needed": ["Senior Citizen ID", "prescription"],
    },
    "HLT-005": {
        "code": "HLT-005",
        "trigger_summary": 'Reported dental concern.',
        "category": "health",
        "source_id": "RA_9994",
        "action": (
            "Refer to RHU/public dental service or accredited dental provider and remind "
            "the senior of the applicable senior citizen dental-service discounts."
        ),
        "reason_template": "Senior has reported dental concern.",
        "eligibility_trigger": "dental_concern present",
        "requires_human_validation": True,
        "documents_needed": ["Senior Citizen ID"],
    },
    "HLT-006": {
        "code": "HLT-006",
        "trigger_summary": 'Reported optical or vision concern.',
        "category": "health",
        "source_id": "RA_9994",
        "action": (
            "Refer for eye screening and assess need for eyeglasses or cataract-related "
            "assistance through RHU or OSCA/MSWDO."
        ),
        "reason_template": "Senior has reported optical or vision concern.",
        "eligibility_trigger": "optical_concern present",
        "requires_human_validation": True,
        "documents_needed": ["Senior Citizen ID"],
    },
    "HLT-007": {
        "code": "HLT-007",
        "trigger_summary": 'Reported hearing concern.',
        "category": "health",
        "source_id": "RA_9994",
        "action": (
            "Refer for hearing assessment and check eligibility for hearing aid discount "
            "or assistance through RHU or OSCA/MSWDO."
        ),
        "reason_template": "Senior has reported hearing concern.",
        "eligibility_trigger": "hearing_concern present",
        "requires_human_validation": True,
        "documents_needed": ["Senior Citizen ID"],
    },
    "HLT-008": {
        "code": "HLT-008",
        "trigger_summary": 'Mobility difficulty or fall/frailty risk indicators.',
        "category": "health",
        "source_id": "WHO_HEALTHY_AGEING",
        "action": (
            "Request fall-risk and home-safety assessment, and assess need for cane, walker, "
            "wheelchair, or other assistive device through BHW/RHU/OSCA."
        ),
        "reason_template": (
            "Senior presents mobility difficulty or fall/frailty risk indicators."
        ),
        "eligibility_trigger": "poor_mobility OR functional_risk_high",
        "requires_human_validation": True,
        "documents_needed": None,
    },
    # ── Financial ─────────────────────────────────────────────────────────────
    "FIN-001": {
        "code": "FIN-001",
        "trigger_summary": 'No pension with low-income or indigent indicators.',
        "category": "financial",
        "source_id": "SOCIAL_PENSION",
        "action": (
            "Refer for Social Pension validation through OSCA/MSWDO/NCSC/DSWD — assist the "
            "senior in completing the required intake form and supporting documents."
        ),
        "reason_template": (
            "Senior has no recorded pension and meets indicators associated with indigent "
            "or low-income status."
        ),
        "eligibility_trigger": "no_pension AND (low_income OR eco_stability < 0.35)",
        "requires_human_validation": True,
        "documents_needed": [
            "Senior Citizen ID",
            "barangay certificate of indigency",
            "birth certificate",
        ],
    },
    "FIN-002": {
        "code": "FIN-002",
        "trigger_summary": 'Financial difficulty meeting medical or household needs.',
        "category": "financial",
        "source_id": "DSWD_AICS",
        "action": (
            "Refer to DSWD AICS through the MSWDO/CSWDO for social-worker assessment of "
            "possible medical, food, transportation, or emergency financial assistance."
        ),
        "reason_template": (
            "Senior presents indicators of financial difficulty in meeting medical or household needs."
        ),
        "eligibility_trigger": "eco_stability < 0.35 OR env_fin_medical <= 2 OR env_fin_household <= 2",
        "requires_human_validation": True,
        "documents_needed": ["valid ID", "barangay certification"],
    },
    "FIN-003": {
        "code": "FIN-003",
        "trigger_summary": 'Difficulty with hospital or major medical expenses.',
        "category": "financial",
        "source_id": "MALASAKIT_CENTER",
        "action": (
            "Assist the senior in preparing documents for Malasakit Center or PCSO Medical "
            "Assistance Program (MAP) for hospital bill reduction."
        ),
        "reason_template": (
            "Senior presents indicators of difficulty with hospital or medical expenses."
        ),
        "eligibility_trigger": "high medical cost AND low income",
        "requires_human_validation": True,
        "documents_needed": [
            "hospital bill/medical certificate",
            "PhilHealth MDR",
            "Senior Citizen ID",
            "DSWD certificate of indigency if applicable",
        ],
    },
    "FIN-004": {
        "code": "FIN-004",
        "trigger_summary": 'Moderate-to-high financial risk warranting PhilHealth check.',
        "category": "financial",
        "action": (
            "Verify active PhilHealth membership and check benefit utilization — assist the "
            "senior in updating records if needed."
        ),
        "reason_template": (
            "Senior may benefit from PhilHealth coverage verification to ensure outpatient "
            "and inpatient benefits are accessible."
        ),
        "source_id": "PHILHEALTH_UHC",
        "eligibility_trigger": "moderate_or_high_financial_risk",
        "requires_human_validation": True,
        "documents_needed": ["PhilHealth ID or MDR"],
    },
    "FIN-005": {
        "code": "FIN-005",
        "trigger_summary": 'Senior is 80, 85, 90, 95, or 100 years old.',
        "category": "financial",
        "action": (
            "Check eligibility for Expanded Centenarians Act cash gift and assist with "
            "document preparation through OSCA/MSWDO/NCSC."
        ),
        "reason_template": (
            "Senior is approaching or has reached a centenarian milestone age "
            "(80, 85, 90, 95, or 100)."
        ),
        "source_id": "RA_11982",
        "eligibility_trigger": "age in [80, 85, 90, 95, 100]",
        "requires_human_validation": True,
        "documents_needed": ["birth certificate", "Senior Citizen ID"],
    },
    "FIN-006": {
        "code": "FIN-006",
        "trigger_summary": 'Disability or severe functional limitation indicators.',
        "category": "financial",
        "action": (
            "Refer to PDAO/MSWDO for PWD assessment if applicable; remind staff that senior "
            "and PWD discounts cannot be double-claimed on the same transaction."
        ),
        "reason_template": (
            "Senior presents disability or severe functional limitation indicators."
        ),
        "source_id": "RA_7277",
        "eligibility_trigger": "physical_disability OR severe_functional_limitation",
        "requires_human_validation": True,
        "documents_needed": ["PWD ID if available", "medical certificate"],
    },
    # ── Livelihood ────────────────────────────────────────────────────────────
    "LVL-001": {
        "code": "LVL-001",
        "trigger_summary": 'Low income with skills/interest and no functional contraindication.',
        "category": "livelihood",
        "action": (
            "Assess willingness and physical capacity for livelihood support through DSWD SLP, "
            "DOLE Kabuhayan/DILP, or TESDA skills training — proceed only if the senior is "
            "physically and medically appropriate."
        ),
        "reason_template": (
            "Senior has livelihood skills and income need, and functional indicators do not "
            "suggest contraindication to light livelihood activity."
        ),
        "source_id": "DOLE_KABUHAYAN",
        "eligibility_trigger": "has_skills AND wants_income AND functional_risk NOT high AND NOT frail",
        "requires_human_validation": True,
        "documents_needed": ["valid ID", "barangay clearance"],
    },
    # ── Social / Emotional ────────────────────────────────────────────────────
    "SOC-001": {
        "code": "SOC-001",
        "trigger_summary": 'Lives alone or limited family support (isolation risk).',
        "category": "social",
        "action": (
            "Assign barangay health worker (BHW) or OSCA/MSWDO caseworker for periodic welfare "
            "check-ins and monitor the senior's social situation."
        ),
        "reason_template": (
            "Senior lives alone or reports limited family support, presenting indicators of "
            "social isolation risk."
        ),
        "source_id": "BHW_COMMUNITY",
        "eligibility_trigger": "lives_alone OR low_family_support",
        "requires_human_validation": True,
        "documents_needed": None,
    },
    "SOC-002": {
        "code": "SOC-002",
        "trigger_summary": 'Not registered with the Senior Citizen Association.',
        "category": "social",
        "action": (
            "Encourage registration or participation in the local Senior Citizen Association "
            "or OSCA activities for social engagement and peer support."
        ),
        "reason_template": (
            "Senior is not currently registered with the Senior Citizen Association."
        ),
        "source_id": "RA_9994",
        "eligibility_trigger": "NOT is_association_member",
        "requires_human_validation": False,
        "documents_needed": None,
    },
    "SOC-003": {
        "code": "SOC-003",
        "trigger_summary": 'Low social support, no close friend, or low participation.',
        "category": "social",
        "action": (
            "Encourage participation in OSCA, barangay, faith-based, or senior group activities "
            "based on the senior's interest and capacity."
        ),
        "reason_template": (
            "Senior shows low social participation or reports loneliness/isolation."
        ),
        "source_id": "WHO_HEALTHY_AGEING",
        "eligibility_trigger": "low_social_support OR low_close_friend OR low_social_participation",
        "requires_human_validation": False,
        "documents_needed": None,
    },
    "SOC-004": {
        "code": "SOC-004",
        "trigger_summary": 'Reported emotional concern, loneliness, or low wellbeing.',
        "category": "mental_health",
        "action": (
            "Refer the senior to the RHU or a qualified mental health professional for assessment "
            "of reported emotional or psychosocial concern. If crisis indicators are present, "
            "provide NCMH crisis hotline information (1553)."
        ),
        "reason_template": (
            "Senior has reported emotional difficulty, possible psychosocial concern, or indicators "
            "associated with loneliness or low wellbeing — this is a decision-support flag, "
            "not a clinical diagnosis."
        ),
        "source_id": "RA_11036",
        "eligibility_trigger": (
            "depression OR anxiety OR hopelessness in social_emotional_concern OR low_wellbeing"
        ),
        "requires_human_validation": True,
        "documents_needed": None,
    },
    "SOC-005": {
        "code": "SOC-005",
        "trigger_summary": 'Limited family support affecting wellbeing and care.',
        "category": "social",
        "action": (
            "Conduct family support assessment to review caregiver capacity, identify caregiver "
            "stress, and coordinate additional support if needed."
        ),
        "reason_template": (
            "Senior has limited family support which may affect wellbeing and care."
        ),
        "source_id": "WHO_HEALTHY_AGEING",
        "eligibility_trigger": "low_family_support",
        "requires_human_validation": True,
        "documents_needed": None,
    },
    # ── Functional / Accessibility ────────────────────────────────────────────
    "FNC-001": {
        "code": "FNC-001",
        "trigger_summary": 'Limited functional independence or difficulty with daily activities.',
        "category": "functional",
        "action": (
            "Assess ADL (activities of daily living) limitations and coordinate home-care support "
            "or caregiver/family support review through MSWDO or BHW."
        ),
        "reason_template": (
            "Senior shows indicators of limited functional independence or difficulty with daily activities."
        ),
        "source_id": "WHO_HEALTHY_AGEING",
        "eligibility_trigger": "low_func_independence OR high_functional_risk",
        "requires_human_validation": True,
        "documents_needed": None,
    },
    "FNC-002": {
        "code": "FNC-002",
        "trigger_summary": 'Aged 80+ (risk of functional decline, polypharmacy, falls).',
        "category": "functional",
        "source_id": "WHO_HEALTHY_AGEING",
        "action": (
            "Refer the senior to the RHU for a physician-led geriatric review, including "
            "assessment of medication burden and fall-prevention counseling."
        ),
        "reason_template": (
            "Senior is aged 80 or older, which is associated with increased risk of "
            "functional decline, polypharmacy, and falls."
        ),
        "eligibility_trigger": "age >= 80",
        "requires_human_validation": True,
        "documents_needed": [
            "Senior Citizen ID",
            "existing prescription list if available",
        ],
    },
    "FNC-003": {
        "code": "FNC-003",
        "trigger_summary": 'Transport/distance barrier or low service access.',
        "category": "healthcare_access",
        "source_id": "DSWD_AICS",
        "action": (
            "Coordinate barangay transport assistance, BHW follow-up visit, or scheduled "
            "RHU/mobile health clinic to address transportation or distance barriers to healthcare."
        ),
        "reason_template": (
            "Senior reports difficulty accessing health services due to transportation, distance, or cost."
        ),
        "eligibility_trigger": "transport_difficulty OR low_service_access",
        "requires_human_validation": False,
        "documents_needed": None,
    },
    "FNC-004": {
        "code": "FNC-004",
        "trigger_summary": 'Unsafe home or housing-safety concern.',
        "category": "healthcare_access",
        "source_id": "LGU_SOCIAL_WELFARE",
        "action": (
            "Refer to MSWDO/barangay for social case assessment, home-safety review, "
            "and vulnerable-sector monitoring."
        ),
        "reason_template": (
            "Senior's home or housing situation presents safety or vulnerability concerns."
        ),
        "eligibility_trigger": "unsafe_home OR housing_concern",
        "requires_human_validation": True,
        "documents_needed": None,
    },
    "FNC-005": {
        "code": "FNC-005",
        "trigger_summary": 'Limited health service access (low accessibility score).',
        "category": "healthcare_access",
        "source_id": "BHW_COMMUNITY",
        "action": (
            "Coordinate barangay health worker (BHW) for home-based health monitoring "
            "and regular follow-up visits."
        ),
        "reason_template": (
            "Senior has limited access to health services or low service accessibility score."
        ),
        "eligibility_trigger": "low_service_access",
        "requires_human_validation": False,
        "documents_needed": None,
    },
    # ── Functional (continued) ────────────────────────────────────────────────
    "FNC-006": {
        "code": "FNC-006",
        "trigger_summary": 'Fall-risk indicators from mobility/functional score and age.',
        "category": "functional",
        "action": (
            "Conduct a home-based fall-risk assessment with the BHW/RHU and review "
            "the living environment for fall hazards (loose rugs, poor lighting, "
            "uneven floors). Recommend corrective measures."
        ),
        "reason_template": (
            "Senior presents fall-risk indicators based on mobility or functional "
            "ability scores and age."
        ),
        "source_id": "WHO_HEALTHY_AGEING",
        "eligibility_trigger": "moderate_mobility_impairment OR age >= 75 AND functional_risk",
        "requires_human_validation": True,
        "documents_needed": None,
    },
    "FNC-007": {
        "code": "FNC-007",
        "trigger_summary": 'Functional or sensory limitation that may need an assistive device.',
        "category": "functional",
        "action": (
            "Assess eligibility for assistive device support (cane, walker, wheelchair, "
            "hearing aid, or eyeglasses) through OSCA/MSWDO or PhilHealth benefit claims. "
            "Assist with prescription and procurement process."
        ),
        "reason_template": (
            "Senior shows functional or sensory limitation indicators that may benefit from "
            "an assistive device — assessment and access facilitation recommended."
        ),
        "source_id": "RA_9994",
        "eligibility_trigger": (
            "mobility_impairment OR hearing_concern OR optical_concern "
            "OR functional_risk_moderate"
        ),
        "requires_human_validation": True,
        "documents_needed": ["Senior Citizen ID", "physician prescription if available"],
    },
    # ── Household Safety ─────────────────────────────────────────────────────
    "HSF-001": {
        "code": "HSF-001",
        "trigger_summary": 'Unsafe home, poor household condition, or housing concern.',
        "category": "healthcare_access",
        "source_id": "LGU_SOCIAL_WELFARE",
        "action": (
            "Refer to MSWDO/barangay for a home-safety assessment to identify fall hazards "
            "and structural safety concerns. Coordinate with MDRRMO if the location is in "
            "a high-risk area."
        ),
        "reason_template": (
            "Senior lives in a home with reported safety or structural concerns that may "
            "increase fall, injury, or disaster risk."
        ),
        "eligibility_trigger": "unsafe_home OR poor_household_condition OR housing_concern",
        "requires_human_validation": True,
        "documents_needed": None,
    },
    "HSF-002": {
        "code": "HSF-002",
        "trigger_summary": 'Structurally unsafe home with low income.',
        "category": "healthcare_access",
        "source_id": "DSWD_AICS",
        "action": (
            "Assist the senior in applying for housing or home-improvement assistance "
            "through MSWDO, NHA, or DSWD emergency housing programs, if structurally unsafe."
        ),
        "reason_template": (
            "Senior's home condition presents structural safety risks requiring "
            "housing support or improvement assistance."
        ),
        "eligibility_trigger": "structurally_unsafe_home AND low_income",
        "requires_human_validation": True,
        "documents_needed": [
            "valid ID", "barangay certification", "proof of home ownership or tenancy"
        ],
    },
    # ── Social (continued) ────────────────────────────────────────────────────
    "SOC-006": {
        "code": "SOC-006",
        "trigger_summary": 'Low community participation or limited opportunities/respect.',
        "category": "social",
        "action": (
            "Coordinate with OSCA or barangay to facilitate active participation in senior "
            "group activities, wellness programs, or volunteer engagements tailored to the "
            "senior's interests and physical capacity."
        ),
        "reason_template": (
            "Senior shows low community participation indicators. Regular social engagement "
            "supports psychological wellbeing and functional ability in older adults."
        ),
        "source_id": "WHO_HEALTHY_AGEING",
        "eligibility_trigger": "low_soc_participation OR low_community_opportunities",
        "requires_human_validation": False,
        "documents_needed": None,
    },
    "SOC-007": {
        "code": "SOC-007",
        "trigger_summary": 'Widowed or separated with low social support.',
        "category": "social",
        "action": (
            "Connect the senior with OSCA peer support groups, faith-based community "
            "programmes, or referral to RHU/mental health services for grief or loss "
            "adjustment support."
        ),
        "reason_template": (
            "Senior is widowed or separated, which is associated with elevated social "
            "isolation and psychosocial adjustment needs."
        ),
        "source_id": "WHO_HEALTHY_AGEING",
        "eligibility_trigger": "marital_status in ['Widowed', 'Separated'] AND low_social_support",
        "requires_human_validation": False,
        "documents_needed": None,
    },
    # ── Healthcare Access (additional) ───────────────────────────────────────
    "HCA-001": {
        "code": "HCA-001",
        "trigger_summary": 'Chronic condition(s) with no recorded regular check-up.',
        "category": "healthcare_access",
        "source_id": "PHILHEALTH_YAKAP",
        "action": (
            "Facilitate scheduling of a primary-care follow-up visit at RHU/YAKAP clinic "
            "and assign BHW to confirm attendance. Prioritise seniors with chronic conditions "
            "who have no recent recorded check-up."
        ),
        "reason_template": (
            "Senior has one or more chronic conditions but no recorded regular check-up, "
            "indicating a healthcare-access gap requiring immediate coordination."
        ),
        "eligibility_trigger": "no_checkup AND has_chronic_condition",
        "requires_human_validation": True,
        "documents_needed": ["PhilHealth ID or MDR", "Senior Citizen ID"],
    },
}


def get_rule(code: str) -> dict:
    """Return a copy of a rule by code, or empty dict if not found."""
    return dict(RECOMMENDATION_RULES.get(code, {}))


def build_rec_from_rule(
    code: str,
    reason_override: str = None,
    priority: int = 1,
    urgency: str = "planned",
    risk_level: str = "moderate",
) -> dict:
    """
    Build a recommendation dict from a rule code ready for JSON output.

    For rules that declare a ``source_id``, the citation fields
    (service_provider, evidence_source, apa_reference, source_type) are
    automatically merged from EVIDENCE_SOURCES — no need to duplicate them
    on the rule itself.

    Returns an empty dict if the code is not found.
    """
    rule = get_rule(code)
    if not rule:
        return {}

    # Resolve evidence source: source_id lookup takes precedence over inline fields
    source_id = rule.get("source_id")
    evidence = EVIDENCE_SOURCES.get(source_id, {}) if source_id else {}

    service_provider = (
        evidence.get("service_provider")
        or rule.get("service_provider", "")
    )
    evidence_source = (
        evidence.get("evidence_source")
        or rule.get("evidence_source", "")
    )
    apa_reference  = evidence.get("apa_reference", "")
    source_type    = evidence.get("source_type", "")

    reason = (
        reason_override
        or rule.get("reason_template", "")
        or rule.get("trigger_summary", "")
    )

    return {
        "priority":                 priority,
        "type":                     "domain",
        # domain = WHO Healthy Ageing domain (Intrinsic Capacity / Environment /
        #          Functional Ability); falls back to category for legacy rules.
        "domain":                   rule.get("domain", rule.get("category", "general")),
        "category":                 rule.get("category", "general"),
        "action":                   rule["action"],
        "urgency":                  urgency,
        "risk_level":               risk_level,
        "reason":                   reason,
        "service_provider":         service_provider,
        "evidence_source":          evidence_source,
        "apa_reference":            apa_reference,
        "source_type":              source_type,
        "recommendation_code":      rule.get("code", code),
        # trigger_summary: concise human-readable description of what fired this
        # rule. New-format rules declare it explicitly; legacy rules fall back to
        # the reason_template so the field is never empty for any coded rec.
        "trigger_summary":          rule.get("trigger_summary") or rule.get("reason_template", ""),
        "requires_human_validation": rule.get("requires_human_validation", True),
        "documents_needed":         rule.get("documents_needed"),
    }
