# Authoritative domain rules vs. the clean-room basis — independent verification (WJ 2026)

## Scope & method

- Rules: `business-rules/domain-rules.json` — 131 EUE-ids, 11 areas, `supported_years=[2024,2025,2026]`, meta says 26 year-specific rules.
- Evidence: `local/input.ods` parsed **read-only** via Python `zipfile` + `xml.etree` on `content.xml`
  (expanded `number-rows-repeated` / `number-columns-repeated`; captured `office:value`, `table:formula`, text).
  Data validations, conditional formatting (incl. `calcext:color-scale`) and defined names were parsed separately.
  No UNO recalculation was needed: all cached `office:value` results for WJ 2026 are present and were checked directly.
  The clean-room basis itself was not modified.
- Human reports (`domain-verification.md`, `independent-report.md`, die Soll-Dokumentation) were used only as leads;
  every rule was re-checked cell by cell against the ODS.

## Summary counts

| Metric | Count |
|---|---|
| Business rules checked | **131 / 131** |
| Sheets with rule evidence | **16 / 16** |
| Rule source (`quellen`) cell references inspected | **1 191** (57 point at empty user-input cells; every such rule also has formula evidence elsewhere) |
| Rules with concrete ODS evidence (formula / cached value / label / validation / CF) | **131** |
| Fully matching, no caveat | **127** |
| Matching, but with a documented caveat (clean-room basis defect or beleg typo) | **4** |
| Documented 2026 constants / formulas that contradict the ODS | **0** |
| Rules with **no** ODS evidence / documentation-only | **0** |
| Substantive ODS rules with **no** corresponding EUE-id | **0** |
| Year-specific rules (parameter restricted to 2024/2025) | **26 / 26 verified** |
| Other years involved | 2024 and 2025 comparands only; `D2=2026` is the single live year switch |

## Per-sheet rule map (condensed)

Full cell-by-cell evidence: `verification/ods-evidence-by-sheet.md`.
Status `*` = matching but with a documented caveat (see Findings).

| Sheet | Rules | Key 2026 ODS anchors (value / formula) |
|---|---|---|
| Einstellungen | 001–030, 033, 048 | `D2=2026`; `D1` warn IF(>2026/<2024); `C58=Österreich`, `D58=30`, `E58=17`; `I57=8085`, `I58=6.8%`, `I59=37.47`, `I60=I61=101.95`, `I62=110.22`, `I63=18.5%`, `I64=20%`, `I65=1.53%`, `I66=12.95`; `H69:H81` 13 holidays; `N3:N30` KZ-katalog; `AC3:AC9` USt-arten; validations `val1/val2/val4..7/val10/val23…` |
| E | 031, 032, 034–037, 127 | `E!Q3=L3*IF(P3="",1,P3)`, `E!P3` Anteil-lookup, `E!O3` Fallback 9040, `E!B3` Zeilencheck, `E!D3/D4` laufende Nummern, CF `E.B3:E.B1000` ⚠ |
| A | 038–044, 130 | `A!L3=58.8`, `A!Q3=58.8`, `A!O3` Fallback 9230, `A!R3` IG-Vorsteuer, `A!S3` Reverse-Charge, `A!B3` Beleg-/WEB-check, `A!D3=1`, `A!P1001` footer* |
| Ü | 089–095, 103–115, 125, 126, 128, 131 | `R17=13539`, `R18=21992`, `R19=36458`, `R20=70365`, `R21=104859`; `S17..S21=20/30/40/48/50%`; `AA9=-705.6`, `AA12=155.4`, `AA13=101.169`, `AA14=449.64`, `AA15=1223.4`, `AA17=1929.609`, `I12=160.80075`, `Y5='Gewerbetreibende (GSVG)'`, `I12` FSVG-string defect*; CF colorScale |
| AV | 049–061 | `AV!S3` AfA+Halbjahr, `AV!T3` Restbuchwert (min 0.01), `AV!B3` Sammelcheck, `AV!D3/D4` Nummern, `AV!O3` DV 2–99 |
| FR | 062–081 | `AB3` KM-Sätze 0.5/0.42, 0.25, 0.38 + Mitfahrer 0.15/0.05; `Z3/Z4` 3000/30000; `AM3/AN3/AQ3/AR3` 30/4.5/5.85; `AF3/AG3/AF4/AG4` 5-/15-Tage; `AH3` Hinfahrt 50/25*; `AW3..BD3` BT/PT; `B3/B4` Zeilenchecks; `AE3=0` |
| WEB | 045–047 | `WEB!B3` Plausibilität+Abgleich, `WEB!P3/Q3` Anteil/Betrag, `WEB!E3` Belegnummern, CF `WEB.B3:WEB.B100` ⚠ |
| E1A | 096–099, 101, 102 | `J6=33000`, `J7=0.15`, `K4=-58.8`, `K5=0`, `E33` 9225, `C39=9216`, `C40=9215/9217`, DV `val42 (0–300)`/`val43 (25–1200)`; KZ-Katalog `C11/C17/C38` via EUE-023/024 (Einstellungen) |
| E1B | 100 | `C10=9500`, `E7` Mieteinnahmen, `E10` Gebäude-AfA, `E15` Sonstige, `E18=0` |
| UST | 082–088 | `D5..D14` KZ 000/016/057/070/072/073/008/125; `E11..E14` 20/10/13/4.9 %; `E20` Zahllast; `H5/X5` Quartale; `C2/AB2` Überschriften; `E4='JÄNNER'` |
| BE-ART | 119, 129 | `E3` Matrix, `E154` Monats-✓, `Q154='✓✓'`, CF colorScale `E3:P152/Q3:Q152/R3:R152` |
| BA-ART | 120 | `E3` Matrix A/AV/WEB, `E153` FR, `E155='✓'`, CF colorScale `E3:P153/Q3:Q153/R3:R153` |
| Projekte | 121 | `E1='HAUPTPROJEKT'`, `E3` Einnahmen, `E10` Ausgaben, `D5=-58.8`, `D78` Stunden, zeilenweise colorScale (EUE-006/128 evidence also lives here) |
| ZA | 122 | `E7/E10/E13=-58.8`, `E14` ✓/✓✓/Δ, CF `ZA.E14:P14 … E95:P95` contains-text('✓') (EUE-005/128/129 too) |
| Monat | 116–118, 124 | `P5='OFF'`, `P7` 💡-Hinweis, `J13/J16/J20/O29` SUMIFS/INDEX, Array-Filter `A54/A104/A254/A404`, `D404` INDEX |
| Stunden | 123 | `C3/F3` leere Eingabefelder; `F:F` via `h_stunden`; `FR!AE3`/`Ü!M12` SUMIFS |
| `*` caveat | 010 (Einstellungen), 071 (FR), 130 (A), 131 (Ü) | see Findings |

## Concrete findings (documented vs. actual ODS)

1. **EUE-010 — beleg annotation wrong, rule logic correct.** Quelle `Einstellungen!AC7`
   documented beleg = `'IG-Erwerb 20 %'`; actual `AC7 = 'IG-Erwerb 10 %'`. The rule's own
   `logik` and the ODS agree (`AC6='IG-Erwerb 20 %'`, `AC7='IG-Erwerb 10 %'`, `AC8='IG-Erwerb 13 %'`,
   `AC9='IG-Erwerb 4.9 %'`). **Only the beleg string is stale**; no value/formula mismatch.
2. **EUE-071 — documented clean-room basis label defect.** `FR!AH3` emits
   `"Nein (HINFAHRT < 25 km)"` although the single-row test is `[R3]<50` and `([Q3]-[P3])<50`.
   The cumulative `<25` test is in `FR!AH4` and below. The rule documents both 50 (single) and 25
   (cumulative) and flags the label as legacy text. **Verified as intended defect documentation.**
3. **EUE-130 — footer row 1001 legacy rules.** `A!D1001='Ebenso viele Einnahmen erwünscht'`,
   `E!E1001='Gratulation zu so vielen Einnahmen!'`; `A!P1001` still computes 20/10/13/5 % VST.
   No active header/rule uses these. **Verified; correctly classified as no-valid-rule footer.**
4. **EUE-131 — FSVG modus-string defect (the "FSVG label mismatch").** `Ü!Y5` validation `val23`
   lists `'Freiberufliche (FSVG)'`; `Ü!Z15`/`Ü!AA15` test that exact string. `Ü!I12` instead tests
   `'Freiberufler (FSVG)'`, so the FSVG branch never fires: FSVG falls back to the GSVG minimum.
   Verified numerically for 2026: `I61=101.95` (GSVG) vs `I62=110.22` (FSVG) → Δ **8.27 €/month**;
   2024 `95.91` vs `103.69` → Δ **7.78 €/month**. The rule documents this Ist-Zustand as a
   clean-room basis defect. **Verified.**
5. **No material mismatches.** All 2026 constants and formulas checked matched: SV/Est rates and
   thresholds (EUE-014…022, 089…097, 108…114), per-diems 30/17 and 4.5/5.85 (EUE-013, 069),
   km rates 0.5/0.25/0.38 and 0.15/0.05 (EUE-062…066), business-share defaults/fallbacks
   (0/0.5/0.6, 9040/9230/9130 — EUE-007…009, 031, 033, 041, 048, 054, 061), VAT handling
   (EUE-039, 040, 082…086) and AfA (EUE-049…061).
6. **Stale human reports.** `domain-verification.md` still lists EUE-072, EUE-102, EUE-109 and
   EUE-115 as "WRONG" and EUE-020 as a `ŞZ!Z15` typo. The **current** rule text has been corrected
   (EUE-072 uses `SUM($S3,$AC3)`, EUE-102 states 9217 does not suppress 9216, EUE-109/115 describe
   AA9 as *adding* 9225, EUE-020 cites `Ü!Z15`) and now matches the ODS. These are not current findings.

## Completeness

Full rule-id → ODS-evidence matrix: **`verification/ods-coverage-matrix.md`** (136 lines).
Result: 131/131 rules carry concrete ODS evidence; 0 documentation-only rules; 0 rules whose
cited cells are all absent. The 57 empty cited cells are ordinary user-input fields
(`E!M3`, `AV!N3`, `FR!P3`, `Stunden!F3`, …) and always co-occur with formula evidence in the same rule.

Year handling: every rule's `gueltigkeit.jahre` is `[2024,2025,2026]`; `Einstellungen!D2=2026` is the
only live switch. The 26 year-specific rules (parameter subsets for 2024/2025) were re-checked
against the nested `IF(set_wirtschaftsjahr=…)` formulas — all match. Other years appear only as
embedded comparison branches (e.g. 2024 26.4/15 per-diems, 2024 12816/20818/… tax limits).

ODS constructs without a dedicated rule (minor, implied by other rules): the `val31` `'Privat'`
list on `FR!K3`, the `val34` country list on `FR!T3`, and row-copy formulas `FR!AB4+`/`AH4+`
(103 cells containing `2026` outside the cited anchors) — all repetitions of documented row-3
logic, i.e. **no additional business rule**.

## Reproducibility

Read-only helper scripts under `verification/`: `ods_parse.py`, `ods_meta.py`,
`ods_analyze.py`, `ods_verify.py`, `ods_report_gen.py`. To regenerate from the local ODS:

```bash
python3 verification/ods_parse.py local/input.ods /tmp/opencode/ods_cells.json
python3 verification/ods_meta.py
python3 verification/ods_verify.py > /tmp/opencode/ods_verify.log
python3 verification/ods_report_gen.py
```

The last command writes the appendices `verification/ods-evidence-by-sheet.md` and
`verification/ods-coverage-matrix.md`. Intermediate parse output stays in
`/tmp/opencode/`. No source, test or JSON parity fixture was changed by this
verification; `local/input.ods` was not modified.

**Interpreting `ods_verify.py` output:** `PARAM 2026 checks: 92 failed: 61` is
*not* a mismatch count. That probe only asks whether a single cached cell
literally contains the parameter value. 31 checks have such a cell (after
normalising German `6,8 %` to `0.068`, etc.); the other 61 are year-switch
bounds, counts, dropdown defaults and validation thresholds that exist only in
formula/`val?-` definitions, not as cached cell values. They are verified via
those formulas/validations in the per-sheet appendix and produced **0
contradictions**. The numeric/formula verification result above (0 mismatches)
is authoritative.
