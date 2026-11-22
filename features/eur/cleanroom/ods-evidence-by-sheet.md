
### Sheet `Einstellungen`

| Rule | Title | ODS cell evidence |
|---|---|---|
| EUE-001 | Wirtschaftsjahr als zentrale Steuerungsgröße | Einstellungen!D2=2026, Einstellungen!B2 f:of:=IF([.D2]=""; ""; "❯") |
| EUE-002 | Warnung bei zu neuem Wirtschaftsjahr | Einstellungen!D1 f:of:=IF(set_wirtschaftsjahr > 2026; "⚠ Die Tabe… |
| EUE-003 | Warnung bei zu altem Wirtschaftsjahr | Einstellungen!D1 f:of:=IF(set_wirtschaftsjahr > 2026; "⚠ Die Tabe… |
| EUE-004 | Firmenstammdaten und Branchenkennzahl | Einstellungen!D3(empty user input), Einstellungen!D7of:cell-content-is-decimal-number() and cell-content-is-between(11,990), E1A!C6 f:of:=IF(set_branchenkennzahl=""; "Branchenkennz…, Monat!H4 f:of:=IF(set_firmenname=""; ""; set_firmenname) |
| EUE-005 | Zahlarten mit Startsaldo | Einstellungen!H3 txt:'Bankkonto', Einstellungen!H4 txt:'Bar', ZA!E13=-58.8 [of:=IF(OR([.$B7]=""; AND([.E7]=""; [.E8]=""; [.E10]=""; [.E11]="")); "";  N(INDEX(set_zahlarten_startsaldo; MATCH([.$B7]; set_zahlarten; 0); 0)) +  SUM([.$E7:.E7]) + SUM([.$E8:.E8]) - SUM([.$E10:.E10]) - SUM([.$E11:.E11]))] |
| EUE-006 | Projektliste als Auswahl- und Auswertungsdimension | Einstellungen!L3 txt:'Hauptprojekt', Einstellungen!L4 txt:'Nebenprojekt', Einstellungen!L5 txt:'Vermietung', Projekte!E1 f:of:=IF(INDEX(set_projekte;1 ;0)="";"-";UPPER(I… |
| EUE-007 | Zuordnung der Ausgaben-Arten zu E1A-Kennzahlen | Einstellungen!P3 txt:'Wareneinkäufe', Einstellungen!Q3 txt:'9100 - Waren', Einstellungen!N3 f:of:=TRIM(INDEX(e1a_ausgaben_kz; 1; 1)) & " - "…, A!O3 f:of:=IF(OR([.$L3]=""; [.$K3]=""; [.$P3]=0); "";… |
| EUE-008 | Zuordnung der Einnahmen-Arten zu E1A-Kennzahlen | Einstellungen!Z3 txt:'Dienstleistung', Einstellungen!AA3 txt:'9040 - Betriebseinnahmen', Einstellungen!X3 f:of:=TRIM(INDEX(e1a_einnahmen_kz; 1; 1)) & " - …, E!O3 f:of:=IF(OR([.$L3]=""; [.$M3]=""; [.$P3]=0); "";… |
| EUE-009 | Betriebliche Anteile der Ausgaben-Arten | Einstellungen!R3of:cell-content-is-decimal-number() and cell-content-is-between(0,1), A!P3 f:of:=IF(OR([.$L3]=""; [.$K3]=""; COUNTIF(set_a_…, A!Q3=58.8 [of:=IF([.$L3] = ""; ""; [.$L3] * IF([.$P3] = ""; 1; [.$P3]))] |
| EUE-010 | Umsatzsteuer-Arten der Eingangsrechnungen | Einstellungen!AC3 txt:'Reverse Charge 20 %', Einstellungen!AC7 txt:'IG-Erwerb 10 %', A!M3of:cell-content-is-in-list(set_a_ust_arten) |
| EUE-011 | Transportmittel und Fahrzeugtypen | Einstellungen!U3 txt:'Privatauto', Einstellungen!V3 txt:'PKW oder Kombi', Einstellungen!T3 f:of:=IF([.U3]=""; ""; IF([.V3]=""; "⚠";   "❯")), FR!Y3 f:of:=IFERROR(IF([.$N3]=""; "";     IF(INDEX(set… |
| EUE-012 | Feiertage des Wirtschaftsjahres | Einstellungen!H69 f:of:=DATE(set_wirtschaftsjahr;1;1), Einstellungen!H71 f:of:=DATE(set_wirtschaftsjahr;3;22 + MOD(19*MOD…, Einstellungen!H81 f:of:=DATE(set_wirtschaftsjahr;12;26), FR!AV3 f:of:=IF(OR([.$E3]=""; [.$F3]=""); "";    IF(COU… |
| EUE-013 | Reiseverpflegungspauschalen je Land | Einstellungen!C58 txt:'Österreich', Einstellungen!D58=30 [of:=IF(set_wirtschaftsjahr>=2025; 30; 26.4)], Einstellungen!E58=17 [of:=IF(set_wirtschaftsjahr>=2025; 17; 15)], Einstellungen!C231 txt:'Zypern' |
| EUE-014 | Maximale SV-Beitragsgrundlage | Einstellungen!I57=8085 [of:=IF(set_wirtschaftsjahr=2026; 8085; IF(set_wirtschaftsjahr=2025; 7525; IF(set_wirtschaftsjahr=2024; 7070; IF(set_wirtschaftsjahr=2023; 6825; "?"))))], Ü!AA9=-705.6 [of:=MIN(SUM([.AA7:.AA8]); set_sv_bgl_max*12)], Ü!Y10 f:of:=IF(set_sv_bgl_max*12 = ue_sv_bmg; "Wow! Ma… |
| EUE-015 | Krankenversicherungssatz | Einstellungen!I58=0.068 [of:=IF(set_wirtschaftsjahr=2026; 6.8%; IF(set_wirtschaftsjahr=2025; 6.8%; IF(set_wirtschaftsjahr=2024; 6.8%; IF(set_wirtschaftsjahr=2023; 6.8%; "?"))))], Ü!Z14 f:of:=IF(OR(ue_sv_jungunternehmer="Ja"; ue_sv_mo… |
| EUE-016 | Mindest-KV-Beitrag | Einstellungen!I59=37.47 [of:=IF(set_wirtschaftsjahr=2026; 37.47; IF(set_wirtschaftsjahr=2025; 37.47; IF(set_wirtschaftsjahr=2024; 35.45; IF(set_wirtschaftsjahr=2023; 34.06; "?"))))], Ü!AA14=449.64 [of:=IF(ue_sv_modus="Nur Unfallversicherung"; ""; IF(ue_sv_kv_satz="-"; set_sv_kv_min_beitrag * 12; MAX(ue_sv_bmg * ue_sv_kv_satz; set_sv_kv_min_beitrag * 12)))] |
| EUE-017 | Mindest-GSVG-Pensionsversicherungsbeitrag | Einstellungen!I60=101.95 [of:=IF(set_wirtschaftsjahr=2026; 101.95; IF(set_wirtschaftsjahr=2025; 101.95; IF(set_wirtschaftsjahr=2024; 95.91; IF(set_wirtschaftsjahr=2023; 92.67; "?"))))], Einstellungen!I61=101.95 [of:=IF(set_wirtschaftsjahr=2026; 101.95; IF(set_wirtschaftsjahr=2025; 101.95; IF(set_wirtschaftsjahr=2024; 95.91; IF(set_wirtschaftsjahr=2023; 92.67; "?"))))], Ü!AA15=1223.4 [of:=IF(ue_sv_pv_satz="-"; ""; MAX(ue_sv_bmg * ue_sv_pv_satz; IF(ue_sv_modus="Freiberufliche (FSVG)"; set_sv_fsvg_pv_min_beitrag * 12; IF(ue_sv_modus="Neue Selbständige (GSVG)"; set_sv_gsvg_ns_pv_min_beitrag * 12; set_sv_gsvg_pv_min_beitrag * 12))))] |
| EUE-018 | Mindest-FSVG-Pensionsversicherungsbeitrag | Einstellungen!I62=110.22 [of:=IF(set_wirtschaftsjahr=2026; 110.22; IF(set_wirtschaftsjahr=2025; 110.22; IF(set_wirtschaftsjahr=2024; 103.69; IF(set_wirtschaftsjahr=2023; 100.18; "?"))))], Ü!AA15=1223.4 [of:=IF(ue_sv_pv_satz="-"; ""; MAX(ue_sv_bmg * ue_sv_pv_satz; IF(ue_sv_modus="Freiberufliche (FSVG)"; set_sv_fsvg_pv_min_beitrag * 12; IF(ue_sv_modus="Neue Selbständige (GSVG)"; set_sv_gsvg_ns_pv_min_beitrag * 12; set_sv_gsvg_pv_min_beitrag * 12))))] |
| EUE-019 | GSVG-Pensionsversicherungssatz | Einstellungen!I63=0.185 [of:=IF(set_wirtschaftsjahr=2026; 18.5%; IF(set_wirtschaftsjahr=2025; 18.5%; IF(set_wirtschaftsjahr=2024; 18.5%; IF(set_wirtschaftsjahr=2023; 18.5%; "?"))))], Ü!Z15=0.185 [of:=IF(ue_sv_modus="Nur Unfallversicherung"; "-";  IF(ue_sv_modus="Freiberufliche (FSVG)"; set_sv_fsvg_pv_satz; set_sv_gsvg_pv_satz))] |
| EUE-020 | FSVG-Pensionsversicherungssatz | Einstellungen!I64=0.2 [of:=IF(set_wirtschaftsjahr=2026; 20%; IF(set_wirtschaftsjahr=2025; 20%; IF(set_wirtschaftsjahr=2024; 20%; IF(set_wirtschaftsjahr=2023; 20%; "?"))))], Ü!Z15=0.185 [of:=IF(ue_sv_modus="Nur Unfallversicherung"; "-";  IF(ue_sv_modus="Freiberufliche (FSVG)"; set_sv_fsvg_pv_satz; set_sv_gsvg_pv_satz))] |
| EUE-021 | Selbständigenvorsorge-Satz | Einstellungen!I65=0.0153 [of:=IF(set_wirtschaftsjahr=2026; 1.53%; IF(set_wirtschaftsjahr=2025; 1.53%; IF(set_wirtschaftsjahr=2024; 1.53%; IF(set_wirtschaftsjahr=2023; 1.53%; "?"))))], Ü!Z13=0.0153 [of:=IF(OR(ue_sv_modus="Freiberufliche (FSVG)"; ue_sv_modus="Nur Unfallversicherung"); "-"; set_sv_svs_satz)] |
| EUE-022 | Unfallversicherungspauschale | Einstellungen!I66=12.95 [of:=IF(set_wirtschaftsjahr=2026; 12.95; IF(set_wirtschaftsjahr=2025; 12.07; IF(set_wirtschaftsjahr=2024; 11.35; IF(set_wirtschaftsjahr=2023; 10.97; "?"))))], Ü!AA12=155.4 [of:=set_sv_uv*12] |
| EUE-023 | E1A-Kennzahlenkatalog Ausgaben | Einstellungen!N3 f:of:=TRIM(INDEX(e1a_ausgaben_kz; 1; 1)) & " - "…, Einstellungen!N19 f:of:=TRIM(INDEX(e1a_ausgaben_kz; 17; 1)) & " - …, Einstellungen!N30 f:of:="E1B " & TRIM(INDEX(e1b_werbungskosten_kz;…, E1A!C17 txt:'9100' |
| EUE-024 | E1A-Kennzahlenkatalog Einnahmen | Einstellungen!X3 f:of:=TRIM(INDEX(e1a_einnahmen_kz; 1; 1)) & " - …, Einstellungen!X7 f:of:="E1B " & TRIM(INDEX(e1b_einnahmen_kz; 1; 1…, E1A!C11 txt:'9040' |
| EUE-025 | Duplikatwarnung Zahlarten | Einstellungen!H1 f:of:=IF(SUMPRODUCT(N(COUNTIF(set_zahlarten; set…, Einstellungen!H3 txt:'Bankkonto' |
| EUE-026 | Duplikatwarnung Projekte | Einstellungen!L1 f:of:=IF(SUMPRODUCT(N(COUNTIF(set_projekte; set_… |
| EUE-027 | Duplikatwarnung Ausgaben-Arten | Einstellungen!P1 f:of:=IF(SUMPRODUCT(N(COUNTIF(set_a_arten; set_a… |
| EUE-028 | Duplikatwarnung Einnahmen-Arten | Einstellungen!Z1 f:of:=IF(SUMPRODUCT(N(COUNTIF(set_e_arten; set_e… |
| EUE-029 | Verwendungsanzeige je Art (❯ / 99+) | Einstellungen!O3 f:of:=IF([.$P3]=""; "";  IF(SUM(COUNTIF(a_ausgab…, Einstellungen!Y3 f:of:=IF([.$Z3]=""; "";  IF(COUNTIF(e_einnahmen_… |
| EUE-030 | Zulässige Wertebereiche der Eingabefelder | Einstellungen!R3of:cell-content-is-decimal-number() and cell-content-is-between(0,1), E!G3of:cell-content-is-decimal-number() and cell-content-is-between(1,31), AV!O3of:cell-content-is-decimal-number() and cell-content-is-between(2,99), FR!O3of:cell-content-is-decimal-number() and cell-content-is-between(0,10) |
| EUE-033 | Private Einnahmen sind nicht betrieblich | Einstellungen!AB3of:cell-content-is-in-list("0%";"100%"), E!O3 f:of:=IF(OR([.$L3]=""; [.$M3]=""; [.$P3]=0); "";… |
| EUE-048 | Private Ausgaben sind vom Abzug ausgeschlossen | Einstellungen!R3of:cell-content-is-decimal-number() and cell-content-is-between(0,1), A!Q3=58.8 [of:=IF([.$L3] = ""; ""; [.$L3] * IF([.$P3] = ""; 1; [.$P3]))] |

### Sheet `E`

| Rule | Title | ODS cell evidence |
|---|---|---|
| EUE-031 | Betriebliche Einnahme aus Bruttobetrag und Betriebsanteil | E!M3conditional-format, E!P3 f:of:=IF(OR([.$M3]=""; [.$L3]=""; COUNTIF(set_e_…, E!Q3 f:of:=IF([.$M3]=""; ""; [.$M3] * IF([.$P3]=""; 1… |
| EUE-032 | Einnahmen-Kennzahl mit Fallback 9040 | E!O3 f:of:=IF(OR([.$L3]=""; [.$M3]=""; [.$P3]=0); "";…, E!L3of:cell-content-is-in-list(set_e_arten) |
| EUE-034 | Mieteinnahmen werden im Formular E1B erfasst | E!O3 f:of:=IF(OR([.$L3]=""; [.$M3]=""; [.$P3]=0); "";…, E1B!E7 f:of:=IF(SUMIF(e_est_kennzahlen; "E1B*"; e_betri…, Ü!U12=0 [of:=IF(ue_est_modus="Nur Betrieb"; ""; SUM(e1b_einnahmen) - SUM(e1b_werbungskosten))] |
| EUE-035 | Laufende Nummern im Einnahmenjournal | E!D3 f:of:=IF(OR([.M3]=""; [.P3]=0); ""; 1), E!D4 f:of:=IF(OR([.M4]=""; [.P4]=0); ""; IF(ROW()=3; … |
| EUE-036 | Plausibilitätsprüfung Einnahmenzeile | E!B3 f:of:=IF([.$M3]=""; ""; CONCATENATE( IF(AND([.$E…, E!F3of:cell-content-is-in-list("Jänner";"Februar";"März";"April";"Mai";"Juni";"Juli";"August";"September";"Oktober";"November";"Dezember") |
| EUE-037 | Monats- und Tagesvalidierung in E/A/AV/WEB | E!F3of:cell-content-is-in-list("Jänner";"Februar";"März";"April";"Mai";"Juni";"Juli";"August";"September";"Oktober";"November";"Dezember"), E!G3of:cell-content-is-decimal-number() and cell-content-is-between(1,31), A!B3 f:of:=IF([.$L3]=""; ""; CONCATENATE(  IF(AND([.$… |
| EUE-127 | Warnindikator ⚠ in den Zeilenchecks | E!B3 f:of:=IF([.$M3]=""; ""; CONCATENATE( IF(AND([.$E…, A!B3 f:of:=IF([.$L3]=""; ""; CONCATENATE(  IF(AND([.$…, FR!B3 f:of:=CONCATENATE( IF([.$BD3]<>"";   IF([.$F3]="…, Monat!P7 f:of:=IF(OR(m_monat= ""; m_monat = "OFF"); "💡 Re… |

### Sheet `A`

| Rule | Title | ODS cell evidence |
|---|---|---|
| EUE-038 | Betriebliche Ausgabe aus Betrag und Betriebsanteil | A!L3=58.8, A!P2 txt:'Diese Zelle wird automatisch basierend a', A!Q2 txt:'Dieser Netto-Betrag wird für die Ermittl', A!Q3=58.8 [of:=IF([.$L3] = ""; ""; [.$L3] * IF([.$P3] = ""; 1; [.$P3]))] |
| EUE-039 | Vorsteuer aus innergemeinschaftlichen Erwerben | A!R3 f:of:=IF(OR([.$L3]=""; [.$M3]=""); ""; IF([.$M3]…, A!M3of:cell-content-is-in-list(set_a_ust_arten) |
| EUE-040 | USt aus Reverse Charge | A!S3 f:of:=IF(OR([.$L3]=""; [.$M3]=""); ""; IF([.$M3]…, A!M3of:cell-content-is-in-list(set_a_ust_arten) |
| EUE-041 | Ausgaben-Kennzahl mit Fallback 9230 | A!O3 f:of:=IF(OR([.$L3]=""; [.$K3]=""; [.$P3]=0); "";…, A!K3 txt:'Software' |
| EUE-042 | Belegpflicht und Plausibilitätsprüfung Ausgaben | A!B3 f:of:=IF([.$L3]=""; ""; CONCATENATE(  IF(AND([.$…, A!E3 txt:'A1' |
| EUE-043 | Wareneinkauf muss im Wareneingangsbuch stehen | A!B3 f:of:=IF([.$L3]=""; ""; CONCATENATE(  IF(AND([.$…, WEB!E3conditional-format |
| EUE-044 | Laufende Nummern im Ausgabenjournal | A!D3=1 [of:=IF(OR([.L3]=""; [.P3]=0); ""; 1)], A!D4 f:of:=IF(OR([.L4]=""; [.P4]=0); ""; IF(ROW()=3; … |
| EUE-130 | Footer-Zeile 1001 enthält keine gültigen Regeln | A!D1001 txt:'Ebenso viele Einnahmen erwünscht', A!P1001 f:of:=IF([.$K1001] = ""; ""; IF([.$L1001] = "20 …, E!E1001 txt:'Gratulation zu so vielen Einnahmen!', Einstellungen!AC3 txt:'Reverse Charge 20 %' |

### Sheet `Ü`

| Rule | Title | ODS cell evidence |
|---|---|---|
| EUE-089 | Einkommensteuertarif Stufe 1 (20 %) | Ü!R17=13539 [of:=IF(set_wirtschaftsjahr=2024; 12816; IF(set_wirtschaftsjahr=2025; 13308; IF(set_wirtschaftsjahr=2026; 13539; "?")))], Ü!S17=0.2 [of:=IF(N(set_wirtschaftsjahr) <= 2026; 20%; "?")], Ü!U17=0 [of:=(MAX(ue_gesamteinkommen - ue_est_1_grenze; 0) - MAX(ue_gesamteinkommen - ue_est_2_grenze; 0)) * ue_est_1_satz] |
| EUE-090 | Einkommensteuertarif Stufe 2 (30 %) | Ü!R18=21992 [of:=IF(set_wirtschaftsjahr=2024; 20818; IF(set_wirtschaftsjahr=2025; 21617; IF(set_wirtschaftsjahr=2026; 21992; "?")))], Ü!S18=0.3 [of:=IF(OR(set_wirtschaftsjahr=2024; set_wirtschaftsjahr=2025; set_wirtschaftsjahr=2026); 30%; "?")], Ü!U18=0 [of:=(MAX(ue_gesamteinkommen - ue_est_2_grenze; 0) - MAX(ue_gesamteinkommen - ue_est_3_grenze; 0)) * ue_est_2_satz] |
| EUE-091 | Einkommensteuertarif Stufe 3 (40 %) | Ü!R19=36458 [of:=IF(set_wirtschaftsjahr=2024; 34513; IF(set_wirtschaftsjahr=2025; 35836; IF(set_wirtschaftsjahr=2026; 36458; "?")))], Ü!S19=0.4 [of:=IF(OR(set_wirtschaftsjahr=2024; set_wirtschaftsjahr=2025; set_wirtschaftsjahr=2026); 40%; "?")] |
| EUE-092 | Einkommensteuertarif Stufe 4 (48 %) | Ü!R20=70365 [of:=IF(set_wirtschaftsjahr=2024; 66612; IF(set_wirtschaftsjahr=2025; 69166; IF(set_wirtschaftsjahr=2026; 70365; "?")))], Ü!S20=0.48 [of:=IF(set_wirtschaftsjahr<=2026; 48%; "?")] |
| EUE-093 | Einkommensteuertarif Stufe 5 (50 %) | Ü!R21=104859 [of:=IF(set_wirtschaftsjahr=2024; 99266; IF(set_wirtschaftsjahr=2025; 103072; IF(set_wirtschaftsjahr=2026; 104859; "?")))], Ü!S21=0.5 [of:=IF(set_wirtschaftsjahr<=2026; 50%; "?")], Ü!U21=0 [of:=MAX(ue_gesamteinkommen - ue_est_5_grenze; 0) * ue_est_5_satz] |
| EUE-094 | Progressionsberechnung der Einkommensteuer | Ü!U17=0 [of:=(MAX(ue_gesamteinkommen - ue_est_1_grenze; 0) - MAX(ue_gesamteinkommen - ue_est_2_grenze; 0)) * ue_est_1_satz], Ü!U21=0 [of:=MAX(ue_gesamteinkommen - ue_est_5_grenze; 0) * ue_est_5_satz], Ü!U23=0 [of:=SUM([.U17:.U21])] |
| EUE-095 | Gesamteinkommen nach Rechenmodus | Ü!R5 txt:'Gesamt', Ü!U14=-705.6 [of:=IF(ue_est_modus="Nur Betrieb"; N([.U10])+N([.U11]); IF(ue_est_modus="Nur Vermietung"; N([.U11])+N([.U12])+N([.U13]); N([.U10])+N([.U11])+N([.U12])+N([.U13])))], Ü!U10=-705.6 [of:=IF(ue_est_modus="Nur Vermietung"; ""; SUM([.U7:.U8])-N([.U9]))] |
| EUE-103 | Est-Aufteilung und Vorauszahlungen | Ü!U11 txt:'Haben Sie zusätzlich eine nicht-selbstän', Ü!U24=0 [of:=IF(ue_est_modus="Nur Bruttolohn"; ""; N([.U23]) - N([.U25]) - N([.U27]))], Ü!U25=0 [of:=IF(ue_est_modus="Nur Bruttolohn"; ""; SUM((MAX([.U11]+[.U12]-ue_est_1_grenze;0)-MAX([.U11]+[.U12]-ue_est_2_grenze;0))*ue_est_1_satz; (MAX([.U11]+[.U12]-ue_est_2_grenze;0)-MAX([.U11]+[.U12]-ue_est_3_grenze;0))*ue_est_2_satz; (MAX([.U11]+[.U12]-ue_est_3_grenze;0)-MAX([.U11]+[.U12]-ue_est_4_grenze;0))*ue_est_3_satz; (MAX([.U11]+[.U12]-ue_est_4_grenze;0)-MAX([.U11]+[.U12]-ue_est_5_grenze;0))*ue_est_4_satz; MAX([.U11]+[.U12]-ue_est_5_grenze;0)*ue_est_5_satz) - [.U27])], Ü!U27=0 [of:=SUM((MAX([.U11]-ue_est_1_grenze;0)-MAX([.U11]-ue_est_2_grenze;0))*ue_est_1_satz; (MAX([.U11]-ue_est_2_grenze;0)-MAX([.U11]-ue_est_3_grenze;0))*ue_est_2_satz; (MAX([.U11]-ue_est_3_grenze;0)-MAX([.U11]-ue_est_4_grenze;0))*ue_est_3_satz; (MAX([.U11]-ue_est_4_grenze;0)-MAX([.U11]-ue_est_5_grenze;0))*ue_est_4_satz; MAX([.U11]-ue_est_5_grenze;0)*ue_est_5_satz)] |
| EUE-104 | Prognose- und Rechenmodi | Ü!U5 txt:'Ja', Ü!R5 txt:'Gesamt', Ü!U8=-646.8 [of:=IF(AND([.$U$5]="Ja"; ue_est_modus<>"Nur Vermietung"); IF(SUMPRODUCT((ue_gewinn_pro_monat<>"")*1)=0; 0; (SUM(e1a_betriebseinnahmen) - SUM(e1a_betriebsausgaben))/SUMPRODUCT((ue_gewinn_pro_monat<>"")*1) * 12 - (SUM(e1a_betriebseinnahmen) - SUM(e1a_betriebsausgaben))); "")], Ü!U13=0 [of:=IF(AND([.$U$5]="Ja"; ue_est_modus<>"Nur Betrieb"); IF(SUMPRODUCT((ue_gewinn_pro_monat<>"")*1)=0; 0; (SUM(e1b_einnahmen) - SUM(e1b_werbungskosten))/SUMPRODUCT((ue_gewinn_pro_monat<>"")*1) * 12 - (SUM(e1b_einnahmen) - SUM(e1b_werbungskosten))); "")] |
| EUE-105 | Bruttolohn- und andere-Gewinne-Modi | Ü!R5 txt:'Gesamt', Ü!AA8=0 [of:=IF(ue_est_modus="Nur andere Gewinne"; 0; N(e1a_9225))], Ü!N4 f:of:=IF(ue_est_modus="Nur andere Gewinne"; ""; … |
| EUE-106 | Zusätzliche Rechenmodi in den Übersichtsformeln | Ü!R5 txt:'Gesamt', Ü!Y17 f:of:=IF(ue_est_modus="Mit Prognose"; "PROGNOSTI…, Ü!R24 f:of:=IF(OR(ue_est_modus="Nur Bruttolohn"; ue_es…, Ü!R25 f:of:=IF(OR(ue_est_modus="Nur Bruttolohn"; ue_es… |
| EUE-107 | Sozialversicherungsmodus | Ü!Y5 txt:'Gewerbetreibende (GSVG)', Ü!Z13=0.0153 [of:=IF(OR(ue_sv_modus="Freiberufliche (FSVG)"; ue_sv_modus="Nur Unfallversicherung"); "-"; set_sv_svs_satz)], Ü!Z15=0.185 [of:=IF(ue_sv_modus="Nur Unfallversicherung"; "-";  IF(ue_sv_modus="Freiberufliche (FSVG)"; set_sv_fsvg_pv_satz; set_sv_gsvg_pv_satz))], Einstellungen!I64=0.2 [of:=IF(set_wirtschaftsjahr=2026; 20%; IF(set_wirtschaftsjahr=2025; 20%; IF(set_wirtschaftsjahr=2024; 20%; IF(set_wirtschaftsjahr=2023; 20%; "?"))))] |
| EUE-108 | Jungunternehmer-Regelung | Ü!AA5 txt:'Ja', Ü!Z14 f:of:=IF(OR(ue_sv_jungunternehmer="Ja"; ue_sv_mo…, Ü!AA14=449.64 [of:=IF(ue_sv_modus="Nur Unfallversicherung"; ""; IF(ue_sv_kv_satz="-"; set_sv_kv_min_beitrag * 12; MAX(ue_sv_bmg * ue_sv_kv_satz; set_sv_kv_min_beitrag * 12)))], Ü!Z13=0.0153 [of:=IF(OR(ue_sv_modus="Freiberufliche (FSVG)"; ue_sv_modus="Nur Unfallversicherung"); "-"; set_sv_svs_satz)] |
| EUE-109 | SV-Beitragsgrundlage und Deckelung | Ü!AA7=-705.6 [of:=ue_est_bmg], Ü!AA8=0 [of:=IF(ue_est_modus="Nur andere Gewinne"; 0; N(e1a_9225))], Ü!AA9=-705.6 [of:=MIN(SUM([.AA7:.AA8]); set_sv_bgl_max*12)] |
| EUE-110 | Krankenversicherungsbeitrag | Ü!AA14=449.64 [of:=IF(ue_sv_modus="Nur Unfallversicherung"; ""; IF(ue_sv_kv_satz="-"; set_sv_kv_min_beitrag * 12; MAX(ue_sv_bmg * ue_sv_kv_satz; set_sv_kv_min_beitrag * 12)))], Einstellungen!I58=0.068 [of:=IF(set_wirtschaftsjahr=2026; 6.8%; IF(set_wirtschaftsjahr=2025; 6.8%; IF(set_wirtschaftsjahr=2024; 6.8%; IF(set_wirtschaftsjahr=2023; 6.8%; "?"))))], Einstellungen!I59=37.47 [of:=IF(set_wirtschaftsjahr=2026; 37.47; IF(set_wirtschaftsjahr=2025; 37.47; IF(set_wirtschaftsjahr=2024; 35.45; IF(set_wirtschaftsjahr=2023; 34.06; "?"))))] |
| EUE-111 | Pensionsversicherungsbeitrag | Ü!AA15=1223.4 [of:=IF(ue_sv_pv_satz="-"; ""; MAX(ue_sv_bmg * ue_sv_pv_satz; IF(ue_sv_modus="Freiberufliche (FSVG)"; set_sv_fsvg_pv_min_beitrag * 12; IF(ue_sv_modus="Neue Selbständige (GSVG)"; set_sv_gsvg_ns_pv_min_beitrag * 12; set_sv_gsvg_pv_min_beitrag * 12))))], Ü!Z15=0.185 [of:=IF(ue_sv_modus="Nur Unfallversicherung"; "-";  IF(ue_sv_modus="Freiberufliche (FSVG)"; set_sv_fsvg_pv_satz; set_sv_gsvg_pv_satz))], Einstellungen!I63=0.185 [of:=IF(set_wirtschaftsjahr=2026; 18.5%; IF(set_wirtschaftsjahr=2025; 18.5%; IF(set_wirtschaftsjahr=2024; 18.5%; IF(set_wirtschaftsjahr=2023; 18.5%; "?"))))], Einstellungen!I64=0.2 [of:=IF(set_wirtschaftsjahr=2026; 20%; IF(set_wirtschaftsjahr=2025; 20%; IF(set_wirtschaftsjahr=2024; 20%; IF(set_wirtschaftsjahr=2023; 20%; "?"))))] |
| EUE-112 | Selbständigenvorsorge und Unfallversicherung | Ü!AA12=155.4 [of:=set_sv_uv*12], Ü!AA13=101.169 [of:=IF(ue_sv_svs_satz="-"; ""; IF(ue_sv_kv_satz="-"; set_sv_kv_min_beitrag / set_sv_kv_satz * 12 * ue_sv_svs_satz; MAX(ue_sv_bmg * ue_sv_svs_satz; set_sv_kv_min_beitrag / set_sv_kv_satz * 12 * ue_sv_svs_satz)))], Einstellungen!I65=0.0153 [of:=IF(set_wirtschaftsjahr=2026; 1.53%; IF(set_wirtschaftsjahr=2025; 1.53%; IF(set_wirtschaftsjahr=2024; 1.53%; IF(set_wirtschaftsjahr=2023; 1.53%; "?"))))] |
| EUE-113 | Mindestbeiträge bei fehlenden Einkünften | Ü!I12=160.80075 [of:=IF(ue_sv_modus="Nur Unfallversicherung"; set_sv_uv; SUM(set_sv_uv; IF(ue_sv_svs_satz="-"; 0; set_sv_kv_min_beitrag / set_sv_kv_satz * ue_sv_svs_satz); set_sv_kv_min_beitrag; IF(ue_sv_modus="Freiberufler (FSVG)"; set_sv_fsvg_pv_min_beitrag; IF(ue_sv_modus="Neue Selbständige (GSVG)"; set_sv_gsvg_ns_pv_min_beitrag; set_sv_gsvg_pv_min_beitrag))))], Ü!AA17=1929.609 [of:=SUM([.AA12:.AA15])] |
| EUE-114 | Gesamt- und Monats-SV-Beiträge | Ü!AA17=1929.609 [of:=SUM([.AA12:.AA15])], Ü!AA18=160.80075 [of:=IF([.AA17]=""; ""; [.AA17]/12)], Ü!Y17 f:of:=IF(ue_est_modus="Mit Prognose"; "PROGNOSTI… |
| EUE-115 | SV-Beiträge in der Einnahmen-Ausgaben-Rechnung | Ü!AA8=0 [of:=IF(ue_est_modus="Nur andere Gewinne"; 0; N(e1a_9225))], E1A!E33 f:of:=IF(SUMIF(a_est_kennzahlen; [.$C33]&"*"; a_…, Ü!F12=58.8 [of:=IF(ue_est_modus="Nur Vermietung"; IF(SUMIFS(a_betriebsausgaben; a_monate; [.$C12]; a_est_kennzahlen; "E1B*") + SUMIF(av_jahre; "<"&set_wirtschaftsjahr; av_betriebsausgaben) + SUMIFS(av_betriebsausgaben; av_monate; [.$C12]; av_jahre; set_wirtschaftsjahr; av_est_kennzahlen; "E1B*")=0; ""; SUMIFS(a_betriebsausgaben; a_monate; [.$C12]; a_est_kennzahlen; "E1B*") + SUMIFS(av_betriebsausgaben; av_monate; [.$C12]; av_jahre; set_wirtschaftsjahr; av_est_kennzahlen; "E1B*")); IF(ue_est_modus="Nur Betrieb"; IF(SUMIF(a_monate; [.$C12]; a_betriebsausgaben) - SUMIFS(a_betriebsausgaben; a_monate; [.$C12]; a_est_kennzahlen; "E1B*") - SUMIFS(a_betriebsausgaben; a_monate; [.$C12]; a_est_kennzahlen; "9225*") * --(ue_sv_modus<>"Nur Unfallversicherung") + SUMIF(av_jahre; "<"&set_wirtschaftsjahr; av_betriebsausgaben) + SUMIFS(av_betriebsausgaben; av_monate; [.$C12]; av_jahre; set_wirtschaftsjahr) - SUMIFS(av_betriebsausgaben; av_monate; [.$C12]; av_jahre; set_wirtschaftsjahr; av_est_kennzahlen; "E1B*") + SUMIF(web_monate; [.$C12]; web_betriebsausgaben) + SUMIF(fr_monate; [.$C12]; fr_betriebsausgaben)=0; ""; SUMIF(a_monate; [.$C12]; a_betriebsausgaben) - SUMIFS(a_betriebsausgaben; a_monate; [.$C12]; a_est_kennzahlen; "E1B*") - SUMIFS(a_betriebsausgaben; a_monate; [.$C12]; a_est_kennzahlen; "9225*") * --(ue_sv_modus<>"Nur Unfallversicherung") + SUMIF(av_jahre; "<"&set_wirtschaftsjahr; av_betriebsausgaben) + SUMIFS(av_betriebsausgaben; av_monate; [.$C12]; av_jahre; set_wirtschaftsjahr) - SUMIFS(av_betriebsausgaben; av_monate; [.$C12]; av_jahre; set_wirtschaftsjahr; av_est_kennzahlen; "E1B*") + SUMIF(web_monate; [.$C12]; web_betriebsausgaben) + SUMIF(fr_monate; [.$C12]; fr_betriebsausgaben)); IF(SUMIF(a_monate; [.$C12]; a_betriebsausgaben) - SUMIFS(a_betriebsausgaben; a_monate; [.$C12]; a_est_kennzahlen; "9225*") * --(ue_sv_modus<>"Nur Unfallversicherung") + SUMIF(av_jahre; "<"&set_wirtschaftsjahr; av_betriebsausgaben) + SUMIFS(av_betriebsausgaben; av_monate; [.$C12]; av_jahre; set_wirtschaftsjahr) + SUMIF(web_monate; [.$C12]; web_betriebsausgaben) + SUMIF(fr_monate; [.$C12]; fr_betriebsausgaben)=0; ""; SUMIF(a_monate; [.$C12]; a_betriebsausgaben) - SUMIFS(a_betriebsausgaben; a_monate; [.$C12]; a_est_kennzahlen; "9225*") * --(ue_sv_modus<>"Nur Unfallversicherung") + SUMIF(av_jahre; "<"&set_wirtschaftsjahr; av_betriebsausgaben) + SUMIFS(av_betriebsausgaben; av_monate; [.$C12]; av_jahre; set_wirtschaftsjahr) + SUMIF(web_monate; [.$C12]; web_betriebsausgaben) + SUMIF(fr_monate; [.$C12]; fr_betriebsausgaben))))] |
| EUE-131 | Konsistenz der SV-Modusstrings | Ü!Y5 txt:'Gewerbetreibende (GSVG)', Ü!I12=160.80075 [of:=IF(ue_sv_modus="Nur Unfallversicherung"; set_sv_uv; SUM(set_sv_uv; IF(ue_sv_svs_satz="-"; 0; set_sv_kv_min_beitrag / set_sv_kv_satz * ue_sv_svs_satz); set_sv_kv_min_beitrag; IF(ue_sv_modus="Freiberufler (FSVG)"; set_sv_fsvg_pv_min_beitrag; IF(ue_sv_modus="Neue Selbständige (GSVG)"; set_sv_gsvg_ns_pv_min_beitrag; set_sv_gsvg_pv_min_beitrag))))], Ü!Z15=0.185 [of:=IF(ue_sv_modus="Nur Unfallversicherung"; "-";  IF(ue_sv_modus="Freiberufliche (FSVG)"; set_sv_fsvg_pv_satz; set_sv_gsvg_pv_satz))], Ü!AA15=1223.4 [of:=IF(ue_sv_pv_satz="-"; ""; MAX(ue_sv_bmg * ue_sv_pv_satz; IF(ue_sv_modus="Freiberufliche (FSVG)"; set_sv_fsvg_pv_min_beitrag * 12; IF(ue_sv_modus="Neue Selbständige (GSVG)"; set_sv_gsvg_ns_pv_min_beitrag * 12; set_sv_gsvg_pv_min_beitrag * 12))))] |
| EUE-125 | Monatlicher Mindest-SV-Sockel und Verlustbasis | Ü!I12=160.80075 [of:=IF(ue_sv_modus="Nur Unfallversicherung"; set_sv_uv; SUM(set_sv_uv; IF(ue_sv_svs_satz="-"; 0; set_sv_kv_min_beitrag / set_sv_kv_satz * ue_sv_svs_satz); set_sv_kv_min_beitrag; IF(ue_sv_modus="Freiberufler (FSVG)"; set_sv_fsvg_pv_min_beitrag; IF(ue_sv_modus="Neue Selbständige (GSVG)"; set_sv_gsvg_ns_pv_min_beitrag; set_sv_gsvg_pv_min_beitrag))))], Ü!I13=0 [of:=SUM([.$U$24]; N([.$U$25]); ue_sv) - [.$I$12] * IF([.$U$5]="Ja"; 12; 12 - COUNTIF(ue_gewinn_pro_monat; ""))], Ü!I14=58.8 [of:=ABS(MIN(ue_gewinn_pro_monat))], Ü!I16=0 [of:=IF(SUM(ue_gewinn_pro_monat)=0; 0; SUM(IF(ue_gewinn_pro_monat=""; IF([.$U$5]="Ja"; [.$I$15] + [.$I$14]; 0); ue_gewinn_pro_monat + [.$I$14])))] |
| EUE-126 | Monatliche EST+SV und Reingewinn | Ü!J12=160.80075 [of:=IF(ue_est_modus="Nur Vermietung"; IF(AND([.$G12]=""; [.$U$5]="Nein"); ""; IF(SUM(ue_gewinn_pro_monat)=0; ""; (IF(AND([.$U$5]="Ja"; [.$G12]=""); ue_prgn_gw/12; N([.$G12])) + [.$I$14]) / (SUM(ue_gewinn_pro_monat) + [.$I$14]*12) * ([.$U$23] - [.$U$27]))); IF([.$I$16]=0; IF([.$U$5]="Ja"; [.$I$13]/12+[.$I$12]; ""); IF(AND([.$U$5]="Nein"; [.$G12]=""); ""; (IF(AND([.$U$5]="Ja"; [.$G12]=""); [.$I$15]; [.$G12]) + [.$I$14]) / [.$I$16] * [.$I$13] + [.$I$12])))], Ü!K12=-219.60075 [of:=IF(AND([.$G12]=""; [.$J12]=""); ""; IF([.$U$5]="Nein"; IF([.$G12]=""; ""; N([.$G12])-N([.$J12])); IF([.$G12]=""; IF(ue_est_modus="Nur Vermietung"; ""; ue_prgn_gw/COUNTIF(ue_gewinn_pro_monat;"")-N([.$J12])); N([.$G12])-N([.$J12]))))], Ü!N5 f:of:=IF(OR([.$K5] = ""; [.$K5] = 0; [.$M5] = ""…, Ü!M12 f:of:=IF(SUMIFS(h_stunden; h_monate; [.$C12]) = … |
| EUE-128 | Farbliche Hervorhebung der Auswertungen | Ü!E7 f:of:=IF(SUM([.E$12:.E$14])=0;""; SUM([.E$12:.E$…, BE-ART!Q3 f:of:=IF(SUM([.E3:.P3]) = 0;"";SUM([.E3:.P3])), ZA!E14 f:of:=IF(OR([.$B7]=""; AND([.E9]=""; [.E12]=""))…, Ü!E12 f:of:=IF(ue_est_modus="Nur Vermietung"; IF(SUMIF… |

### Sheet `AV`

| Rule | Title | ODS cell evidence |
|---|---|---|
| EUE-049 | Lineare Abschreibung und Halbjahresregel | AV!S3 f:of:=IF(OR(AND([.$N3]="";[.$P3]="";[.$Q3]=""); …, AV!N3conditional-format, AV!O3of:cell-content-is-decimal-number() and cell-content-is-between(2,99), AV!G3of:cell-content-is-in-list("Jänner";"Februar";"März";"April";"Mai";"Juni";"Juli";"August";"September";"Oktober";"November";"Dezember") |
| EUE-050 | Restbuchwertberechnung | AV!T3 f:of:=IF([.$N3]=""; ""; IF([.$O3]=0; IF([.$Q3]="…, AV!Q3conditional-format |
| EUE-051 | Geringwertige Wirtschaftsgüter (GWG) | AV!B3 f:of:=IF(AND([.$N3]="";[.$P3]="";[.$Q3]=""); "";…, AV!N3conditional-format |
| EUE-052 | Nutzungsjahre für Anlagegüter | AV!O3of:cell-content-is-decimal-number() and cell-content-is-between(2,99), AV!B3 f:of:=IF(AND([.$N3]="";[.$P3]="";[.$Q3]=""); "";… |
| EUE-053 | AfA-Kennzahl ist erforderlich | AV!B3 f:of:=IF(AND([.$N3]="";[.$P3]="";[.$Q3]=""); "";…, AV!V3 f:of:=IF(OR([.$M3]=""; COUNTIF(set_a_arten; [.$M… |
| EUE-054 | Mindestbetriebsanteil 50 % im Anlagenverzeichnis | AV!B3 f:of:=IF(AND([.$N3]="";[.$P3]="";[.$Q3]=""); "";…, AV!M3of:cell-content-is-in-list(set_a_arten) |
| EUE-055 | Privatanteil erfordert Entnahme im E-Blatt | AV!B3 f:of:=IF(AND([.$N3]="";[.$P3]="";[.$Q3]=""); "";…, E!E3conditional-format, AV!S3 f:of:=IF(OR(AND([.$N3]="";[.$P3]="";[.$Q3]=""); … |
| EUE-056 | AfA-Plausibilität gegen Kosten/Nutzungsjahre | AV!B3 f:of:=IF(AND([.$N3]="";[.$P3]="";[.$Q3]=""); "";…, AV!P3conditional-format |
| EUE-057 | Vorjahreskonsistenz Restbuchwert und AfA | AV!B3 f:of:=IF(AND([.$N3]="";[.$P3]="";[.$Q3]=""); "";…, AV!P3conditional-format, AV!Q3conditional-format |
| EUE-058 | Jahr eines Anlageguts darf nicht in der Zukunft liegen | AV!B3 f:of:=IF(AND([.$N3]="";[.$P3]="";[.$Q3]=""); "";…, AV!F3conditional-format |
| EUE-059 | Anlagen-Plausibilität Belegnummer/Datum/Bezeichnung | AV!B3 f:of:=IF(AND([.$N3]="";[.$P3]="";[.$Q3]=""); "";…, AV!E3conditional-format, AV!J3conditional-format |
| EUE-060 | Laufende Nummern im Anlagenverzeichnis | AV!D3 f:of:=IF([.S3]=""; ""; 1), AV!D4 f:of:=IF([.S4]=""; ""; IF(ROW()=3; 1; IF([.D3]<>… |
| EUE-061 | AfA-Kennzahlzuordnung im Anlagenverzeichnis | AV!V3 f:of:=IF(OR([.$M3]=""; COUNTIF(set_a_arten; [.$M…, AV!M3of:cell-content-is-in-list(set_a_arten) |

### Sheet `FR`

| Rule | Title | ODS cell evidence |
|---|---|---|
| EUE-062 | Kilometergeld PKW/Kombi | FR!AB3 f:of:=IF(OR([.$AA3]=""; [.$Y3]=""); "";  IF([.$Y…, FR!AC3 f:of:=IF(OR([.$AA3]="";[.AB3]=""); ""; [.AA3]*[.… |
| EUE-063 | Kilometergeld Motorrad | FR!AB3 f:of:=IF(OR([.$AA3]=""; [.$Y3]=""); "";  IF([.$Y… |
| EUE-064 | Kilometergeld Fahrrad/E-Bike | FR!AB3 f:of:=IF(OR([.$AA3]=""; [.$Y3]=""); "";  IF([.$Y… |
| EUE-065 | Kilometer-Kontingente | FR!Z3 f:of:=IF(OR(AND([.$P3]=""; [.$Q3]=""; [.$R3]="")…, FR!Z4 f:of:=IF(OR(AND([.$P4]=""; [.$Q4]=""; [.$R4]="")…, FR!AA3 f:of:=IF([.$Z3]=""; "";    MIN([.$Z3]; MAX(0; IF… |
| EUE-066 | Mitfahrerbegrenzung je Transportmittel | FR!AB3 f:of:=IF(OR([.$AA3]=""; [.$Y3]=""); "";  IF([.$Y…, FR!B3 f:of:=CONCATENATE( IF([.$BD3]<>"";   IF([.$F3]="…, FR!O3of:cell-content-is-decimal-number() and cell-content-is-between(0,10) |
| EUE-067 | Entfernung: KM-Stand oder Distanz | FR!P3conditional-format, FR!Q3conditional-format, FR!R3conditional-format, FR!B3 f:of:=CONCATENATE( IF([.$BD3]<>"";   IF([.$F3]="… |
| EUE-068 | Tagesgeld und Mahlzeitenabzug | FR!AN3 f:of:=IF(OR([.$AL3]=""; [.$AM3]=""); ""; MAX(SUM…, FR!AM3 f:of:=IF([.$AL3]=""; "";   INDEX(set_reisen_verp…, FR!V3of:cell-content-is-in-list("1 Mahlzeit";"2 Mahlzeiten") |
| EUE-069 | Nächtigungspauschale bei fehlenden Belegen | FR!AQ3 f:of:=IF([.$AP3]=""; "";    IF(AND([.$W3]=0; [.$…, FR!AR3 f:of:=IF(OR([.$AP3]=""; [.$AQ3]=""); "";    [.AQ… |
| EUE-070 | 5-/15-Tage-Regel für Diäten | FR!AF3=1 [of:=SUM([.$AI3]; N([.$AJ2]>0))], FR!AG3=1 [of:=SUM([.$AI3]; N([.$AJ2]>0))], FR!AH3 f:of:=IF([.$F3]=""; ""; IF(OR([.$G3]=""; [.$I3]=…, FR!AL3 f:of:=IF(OR([.$AH3]=""; AND([.$AI3]=""; [.$AJ3]=… |
| EUE-071 | Hin- und Rückfahrt-Regel | FR!AH3 f:of:=IF([.$F3]=""; ""; IF(OR([.$G3]=""; [.$I3]=…, FR!B4 f:of:=CONCATENATE( IF([.$BD4]<>"";   IF([.$F4]="…, FR!AT3 f:of:=IF([.$AV3]=""; "";  IF([.$K3]="Privat"; 0;… |
| EUE-072 | Betriebsanteil der Reisekosten (BT/PT) | FR!AW3 f:of:=IF([.$AH3]<>"Hin-Rückfahrt"; "";   IF(COUN…, FR!AX3 f:of:=IF([.$AW3]=""; "";   IF((SUMIF(fr_belege; …, FR!BA3 f:of:=IF(AND([.$S3]=""; [.$AC3]=""); "";  IF([.$…, FR!BD3 f:of:=IF(AND([.$BA3]=""; [.$BB3]=""; [.$BC3]="")… |
| EUE-073 | Private Veranlassung schließt Abzug aus | FR!BA3 f:of:=IF(AND([.$S3]=""; [.$AC3]=""); "";  IF([.$…, FR!AH3 f:of:=IF([.$F3]=""; ""; IF(OR([.$G3]=""; [.$I3]=…, FR!AT3 f:of:=IF([.$AV3]=""; "";  IF([.$K3]="Privat"; 0;… |
| EUE-074 | Werktage unter Berücksichtigung der Feiertage | FR!AV3 f:of:=IF(OR([.$E3]=""; [.$F3]=""); "";    IF(COU…, Einstellungen!H69 f:of:=DATE(set_wirtschaftsjahr;1;1) |
| EUE-075 | Reisejahr muss dem Wirtschaftsjahr entsprechen | FR!B3 f:of:=CONCATENATE( IF([.$BD3]<>"";   IF([.$F3]="…, FR!F3conditional-format, FR!H3conditional-format |
| EUE-076 | Zeitliche Konsistenz einer Reise | FR!B4 f:of:=CONCATENATE( IF([.$BD4]<>"";   IF([.$F4]="…, FR!G3conditional-format, FR!I3conditional-format |
| EUE-077 | Belegnummer einer Reise ist verpflichtend | FR!B3 f:of:=CONCATENATE( IF([.$BD3]<>"";   IF([.$F3]="…, FR!B4 f:of:=CONCATENATE( IF([.$BD4]<>"";   IF([.$F4]="…, FR!E3conditional-format |
| EUE-078 | Land/Region und Transportmittel sind Pflicht | FR!B4 f:of:=CONCATENATE( IF([.$BD4]<>"";   IF([.$F4]="…, FR!T3of:cell-content-is-in-list(set_reisen_laender), FR!N3of:cell-content-is-in-list(set_transportmittel) |
| EUE-079 | Veranlassung und Einsatzort bei Hin-/Rückfahrt | FR!B4 f:of:=CONCATENATE( IF([.$BD4]<>"";   IF([.$F4]="…, FR!K3of:cell-content-is-in-list("Privat"), FR!U3conditional-format |
| EUE-080 | Arbeitsstunden einer Reise aus Stundenbuch | FR!AE3=0 [of:=IF([.$E3]=""; 0; SUMIFS(fr_stunden; fr_belege; [.$E3]))], Stunden!F3(empty user input), FR!AH3 f:of:=IF([.$F3]=""; ""; IF(OR([.$G3]=""; [.$I3]=… |
| EUE-081 | Gesamte Reisekosten und Weiterleitung in die Auswertungen | FR!BA3 f:of:=IF(AND([.$S3]=""; [.$AC3]=""); "";  IF([.$…, FR!BB3 f:of:=IF([.$AN3]=""; ""; [.$AN3]), FR!BC3 f:of:=IF(AND([.$W3]=""; [.$AR3]=""); "";  IF([.$…, FR!BD3 f:of:=IF(AND([.$BA3]=""; [.$BB3]=""; [.$BC3]="")… |

### Sheet `WEB`

| Rule | Title | ODS cell evidence |
|---|---|---|
| EUE-045 | Wareneingangsbuch: Betrag und Betriebsanteil | WEB!P3 f:of:=IF(OR([.$M3]=""; [.$N3]=""; COUNTIF(set_a_…, WEB!Q3 f:of:=IF(OR([.$N3]=""; COUNTIF([.$E3];"WE*") = 0…, WEB!N3conditional-format |
| EUE-046 | Abgleich WEB ↔ Ausgabenjournal | WEB!B3 f:of:=IF([.$N3]=""; ""; CONCATENATE( IF([.$F3]="…, A!E3 txt:'A1', A!D3=1 [of:=IF(OR([.L3]=""; [.P3]=0); ""; 1)] |
| EUE-047 | WEB-Plausibilität und Artvalidierung | WEB!B3 f:of:=IF([.$N3]=""; ""; CONCATENATE( IF([.$F3]="…, WEB!H3conditional-format, WEB!I3conditional-format |

### Sheet `E1A`

| Rule | Title | ODS cell evidence |
|---|---|---|
| EUE-096 | Höchstgrenze des Grundfreibetrags im Formular E1A | E1A!J6=33000 [of:=IF(set_wirtschaftsjahr<2024; 30000; 33000)] |
| EUE-097 | Prozentsatz des Grundfreibetrags im Formular E1A | E1A!J7=0.15 [of:=IF(set_wirtschaftsjahr>=2022; 15%; 13%)] |
| EUE-098 | Grundfreibetragsberechnung E1A und Übersicht | E1A!K4=-58.8 [of:=SUM(e1a_betriebseinnahmen) - SUM(e1a_betriebsausgaben)], E1A!K5=0 [of:=MAX(MIN([.K4] * e1a_grundfreibetrag_satz; e1a_grundfreibetrag_grenze * e1a_grundfreibetrag_satz); 0)], Ü!U9=0 [of:=IF(ue_est_modus="Nur Vermietung"; ""; MAX(MIN(SUM([.U7:.U8]) * e1a_grundfreibetrag_satz; e1a_grundfreibetrag_grenze * e1a_grundfreibetrag_satz); 0))], Ü!U10=-705.6 [of:=IF(ue_est_modus="Nur Vermietung"; ""; SUM([.U7:.U8])-N([.U9]))] |
| EUE-099 | E1A-Einnahmen und -Ausgaben je Kennzahl | E1A!E11 f:of:=IF(SUM(e_betriebseinnahmen)=0; ""; SUM(e_b…, E1A!E17 f:of:=IF(AND(SUMIF(a_est_kennzahlen; [.$C17]&"*"…, E1A!E24 f:of:=IF(AND(SUMIF(a_est_kennzahlen; [.$C24]&"*"…, E1A!E38=0 [of:=IF(SUM(a_betriebsausgaben; av_betriebsausgaben; web_betriebsausgaben; fr_betriebsausgaben)=0; ""; SUM(a_betriebsausgaben; av_betriebsausgaben; web_betriebsausgaben; fr_betriebsausgaben) - SUM([.$E17:.$E37]) - SUMIF(a_est_kennzahlen; "E1B*"; a_betriebsausgaben) - SUMIF(av_est_kennzahlen; "E1B*"; av_betriebsausgaben))] |
| EUE-101 | Arbeitsplatzpauschale (E1A 9215/9217) | E1A!C40 f:of:=IF(AND([.$E32]<>""; [.$E32]<>0); IF([.$E40…, E1A!D40 f:of:=IF(AND([.$E32]<>""; [.$E32]<>0); IF([.$E40…, E1A!E40of:cell-content-is-decimal-number() and cell-content-is-between(25,1200) |
| EUE-102 | Ergonomisches Mobiliar (E1A 9216) | E1A!C39 f:of:=IF(AND([.$E32]<>""; [.$E32]<>0); "";    IF…, E1A!D39 f:of:=IF(AND([.$E32]<>""; [.$E32]<>0); IF([.$E40…, E1A!E39of:cell-content-is-decimal-number() and cell-content-is-between(0,300) |

### Sheet `E1B`

| Rule | Title | ODS cell evidence |
|---|---|---|
| EUE-100 | E1B Vermietung und Verpachtung | E1B!E7 f:of:=IF(SUMIF(e_est_kennzahlen; "E1B*"; e_betri…, E1B!E10 f:of:=IF(SUMIF(av_est_kennzahlen; "E1B "&[.$C10]…, E1B!E15 f:of:=IF(SUMIF(a_est_kennzahlen; "E1B*"; a_betri…, E1B!E18=0 [of:=SUM(e1b_einnahmen) - SUM(e1b_werbungskosten)] |

### Sheet `UST`

| Rule | Title | ODS cell evidence |
|---|---|---|
| EUE-082 | Kennzahlen der Umsatzsteuervoranmeldung | UST!D5 txt:'000', UST!D6 txt:'016', UST!D7 txt:'057', UST!D10 txt:'070' |
| EUE-083 | Kleinunternehmer-Umsätze (KZ 016) | UST!E6 f:of:=IF(SUMIFS(e_betriebseinnahmen; e_monate; […, UST!E5 f:of:=IF([.E6]=""; ""; [.E6]) |
| EUE-084 | Reverse Charge in der USt-Voranmeldung (KZ 057) | UST!E7 f:of:=IF(SUMIFS(a_ust_rc; a_ust_arten; "Reverse …, A!S3 f:of:=IF(OR([.$L3]=""; [.$M3]=""); ""; IF([.$M3]… |
| EUE-085 | Innergemeinschaftliche Erwerbe nach Steuersatz | UST!E11 f:of:=IF(SUMIFS(a_brutto; a_ust_arten; "IG-Erwer…, UST!E14 f:of:=IF(SUMIFS(a_brutto; a_ust_arten; "IG-Erwer…, UST!E10 f:of:=IF(SUM([.E$11:.E$14]) = 0; ""; SUM([.E$11:… |
| EUE-086 | Österreichische Umsatzsteuer-Zahllast | UST!E20 f:of:=IF(AND([.E7]=""; [.E11]=""; [.E12]=""; [.E…, UST!C20 txt:'Österreichische Umsatzsteuer (Zahllast /' |
| EUE-087 | Quartalsaggregation der USt | UST!H5 f:of:=IF(SUM([.E5:.G5]) = 0; ""; SUM([.E5:.G5])), UST!X5 f:of:=IF(SUM([.U5:.W5]) = 0; ""; SUM([.U5:.W5])), UST!E4 txt:'JÄNNER' |
| EUE-088 | Erste und zweite USt-Erklärung | UST!C2 f:of:=UPPER("1. Umsatzsteuervoranmeldung"), UST!AB2 f:of:=UPPER("2. Umsatzsteuererklärung") |

### Sheet `BE-ART`

| Rule | Title | ODS cell evidence |
|---|---|---|
| EUE-119 | Betriebseinnahmen nach Art (BE-ART) | BE-ART!E3 f:of:=IF(SUMIFS(e_betriebseinnahmen; e_einnahmen…, BE-ART!Q154 f:of:=IF([.Q$153]="";""; IF(ROUND(SUM(e_betriebs…, BE-ART!E154 f:of:=IF([.E$153]="";""; IF(ROUND(SUMIFS(e_betri… |
| EUE-129 | Vollständigkeitsabgleich der Journale | BE-ART!E154 f:of:=IF([.E$153]="";""; IF(ROUND(SUMIFS(e_betri…, BA-ART!E155 f:of:=IF([.E$154]=""; ""; IF(ROUND(SUMIFS(a_betr…, ZA!E14 f:of:=IF(OR([.$B7]=""; AND([.E9]=""; [.E12]=""))…, E1A!E38=0 [of:=IF(SUM(a_betriebsausgaben; av_betriebsausgaben; web_betriebsausgaben; fr_betriebsausgaben)=0; ""; SUM(a_betriebsausgaben; av_betriebsausgaben; web_betriebsausgaben; fr_betriebsausgaben) - SUM([.$E17:.$E37]) - SUMIF(a_est_kennzahlen; "E1B*"; a_betriebsausgaben) - SUMIF(av_est_kennzahlen; "E1B*"; av_betriebsausgaben))] |

### Sheet `BA-ART`

| Rule | Title | ODS cell evidence |
|---|---|---|
| EUE-120 | Betriebsausgaben nach Art (BA-ART) | BA-ART!E3 f:of:=IF(AND(SUMIFS(a_betriebsausgaben; a_ausgab…, BA-ART!E153 f:of:=IF(SUMIFS(fr_betriebsausgaben; fr_monate; …, BA-ART!Q155 f:of:=IF([.Q$154] = ""; ""; IF(ROUND(SUM(a_betri… |

### Sheet `Projekte`

| Rule | Title | ODS cell evidence |
|---|---|---|
| EUE-121 | Projekte-Auswertung | Projekte!E3 f:of:=IF(SUMIFS(e_betriebseinnahmen; e_projekte;…, Projekte!E10 f:of:=IF(SUM(SUMIFS(a_betriebsausgaben; a_projek…, Projekte!D5=-58.8 [of:=IF(AND([.D3] = ""; [.D4] = "" );""; N([.D3]) - N([.D4]))], Projekte!D78 f:of:=IF(SUM(h_stunden)=0;"";SUM(h_stunden)-SUM(… |

### Sheet `ZA`

| Rule | Title | ODS cell evidence |
|---|---|---|
| EUE-122 | Zahlarten-Abgleich (ZA) | ZA!E7 f:of:=IF([.$B7] = ""; ""; IF(AND(SUMIFS(e_brutto…, ZA!E10=58.8 [of:=IF([.$B7] = ""; ""; IF(AND(SUMIFS(a_brutto; a_zahlarten; [.$B7]; a_monate; [.E$1]; a_brutto; ">0")=0;  SUMIFS(web_preise; web_zahlarten; [.$B7]; web_belegnummern; "WE*"; web_monate; [.E$1]; web_preise; ">0")=0;  SUMIFS(e_brutto; e_zahlarten; [.$B7]; e_monate; [.E$1]; e_brutto;"<0")=0); "";  SUMIFS(a_brutto; a_zahlarten; [.$B7]; a_monate; [.E$1]; a_brutto; ">0") +  SUMIFS(web_preise; web_zahlarten; [.$B7]; web_belegnummern; "WE*"; web_monate; [.E$1]; web_preise; ">0") -  SUMIFS(e_brutto; e_zahlarten; [.$B7]; e_monate; [.E$1]; e_brutto; "<0")) )], ZA!E13=-58.8 [of:=IF(OR([.$B7]=""; AND([.E7]=""; [.E8]=""; [.E10]=""; [.E11]="")); "";  N(INDEX(set_zahlarten_startsaldo; MATCH([.$B7]; set_zahlarten; 0); 0)) +  SUM([.$E7:.E7]) + SUM([.$E8:.E8]) - SUM([.$E10:.E10]) - SUM([.$E11:.E11]))], ZA!E14 f:of:=IF(OR([.$B7]=""; AND([.E9]=""; [.E12]=""))… |

### Sheet `Monat`

| Rule | Title | ODS cell evidence |
|---|---|---|
| EUE-116 | Monatsblatt-Schalter | Monat!P5 txt:'OFF', Monat!P7 f:of:=IF(OR(m_monat= ""; m_monat = "OFF"); "💡 Re…, Monat!A404 f:of:=IF(OR(m_monat="OFF"; m_monat=""); ""; IFER… |
| EUE-117 | Monatsübersicht Gewinn und Stunden | Monat!J13 f:of:=IF(OR(m_monat="OFF"; m_monat=""); ""; SUMI…, Monat!J16 f:of:=IF(OR(m_monat="OFF"; m_monat=""); "";SUMIF…, Monat!J20 f:of:=IF(OR(m_monat="OFF"; m_monat=""); ""; SUMI…, Monat!O29 f:of:=IF(OR(m_monat="OFF"; m_monat=""); ""; INDE… |
| EUE-118 | Zeilenfilter der Monatsansicht | Monat!A254 f:of:=IF(OR(m_monat="OFF"; m_monat=""); ""; IFER…, Monat!A104 f:of:=IF(OR(m_monat="OFF"; m_monat=""); ""; IFER…, Monat!A54 f:of:=IFERROR( IF(OR(m_monat="OFF"; m_monat="");…, Monat!D404 f:of:=IF(OR(m_monat="OFF"; m_monat=""; [.$A404]=… |
| EUE-124 | USt-Zeilen in der Monatsansicht | Monat!O29 f:of:=IF(OR(m_monat="OFF"; m_monat=""); ""; INDE…, UST!D5 txt:'000', UST!E4 txt:'JÄNNER' |

### Sheet `Stunden`

| Rule | Title | ODS cell evidence |
|---|---|---|
| EUE-123 | Stundenbuch und Zwölfteleinheiten | Stunden!C3of:cell-content-is-in-list("Jänner";"Februar";"März";"April";"Mai";"Juni";"Juli";"August";"September";"Oktober";"November";"Dezember"), Stunden!F3(empty user input), Ü!M12 f:of:=IF(SUMIFS(h_stunden; h_monate; [.$C12]) = … |
