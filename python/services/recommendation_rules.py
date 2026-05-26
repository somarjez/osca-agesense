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
"""

RECOMMENDATION_RULES = {
    # ── Health ────────────────────────────────────────────────────────────────
    "HLT-001": {
        "code": "HLT-001",
        "category": "health",
        "action": (
            "Verify the senior's PhilHealth membership and selected YAKAP/Konsulta clinic "
            "through OSCA or PhilHealth LHIO, then coordinate scheduling of a primary-care consultation."
        ),
        "reason_template": (
            "Senior has no recorded regular check-up or presents health risk indicators "
            "that warrant primary care review."
        ),
        "service_provider": "PhilHealth YAKAP/Konsulta, RHU/BHC, OSCA",
        "evidence_source": "PhilHealth YAKAP/Konsulta primary care services",
        "eligibility_trigger": "no_checkup OR high_medical_risk OR age >= 70",
        "requires_human_validation": True,
        "documents_needed": ["PhilHealth ID or MDR", "Senior Citizen ID"],
    },
    "HLT-002": {
        "code": "HLT-002",
        "category": "health",
        "action": (
            "Refer the senior to the RHU/YAKAP clinic for blood pressure monitoring "
            "and physician-led medication review."
        ),
        "reason_template": (
            "Senior has reported hypertension or high blood pressure as a medical concern."
        ),
        "service_provider": "RHU/BHC, PhilHealth YAKAP/Konsulta",
        "evidence_source": "DOH PhilPEN / primary care hypertension management",
        "eligibility_trigger": "hypertension OR high_blood_pressure in medical_concern",
        "requires_human_validation": True,
        "documents_needed": None,
    },
    "HLT-003": {
        "code": "HLT-003",
        "category": "health",
        "action": (
            "Refer the senior for blood sugar monitoring and physician-led diabetes follow-up "
            "through RHU/YAKAP services."
        ),
        "reason_template": "Senior has reported diabetes as a medical concern.",
        "service_provider": "RHU/BHC, PhilHealth YAKAP/Konsulta",
        "evidence_source": "PhilHealth Konsulta/YAKAP diagnostic services",
        "eligibility_trigger": "diabetes in medical_concern",
        "requires_human_validation": True,
        "documents_needed": None,
    },
    "HLT-004": {
        "code": "HLT-004",
        "category": "health",
        "action": (
            "Check YAKAP/GAMOT medicine availability and remind the senior to use the "
            "senior citizen medicine discount for prescribed medicines."
        ),
        "reason_template": (
            "Senior reports difficulty with medicine costs or high medical financial burden."
        ),
        "service_provider": "PhilHealth YAKAP/GAMOT, OSCA, pharmacy/RHU",
        "evidence_source": "PhilHealth YAKAP/GAMOT; Expanded Senior Citizens Act benefits",
        "eligibility_trigger": "high_medicine_cost OR env_fin_medical <= 2",
        "requires_human_validation": True,
        "documents_needed": ["Senior Citizen ID", "prescription"],
    },
    "HLT-005": {
        "code": "HLT-005",
        "category": "health",
        "action": (
            "Refer to RHU/public dental service or accredited dental provider and remind "
            "the senior of the applicable senior citizen dental-service discounts."
        ),
        "reason_template": "Senior has reported dental concern.",
        "service_provider": "RHU/public dental service, OSCA",
        "evidence_source": "Senior citizen discount/VAT exemption rules",
        "eligibility_trigger": "dental_concern present",
        "requires_human_validation": True,
        "documents_needed": ["Senior Citizen ID"],
    },
    "HLT-006": {
        "code": "HLT-006",
        "category": "health",
        "action": (
            "Refer for eye screening and assess need for eyeglasses or cataract-related "
            "assistance through RHU or OSCA/MSWDO."
        ),
        "reason_template": "Senior has reported optical or vision concern.",
        "service_provider": "RHU, public hospital, OSCA/MSWDO",
        "evidence_source": "Senior citizen medical supplies/equipment discount coverage",
        "eligibility_trigger": "optical_concern present",
        "requires_human_validation": True,
        "documents_needed": ["Senior Citizen ID"],
    },
    "HLT-007": {
        "code": "HLT-007",
        "category": "health",
        "action": (
            "Refer for hearing assessment and check eligibility for hearing aid discount "
            "or assistance through RHU or OSCA/MSWDO."
        ),
        "reason_template": "Senior has reported hearing concern.",
        "service_provider": "RHU/public hospital, OSCA/MSWDO",
        "evidence_source": "Senior citizen assistive device/medical supply benefits",
        "eligibility_trigger": "hearing_concern present",
        "requires_human_validation": True,
        "documents_needed": ["Senior Citizen ID"],
    },
    "HLT-008": {
        "code": "HLT-008",
        "category": "health",
        "action": (
            "Request fall-risk and home-safety assessment, and assess need for cane, walker, "
            "wheelchair, or other assistive device through BHW/RHU/OSCA."
        ),
        "reason_template": (
            "Senior presents mobility difficulty or fall/frailty risk indicators."
        ),
        "service_provider": "BHW/RHU/MSWDO/OSCA",
        "evidence_source": (
            "Senior citizen assistive-device benefits and healthy ageing functional ability support"
        ),
        "eligibility_trigger": "poor_mobility OR functional_risk_high",
        "requires_human_validation": True,
        "documents_needed": None,
    },
    # ── Financial ─────────────────────────────────────────────────────────────
    "FIN-001": {
        "code": "FIN-001",
        "category": "financial",
        "action": (
            "Refer for Social Pension validation through OSCA/MSWDO/NCSC/DSWD — assist the "
            "senior in completing the required intake form and supporting documents."
        ),
        "reason_template": (
            "Senior has no recorded pension and meets indicators associated with indigent "
            "or low-income status."
        ),
        "service_provider": "OSCA, MSWDO/CSWDO, DSWD/NCSC",
        "evidence_source": "Social Pension for Indigent Senior Citizens (RA 9994 as amended)",
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
        "category": "financial",
        "action": (
            "Refer to DSWD AICS through the MSWDO/CSWDO for social-worker assessment of "
            "possible medical, food, transportation, or emergency financial assistance."
        ),
        "reason_template": (
            "Senior presents indicators of financial difficulty in meeting medical or household needs."
        ),
        "service_provider": "DSWD AICS, MSWDO/CSWDO",
        "evidence_source": "Assistance to Individuals in Crisis Situation (AICS)",
        "eligibility_trigger": "eco_stability < 0.35 OR env_fin_medical <= 2 OR env_fin_household <= 2",
        "requires_human_validation": True,
        "documents_needed": ["valid ID", "barangay certification"],
    },
    "FIN-003": {
        "code": "FIN-003",
        "category": "financial",
        "action": (
            "Assist the senior in preparing documents for Malasakit Center or PCSO Medical "
            "Assistance Program (MAP) for hospital bill reduction."
        ),
        "reason_template": (
            "Senior presents indicators of difficulty with hospital or medical expenses."
        ),
        "service_provider": "Malasakit Center, PCSO MAP, MSWDO/OSCA",
        "evidence_source": (
            "Malasakit Center Act (RA 11463); PCSO Medical Assistance Program"
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
        "category": "financial",
        "action": (
            "Verify active PhilHealth membership and check benefit utilization — assist the "
            "senior in updating records if needed."
        ),
        "reason_template": (
            "Senior may benefit from PhilHealth coverage verification to ensure outpatient "
            "and inpatient benefits are accessible."
        ),
        "service_provider": "PhilHealth LHIO, OSCA",
        "evidence_source": "PhilHealth Universal Health Care benefits",
        "eligibility_trigger": "moderate_or_high_financial_risk",
        "requires_human_validation": True,
        "documents_needed": ["PhilHealth ID or MDR"],
    },
    "FIN-005": {
        "code": "FIN-005",
        "category": "financial",
        "action": (
            "Check eligibility for Expanded Centenarians Act cash gift and assist with "
            "document preparation through OSCA/MSWDO/NCSC."
        ),
        "reason_template": (
            "Senior is approaching or has reached a centenarian milestone age "
            "(80, 85, 90, 95, or 100)."
        ),
        "service_provider": "OSCA/MSWDO/NCSC",
        "evidence_source": "Expanded Centenarians Act (RA 10868)",
        "eligibility_trigger": "age in [80, 85, 90, 95, 100]",
        "requires_human_validation": True,
        "documents_needed": ["birth certificate", "Senior Citizen ID"],
    },
    "FIN-006": {
        "code": "FIN-006",
        "category": "financial",
        "action": (
            "Refer to PDAO/MSWDO for PWD assessment if applicable; remind staff that senior "
            "and PWD discounts cannot be double-claimed on the same transaction."
        ),
        "reason_template": (
            "Senior presents disability or severe functional limitation indicators."
        ),
        "service_provider": "PDAO, MSWDO/CSWDO, OSCA",
        "evidence_source": (
            "PWD benefit rules (RA 7277 as amended) and Senior Citizens Act (RA 9994)"
        ),
        "eligibility_trigger": "physical_disability OR severe_functional_limitation",
        "requires_human_validation": True,
        "documents_needed": ["PWD ID if available", "medical certificate"],
    },
    # ── Livelihood ────────────────────────────────────────────────────────────
    "LVL-001": {
        "code": "LVL-001",
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
        "service_provider": "DSWD SLP, DOLE, TESDA, MSWDO",
        "evidence_source": (
            "DSWD Sustainable Livelihood Program; DOLE Integrated Livelihood Program (DILP); "
            "TESDA inclusive skills training"
        ),
        "eligibility_trigger": "has_skills AND wants_income AND functional_risk NOT high AND NOT frail",
        "requires_human_validation": True,
        "documents_needed": ["valid ID", "barangay clearance"],
    },
    # ── Social / Emotional ────────────────────────────────────────────────────
    "SOC-001": {
        "code": "SOC-001",
        "category": "social",
        "action": (
            "Assign barangay health worker (BHW) or OSCA/MSWDO caseworker for periodic welfare "
            "check-ins and monitor the senior's social situation."
        ),
        "reason_template": (
            "Senior lives alone or reports limited family support, presenting indicators of "
            "social isolation risk."
        ),
        "service_provider": "BHW, OSCA, MSWDO/CSWDO",
        "evidence_source": "Barangay health worker and community support mandate",
        "eligibility_trigger": "lives_alone OR low_family_support",
        "requires_human_validation": True,
        "documents_needed": None,
    },
    "SOC-002": {
        "code": "SOC-002",
        "category": "social",
        "action": (
            "Encourage registration or participation in the local Senior Citizen Association "
            "or OSCA activities for social engagement and peer support."
        ),
        "reason_template": (
            "Senior is not currently registered with the Senior Citizen Association."
        ),
        "service_provider": "OSCA/Senior Citizen Association",
        "evidence_source": "Senior citizen community participation mandate (RA 9994)",
        "eligibility_trigger": "NOT is_association_member",
        "requires_human_validation": False,
        "documents_needed": None,
    },
    "SOC-003": {
        "code": "SOC-003",
        "category": "social",
        "action": (
            "Encourage participation in OSCA, barangay, faith-based, or senior group activities "
            "based on the senior's interest and capacity."
        ),
        "reason_template": (
            "Senior shows low social participation or reports loneliness/isolation."
        ),
        "service_provider": "OSCA, barangay, Senior Citizen Association",
        "evidence_source": "WHO Healthy Ageing — social participation and environment",
        "eligibility_trigger": "low_social_support OR low_close_friend OR low_social_participation",
        "requires_human_validation": False,
        "documents_needed": None,
    },
    "SOC-004": {
        "code": "SOC-004",
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
        "service_provider": "RHU, mental health professional, NCMH",
        "evidence_source": "National Mental Health Act (RA 11036); NCMH crisis support",
        "eligibility_trigger": (
            "depression OR anxiety OR hopelessness in social_emotional_concern OR low_wellbeing"
        ),
        "requires_human_validation": True,
        "documents_needed": None,
    },
    "SOC-005": {
        "code": "SOC-005",
        "category": "social",
        "action": (
            "Conduct family support assessment to review caregiver capacity, identify caregiver "
            "stress, and coordinate additional support if needed."
        ),
        "reason_template": (
            "Senior has limited family support which may affect wellbeing and care."
        ),
        "service_provider": "MSWDO/CSWDO, OSCA, BHW",
        "evidence_source": "Community-based care and family support for older persons",
        "eligibility_trigger": "low_family_support",
        "requires_human_validation": True,
        "documents_needed": None,
    },
    # ── Functional / Accessibility ────────────────────────────────────────────
    "FNC-001": {
        "code": "FNC-001",
        "category": "functional",
        "action": (
            "Assess ADL (activities of daily living) limitations and coordinate home-care support "
            "or caregiver/family support review through MSWDO or BHW."
        ),
        "reason_template": (
            "Senior shows indicators of limited functional independence or difficulty with daily activities."
        ),
        "service_provider": "MSWDO/CSWDO, BHW, RHU, OSCA",
        "evidence_source": "Home health care and functional ability support for older persons",
        "eligibility_trigger": "low_func_independence OR high_functional_risk",
        "requires_human_validation": True,
        "documents_needed": None,
    },
    "FNC-002": {
        "code": "FNC-002",
        "category": "functional",
        "action": (
            "Schedule a comprehensive geriatric assessment with a physician; review for "
            "polypharmacy (concurrent use of 5+ medications) and fall risk."
        ),
        "reason_template": (
            "Senior is aged 80 or older, which is associated with increased risk of "
            "functional decline, polypharmacy, and falls."
        ),
        "service_provider": "RHU/hospital geriatric service, PhilHealth YAKAP/Konsulta",
        "evidence_source": "WHO Healthy Ageing; geriatric care guidelines",
        "eligibility_trigger": "age >= 80",
        "requires_human_validation": True,
        "documents_needed": [
            "Senior Citizen ID",
            "existing prescription list if available",
        ],
    },
    "FNC-003": {
        "code": "FNC-003",
        "category": "hc_access",
        "action": (
            "Coordinate barangay transport assistance, BHW follow-up visit, or scheduled "
            "RHU/mobile health clinic to address transportation or distance barriers to healthcare."
        ),
        "reason_template": (
            "Senior reports difficulty accessing health services due to transportation, distance, or cost."
        ),
        "service_provider": "barangay, BHW, RHU, OSCA/MSWDO",
        "evidence_source": (
            "Local primary care access; AICS transportation assistance where crisis-related"
        ),
        "eligibility_trigger": "transport_difficulty OR low_service_access",
        "requires_human_validation": False,
        "documents_needed": None,
    },
    "FNC-004": {
        "code": "FNC-004",
        "category": "hc_access",
        "action": (
            "Refer to MSWDO/barangay for social case assessment, home-safety review, "
            "and vulnerable-sector monitoring."
        ),
        "reason_template": (
            "Senior's home or housing situation presents safety or vulnerability concerns."
        ),
        "service_provider": "MSWDO/CSWDO, barangay, MDRRMO if applicable",
        "evidence_source": (
            "LGU social welfare mandate and disaster-risk vulnerable-sector support"
        ),
        "eligibility_trigger": "unsafe_home OR housing_concern",
        "requires_human_validation": True,
        "documents_needed": None,
    },
    "FNC-005": {
        "code": "FNC-005",
        "category": "hc_access",
        "action": (
            "Coordinate barangay health worker (BHW) for home-based health monitoring "
            "and regular follow-up visits."
        ),
        "reason_template": (
            "Senior has limited access to health services or low service accessibility score."
        ),
        "service_provider": "BHW, RHU, barangay",
        "evidence_source": "Community health worker mandate",
        "eligibility_trigger": "low_service_access",
        "requires_human_validation": False,
        "documents_needed": None,
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
    Merges fixed rule fields with dynamic fields (priority, urgency, risk_level).
    Returns an empty dict if the code is not found.
    """
    rule = get_rule(code)
    if not rule:
        return {}
    return {
        "priority": priority,
        "type": "domain",
        "domain": rule.get("category", "general"),
        "category": rule.get("category", "general"),
        "action": rule["action"],
        "urgency": urgency,
        "risk_level": risk_level,
        "reason": reason_override or rule.get("reason_template", ""),
        "service_provider": rule.get("service_provider", ""),
        "evidence_source": rule.get("evidence_source", ""),
        "recommendation_code": rule.get("code", code),
        "requires_human_validation": rule.get("requires_human_validation", True),
        "documents_needed": rule.get("documents_needed"),
    }
