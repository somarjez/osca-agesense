"""Catalog-driven recommendation matcher.

Single source of truth = recommendations_list.csv (content + trigger_tags +
priority_weight). A catalog row fires when its trigger_tags intersect the
senior's need-tags (see extract_need_tags). Selection caps health and orders
non-health first for routine seniors. Recommendation TEXT is emitted verbatim
from the catalog (no machine-generated clinical prose).
"""
from __future__ import annotations

import csv
import os
from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional, Set

HEALTH_CATEGORY = "health"
GOVERNANCE_CATEGORY = "governance"
TOTAL_REC_CAP = 10
SKIP_TOKENS = {"none", "nan", "", "n/a", "no concern", "no concerns",
               "physically healthy", "healthy eyes", "healthy hearing", "healthy teeth"}

_CATALOG_CACHE: Optional[List["CatalogRow"]] = None


@dataclass(frozen=True)
class CatalogRow:
    code: str
    section: str
    category: str
    who_domain: str
    trigger_summary: str
    recommendation: str
    service_provider: str
    source: str
    apa_reference: str
    source_type: str
    source_link: str
    requires_human_validation: bool
    key_program_tag: str
    implementation_note: str
    trigger_tags: frozenset
    priority_weight: int


def _candidate_paths() -> List[str]:
    here = os.path.dirname(os.path.abspath(__file__))
    return [
        os.path.join(here, "recommendations_list.csv"),                              # shipped copy (primary)
        os.path.abspath(os.path.join(here, "..", "..", "..", "recommendations_list.csv")),  # outer repo root
        os.path.abspath(os.path.join(os.getcwd(), "recommendations_list.csv")),
    ]


def _resolve_path(path: Optional[str]) -> str:
    if path:
        return path
    for c in _candidate_paths():
        if os.path.exists(c):
            return c
    raise FileNotFoundError("recommendations_list.csv not found: " + ", ".join(_candidate_paths()))


def _as_bool(v: Any) -> bool:
    return str(v).strip().lower() in {"1", "true", "yes", "y"}


def load_catalog(path: Optional[str] = None, force: bool = False) -> List[CatalogRow]:
    global _CATALOG_CACHE
    if _CATALOG_CACHE is not None and not force and path is None:
        return _CATALOG_CACHE
    resolved = _resolve_path(path)
    rows: List[CatalogRow] = []
    with open(resolved, encoding="utf-8-sig", newline="") as fh:
        for r in csv.DictReader(fh):
            tags = frozenset(
                t.strip() for t in str(r.get("trigger_tags", "") or "").split("|") if t.strip()
            )
            try:
                weight = int(float(r.get("priority_weight", "1") or "1"))
            except (TypeError, ValueError):
                weight = 1
            rows.append(CatalogRow(
                code=r["recommendation_code"].strip(),
                section=r.get("section", ""),
                category=r.get("category", "").strip(),
                who_domain=r.get("WHO_domain", ""),
                trigger_summary=r.get("trigger_summary", ""),
                recommendation=r.get("recommendation", ""),
                service_provider=r.get("service_provider", ""),
                source=r.get("source", ""),
                apa_reference=r.get("apa_reference", ""),
                source_type=r.get("source_type", ""),
                source_link=r.get("source_link", ""),
                requires_human_validation=_as_bool(r.get("requires_human_validation", "TRUE")),
                key_program_tag=r.get("key_program_tag", ""),
                implementation_note=r.get("implementation_note", ""),
                trigger_tags=tags,
                priority_weight=weight,
            ))
    if path is None:
        _CATALOG_CACHE = rows
    return rows
