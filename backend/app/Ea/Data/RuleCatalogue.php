<?php

declare(strict_types=1);

namespace App\Ea\Data;

/**
 * GENERIERT von `php artisan ea:export-data` aus dem gepinnten
 * Regelartefakt — nicht von Hand ändern. Die Quelldatei kennt nur der Generator.
 *
 * Der gepinnte Regelkatalog: je Regel-ID der Formeltext und die Parameter.
 * Nur diese drei Felder werden gebraucht; alles andere aus dem
 * Katalog bleibt im Artefakt.
 */
final class RuleCatalogue
{
    /**
     * @var array<string, array{logik: string, parameter: list<array{name: string, jahre: list<int>, wert: int|float|string}>}>
     */
    public const RULES = [
        'EUE-001' => [
            'logik' => 'Datenvalidierung Liste \'2024,2025,2026\' für D2; definierter Name set_wirtschaftsjahr = Einstellungen!$D$2; Bezeichnung C2=\'WIRTSCHAFTSJAHR\'; B2 = IF(D2="","","❯").',
            'parameter' => [
                [
                    'name' => 'Wirtschaftsjahr',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 2026,
                ],
            ],
        ],
        'EUE-002' => [
            'logik' => 'D1 = IF(set_wirtschaftsjahr>2026, \'⚠ Die Tabelle nicht offiziell mit \' & set_wirtschaftsjahr & \' kompatibel. ... auf die neueste Version der Tabelle umsteigen.\', IF(set_wirtschaftsjahr<2024, ..., \'\')).',
            'parameter' => [
                [
                    'name' => 'Obergrenze',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 2026,
                ],
            ],
        ],
        'EUE-003' => [
            'logik' => 'D1 = IF(set_wirtschaftsjahr>2026, ..., IF(set_wirtschaftsjahr<2024, \'⚠ Die Tabelle nicht offiziell mit \' & set_wirtschaftsjahr & \' kompatibel. Kontaktieren Sie uns für den Zugang zu einer älteren Version (Kauf der Lifetime-Lizenz erforderlich).\', \'\')).',
            'parameter' => [
                [
                    'name' => 'Untergrenze',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 2024,
                ],
            ],
        ],
        'EUE-004' => [
            'logik' => 'set_firmenname=D3, set_anschrift=D4, set_plz_ort=D5, set_uid=D6, set_branchenkennzahl=D7 (Validierung 11.0–990.0). E1A!C6 zeigt die Kennzahl oder den Hinweis \'im Blatt Einstellungen hinterlegen\'; Monat!H4:H7 spiegeln die Werte.',
            'parameter' => [
                [
                    'name' => 'Branchenkennzahl Minimum',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 11,
                ],
                [
                    'name' => 'Branchenkennzahl Maximum',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 990,
                ],
            ],
        ],
        'EUE-005' => [
            'logik' => 'set_zahlarten=H3:H12; set_zahlarten_startsaldo=I3:I12; ZA!E13 = N(INDEX(set_zahlarten_startsaldo,MATCH($B7,set_zahlarten,0),0)) + Zuflüsse − Abflüsse.',
            'parameter' => [],
        ],
        'EUE-006' => [
            'logik' => 'set_projekte=L3:L22; Datenvalidierung E!K3:K1000, A!J3:J1000, AV!L3:L50, FR!J3:J102, WEB!L3:L100, Stunden!E3:E199 = set_projekte; Projekte!E1 = UPPER(INDEX(set_projekte,1,0)).',
            'parameter' => [],
        ],
        'EUE-007' => [
            'logik' => 'Einstellungen!P3:P152 = set_a_arten (Dropdown-Quelle); Q3:Q152 = set_a_e1a_arten enthält statische Literale wie \'9100 - Waren\'. Die TRIM/INDEX-Formeln, die diese Kennzahlen aus e1a_ausgaben_kz erzeugen, stehen in N3:N25 (z. B. N3 = TRIM(INDEX(e1a_ausgaben_kz,1,1))&\' - \'&TRIM(INDEX(e1a_ausgaben_kz,1,2))); N3:N30 ist zugleich set_est_ausgaben (Validierungsquelle). A!O3 = IFERROR(IF(INDEX(set_a_e1a_arten,MATCH(K3,set_a_arten,0))="","9230 - Übrige Betriebsausgaben",...),"").',
            'parameter' => [],
        ],
        'EUE-008' => [
            'logik' => 'Einstellungen!Z3:Z152 = set_e_arten; AA3:AA152 = set_e_e1a_arten enthält statische Literale (z. B. AA3=\'9040 - Betriebseinnahmen\'); die TRIM/INDEX-Formeln stehen in X3:X6 (9040/9050/9060/9090), X7 erzeugt \'E1B 9460 - Mieteinnahmen\'. X3:X7 ist zugleich set_est_einnahmen (Validierungsquelle). E!O3 = IFERROR(IF(INDEX(set_e_e1a_arten,MATCH(L3,set_e_arten,0))="","9040 - Betriebseinnahmen",INDEX(set_e_e1a_arten,MATCH(L3,set_e_arten,0))),"").',
            'parameter' => [],
        ],
        'EUE-009' => [
            'logik' => 'Einstellungen!R3:R152 = set_a_ba, Datenvalidierung decimal 0.0–1.0; A!P3 = INDEX(set_a_ba,MATCH(K3,set_a_arten,0),0); A!Q3 = L3 * IF(P3="",1,P3). GST-Text \'0 %,100 %\' auf AB3:AB152 für Einnahmen-Anteile.',
            'parameter' => [
                [
                    'name' => 'Anteil Minimum',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 0.0,
                ],
                [
                    'name' => 'Anteil Maximum',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 1.0,
                ],
                [
                    'name' => 'Laptop/Drucker betrieblich',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 0.6,
                ],
                [
                    'name' => 'Geschäftsessen/Klimaticket',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 0.5,
                ],
                [
                    'name' => 'Unternehmer-Lohn/Privat',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 0.0,
                ],
            ],
        ],
        'EUE-010' => [
            'logik' => 'set_a_ust_arten=Einstellungen!AC3:AC9 = \'Reverse Charge 20 %\', \'Reverse Charge 13 %\', \'Reverse Charge 10 %\', \'IG-Erwerb 20 %\', \'IG-Erwerb 10 %\', \'IG-Erwerb 13 %\', \'IG-Erwerb 4.9 %\'; Validierung A!M3:M1000 = set_a_ust_arten.',
            'parameter' => [],
        ],
        'EUE-011' => [
            'logik' => 'set_transportmittel=U3:U17; set_transportmittel_typen=V3:V17 (Validierung \'PKW oder Kombi,Motorrad,Fahrrad oder E-Bike,Andere\'); set_fahrzeuge=U3:U7; FR!Y3 = IFERROR(IF(INDEX(set_transportmittel_typen,MATCH(N3,set_transportmittel,0),0)="","Andere",INDEX(set_transportmittel_typen,MATCH(N3,set_transportmittel,0),0)),""); Einstellungen!T3 = IF(U3="","",IF(V3="","⚠","❯")) meldet einen fehlenden Transportmittel-Typ.',
            'parameter' => [],
        ],
        'EUE-012' => [
            'logik' => 'H69=DATE(j,1,1), H70=DATE(j,1,6), H71=DATE(j,3,22+MOD(19*MOD(j,19)+24,30)+MOD(2*MOD(j,4)+4*MOD(j,7)+6*MOD(19*MOD(j,19)+24,30)+5,7)+1) (Ostersonntag), H73=+39 (Christi Himmelfahrt), H74=+50 (Pfingstmontag), H75=+60 (Fronleichnam), H76=DATE(j,8,15), H77=DATE(j,10,26), H78=DATE(j,11,1), H79=DATE(j,12,8), H80=DATE(j,12,25), H81=DATE(j,12,26); set_feiertage=H69:H88; FR!AV3 nutzt NETWORKDAYS(...,set_feiertage).',
            'parameter' => [
                [
                    'name' => 'Anzahl Feiertage',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 13,
                ],
            ],
        ],
        'EUE-013' => [
            'logik' => 'set_reisen_laender=C58:C231; set_reisen_verpfelgungspauschalen=D58:D231; set_reisen_naechtigungen=E58:E231; FR!AM3 = INDEX(set_reisen_verpfelgungspauschalen,MATCH(T3,set_reisen_laender,0),0); FR!AQ3 = INDEX(set_reisen_naechtigungen,MATCH(T3,set_reisen_laender,0),0).',
            'parameter' => [
                [
                    'name' => 'Inland Tagespauschale',
                    'jahre' => [2024],
                    'wert' => 26.4,
                ],
                [
                    'name' => 'Inland Tagespauschale',
                    'jahre' => [2025],
                    'wert' => 30,
                ],
                [
                    'name' => 'Inland Tagespauschale',
                    'jahre' => [2026],
                    'wert' => 30,
                ],
                [
                    'name' => 'Inland Nächtigungspauschale (Ländertabelle)',
                    'jahre' => [2024],
                    'wert' => 15,
                ],
                [
                    'name' => 'Inland Nächtigungspauschale (Ländertabelle)',
                    'jahre' => [2025],
                    'wert' => 17,
                ],
                [
                    'name' => 'Inland Nächtigungspauschale (Ländertabelle)',
                    'jahre' => [2026],
                    'wert' => 17,
                ],
            ],
        ],
        'EUE-014' => [
            'logik' => 'set_sv_bgl_max=Einstellungen!I57 = IF(j=2026,8085,IF(j=2025,7525,IF(j=2024,7070,IF(j=2023,6825,\'?\')))); Ü!AA9 = MIN(SUM(AA7:AA8),set_sv_bgl_max*12); Ü!Y10 = IF(set_sv_bgl_max*12=ue_sv_bmg,\'Wow! Maximale Beitragsgrundlage erreicht.\',\'\').',
            'parameter' => [
                [
                    'name' => 'maximale Beitragsgrundlage',
                    'jahre' => [2024],
                    'wert' => 7070,
                ],
                [
                    'name' => 'maximale Beitragsgrundlage',
                    'jahre' => [2025],
                    'wert' => 7525,
                ],
                [
                    'name' => 'maximale Beitragsgrundlage',
                    'jahre' => [2026],
                    'wert' => 8085,
                ],
            ],
        ],
        'EUE-015' => [
            'logik' => 'set_sv_kv_satz=Einstellungen!I58 = IF(j=2026,6.8%,IF(j=2025,6.8%,IF(j=2024,6.8%,IF(j=2023,6.8%,\'?\')))); Ü!Z14 = IF(OR(Jungunternehmer=\'Ja\',SV-Modis=\'Nur Unfallversicherung\'),\'-\',set_sv_kv_satz); Ü!AA14 = MAX(ue_sv_bmg*ue_sv_kv_satz,set_sv_kv_min_beitrag*12).',
            'parameter' => [
                [
                    'name' => 'KV-Satz',
                    'jahre' => [2024],
                    'wert' => '6,8 %',
                ],
                [
                    'name' => 'KV-Satz',
                    'jahre' => [2025],
                    'wert' => '6,8 %',
                ],
                [
                    'name' => 'KV-Satz',
                    'jahre' => [2026],
                    'wert' => '6,8 %',
                ],
            ],
        ],
        'EUE-016' => [
            'logik' => 'set_sv_kv_min_beitrag=Einstellungen!I59 = IF(j=2026,37.47,IF(j=2025,37.47,IF(j=2024,35.45,IF(j=2023,34.06,\'?\')))); Ü!AA14 nutzt MAX(BMG*KV%,I59*12); Ü!AA13 nutzt I59/I58*12*SVS%.',
            'parameter' => [
                [
                    'name' => 'Mindest-KV-Beitrag',
                    'jahre' => [2024],
                    'wert' => 35.45,
                ],
                [
                    'name' => 'Mindest-KV-Beitrag',
                    'jahre' => [2025],
                    'wert' => 37.47,
                ],
                [
                    'name' => 'Mindest-KV-Beitrag',
                    'jahre' => [2026],
                    'wert' => 37.47,
                ],
            ],
        ],
        'EUE-017' => [
            'logik' => 'set_sv_gsvg_pv_min_beitrag=I60, set_sv_gsvg_ns_pv_min_beitrag=I61, beide = IF(j=2026,101.95,IF(j=2025,101.95,IF(j=2024,95.91,IF(j=2023,92.67,\'?\')))); Ü!AA15 = MAX(BMG*PV%, mind*12).',
            'parameter' => [
                [
                    'name' => 'Mindest-GSVG-PV',
                    'jahre' => [2024],
                    'wert' => 95.91,
                ],
                [
                    'name' => 'Mindest-GSVG-PV',
                    'jahre' => [2025],
                    'wert' => 101.95,
                ],
                [
                    'name' => 'Mindest-GSVG-PV',
                    'jahre' => [2026],
                    'wert' => 101.95,
                ],
            ],
        ],
        'EUE-018' => [
            'logik' => 'set_sv_fsvg_pv_min_beitrag=I62 = IF(j=2026,110.22,IF(j=2025,110.22,IF(j=2024,103.69,IF(j=2023,100.18,\'?\')))); Ü!AA15 = MAX(BMG*20%,I62*12) bei FSVG.',
            'parameter' => [
                [
                    'name' => 'Mindest-FSVG-PV',
                    'jahre' => [2024],
                    'wert' => 103.69,
                ],
                [
                    'name' => 'Mindest-FSVG-PV',
                    'jahre' => [2025],
                    'wert' => 110.22,
                ],
                [
                    'name' => 'Mindest-FSVG-PV',
                    'jahre' => [2026],
                    'wert' => 110.22,
                ],
            ],
        ],
        'EUE-019' => [
            'logik' => 'set_sv_gsvg_pv_satz=I63 = IF(j=2026,18.5%,IF(j=2025,18.5%,IF(j=2024,18.5%,IF(j=2023,18.5%,\'?\')))); Ü!Z15 = IF(SV-Modis=\'Nur Unfallversicherung\',\'-\',IF(SV-Modis=\'Freiberufliche (FSVG)\',set_sv_fsvg_pv_satz,set_sv_gsvg_pv_satz)).',
            'parameter' => [
                [
                    'name' => 'GSVG-PV-Satz',
                    'jahre' => [2024],
                    'wert' => '18,5 %',
                ],
                [
                    'name' => 'GSVG-PV-Satz',
                    'jahre' => [2025],
                    'wert' => '18,5 %',
                ],
                [
                    'name' => 'GSVG-PV-Satz',
                    'jahre' => [2026],
                    'wert' => '18,5 %',
                ],
            ],
        ],
        'EUE-020' => [
            'logik' => 'set_sv_fsvg_pv_satz=I64 = IF(j=2026,20%,IF(j=2025,20%,IF(j=2024,20%,IF(j=2023,20%,\'?\')))); Ü!Z15 wählt diesen Satz bei FSVG.',
            'parameter' => [
                [
                    'name' => 'FSVG-PV-Satz',
                    'jahre' => [2024],
                    'wert' => '20 %',
                ],
                [
                    'name' => 'FSVG-PV-Satz',
                    'jahre' => [2025],
                    'wert' => '20 %',
                ],
                [
                    'name' => 'FSVG-PV-Satz',
                    'jahre' => [2026],
                    'wert' => '20 %',
                ],
            ],
        ],
        'EUE-021' => [
            'logik' => 'set_sv_svs_satz=I65 = IF(j=2026,1.53%,IF(j=2025,1.53%,IF(j=2024,1.53%,IF(j=2023,1.53%,\'?\')))); Ü!Z13 = IF(OR(ue_sv_modus=\'Freiberufliche (FSVG)\',ue_sv_modus=\'Nur Unfallversicherung\'),\'-\',set_sv_svs_satz).',
            'parameter' => [
                [
                    'name' => 'SVS-Satz',
                    'jahre' => [2024],
                    'wert' => '1,53 %',
                ],
                [
                    'name' => 'SVS-Satz',
                    'jahre' => [2025],
                    'wert' => '1,53 %',
                ],
                [
                    'name' => 'SVS-Satz',
                    'jahre' => [2026],
                    'wert' => '1,53 %',
                ],
            ],
        ],
        'EUE-022' => [
            'logik' => 'set_sv_uv=I66 = IF(j=2026,12.95,IF(j=2025,12.07,IF(j=2024,11.35,IF(j=2023,10.97,\'?\')))); Ü!AA12 = set_sv_uv*12; Ü!I12 = IF(ue_sv_modus=\'Nur Unfallversicherung\',set_sv_uv,SUM(set_sv_uv,...)).',
            'parameter' => [
                [
                    'name' => 'UV-Pauschale',
                    'jahre' => [2024],
                    'wert' => 11.35,
                ],
                [
                    'name' => 'UV-Pauschale',
                    'jahre' => [2025],
                    'wert' => 12.07,
                ],
                [
                    'name' => 'UV-Pauschale',
                    'jahre' => [2026],
                    'wert' => 12.95,
                ],
            ],
        ],
        'EUE-023' => [
            'logik' => 'Einstellungen!N3:N30 enthält 28 Zeilen, aber nur 27 unterschiedliche Kennzahlen (9135 kommt doppelt vor: N8 E1A, N26 E1B); E1A!C17:C38 listet die 22 E1A-Kennzahlen, E1B!C10:C15 die sechs E1B-Kennzahlen 9500/9135/9510/9520/9521/9530; E1A!E33 = Pflichtversicherungen (9225).',
            'parameter' => [
                [
                    'name' => 'Anzahl unterschiedlicher Ausgaben-Kennzahlen',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 27,
                ],
            ],
        ],
        'EUE-024' => [
            'logik' => 'X3:X7 in Einstellungen = \'9040 - Betriebseinnahmen\', \'9050 - Freie Dienstverträge\', \'9060 - Erlös abgeg. Anlagegüter\', \'9090 - Übrige Betriebseinnahmen\', \'E1B 9460 - Mieteinnahmen\'; E1A!C11:C14 übernimmt sie.',
            'parameter' => [],
        ],
        'EUE-025' => [
            'logik' => 'Einstellungen!H1 = IF(SUMPRODUCT(N(COUNTIF(set_zahlarten,set_zahlarten)>1))>0,\'⚠ Zahlarten: Bitte eines der Duplikate in den Zeilen\' & CONCATENATE(...) & \' entfernen\',\'\').',
            'parameter' => [],
        ],
        'EUE-026' => [
            'logik' => 'Einstellungen!L1 = IF(SUMPRODUCT(N(COUNTIF(set_projekte,set_projekte)>1))>0,\'⚠ Projekte: Bitte eines der Duplikate in den Zeilen\' & CONCATENATE(...),\'\').',
            'parameter' => [],
        ],
        'EUE-027' => [
            'logik' => 'Einstellungen!P1 = IF(SUMPRODUCT(N(COUNTIF(set_a_arten,set_a_arten)>1))>0,\'⚠ Ausgaben-Arten: Bitte Duplikate entfernen:\' & CONCATENATE(IF((COUNTIF(...)>1)*(MATCH(set_a_arten,set_a_arten,0)=ROW(...)-MIN(ROW(...))+1),\' [\'&set_a_arten&\']\',\'\')),\'\').',
            'parameter' => [],
        ],
        'EUE-028' => [
            'logik' => 'Einstellungen!Z1 = IF(SUMPRODUCT(N(COUNTIF(set_e_arten,set_e_arten)>1))>0,\'⚠ Einnahmen-Arten: Bitte eines der Duplikate in den Zeilen\' & CONCATENATE(...),\'\').',
            'parameter' => [],
        ],
        'EUE-029' => [
            'logik' => 'Einstellungen!O3:O152 = IF(P3="","",IF(SUM(COUNTIF(a_ausgaben_arten,P3),COUNTIF(av_ausgaben_arten,P3),COUNTIF(web_ausgaben_arten,P3))=0,\'❯\',IF(...>99,\'99+\',SUM(...)))); Y3:Y152 analog mit COUNTIF(e_einnahmen_arten,Z3) und \'999+\'.',
            'parameter' => [
                [
                    'name' => 'Schwellwert Ausgaben',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 99,
                ],
                [
                    'name' => 'Schwellwert Einnahmen',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 999,
                ],
            ],
        ],
        'EUE-030' => [
            'logik' => 'Einstellungen!R3:R50 decimal 0.0–1.0; E!G3:G1000, AV!H3:H50 und WEB!G3:G100 haben Tag decimal 1.0–31.0 (A!G und das Reiseblatt haben keine Tagesvalidierung; A!B3 meldet nur \'• Tag fehlt\'); AV!O3:O50 decimal 2.0–99.0; FR!O3:O102 decimal 0.0–10.0; FR!V3:V102 Liste \'1 Mahlzeit,2 Mahlzeiten\'; E1A!E39 decimal 0.0–300.0; E1A!E40 decimal 25.0–1200.0; Monat!P5 Liste \'OFF,Jänner,...,Dezember\'.',
            'parameter' => [
                [
                    'name' => 'Tag Minimum/Maximum (E/AV/WEB)',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '1–31',
                ],
                [
                    'name' => 'Nutzungsjahre',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '2–99',
                ],
                [
                    'name' => 'Mitfahrer',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '0–10',
                ],
                [
                    'name' => 'Betriebsanteil',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '0–1',
                ],
                [
                    'name' => 'Arbeitsplatzpauschale',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '25–1200',
                ],
            ],
        ],
        'EUE-031' => [
            'logik' => 'E!Q3 = IF(M3="","",M3*IF(P3="",1,P3)); E!P3 = IF(OR(M3="",L3="",COUNTIF(set_e_arten,L3)=0),"",IF(INDEX(set_e_ba,MATCH(L3,set_e_arten,0),0)="","",INDEX(set_e_ba,MATCH(L3,set_e_arten,0),0))).',
            'parameter' => [],
        ],
        'EUE-032' => [
            'logik' => 'E!O3 = IF(OR(L3="",M3="",P3=0),"",IFERROR(IF(INDEX(set_e_e1a_arten,MATCH(L3,set_e_arten,0))="","9040 - Betriebseinnahmen",INDEX(set_e_e1a_arten,MATCH(L3,set_e_arten,0))),"")).',
            'parameter' => [],
        ],
        'EUE-033' => [
            'logik' => 'Aus Einstellungen!AB3:AB152: \'Private Einnahme\' und \'Private Einzahlung\' haben Anteil 0; E!O3 setzt die Kennzahl nur, wenn P3<>0.',
            'parameter' => [
                [
                    'name' => 'Betriebsanteil Privat',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 0,
                ],
            ],
        ],
        'EUE-034' => [
            'logik' => 'E!O3 liefert \'E1B 9460 - Mieteinnahmen\'; E1B!E7 = IF(SUMIF(e_est_kennzahlen,\'E1B*\',e_betriebseinnahmen)=0,\'\',SUMIF(e_est_kennzahlen,\'E1B*\',e_betriebseinnahmen)); Ü!U12 = SUM(e1b_einnahmen)-SUM(e1b_werbungskosten).',
            'parameter' => [],
        ],
        'EUE-035' => [
            'logik' => 'E!D3 = IF(OR(M3="",P3=0),"",1); E!D4 = IF(OR(M4="",P4=0),"",IF(ROW()=3,1,IF(D3<>"",D3+1,COUNTIFS(M$3:M3,"<>",P$3:P3,"<>0")+1))).',
            'parameter' => [],
        ],
        'EUE-036' => [
            'logik' => 'E!B3 = IF(M3="","",CONCATENATE(IF(AND(E3="",Q3<>0),\'• Belegnummer fehlt.\'&CHAR(10),\'\'),IF(AND(G3="",Q3<>0),\'• Tag fehlt.\'&CHAR(10),\'\'),IF(F3=\'\',\'⚠ Monat fehlt.\',IF(ISNA(MATCH(F3,{Jänner...Dezember},0)),\'⚠ Ungültiger Monat.\',\'\')),IF(L3=\'\',\'⚠ Einnahmen-Art fehlt.\',IF(COUNTIF(set_e_arten,L3)>1,\'⚠ ... mehrfach vorhanden.\',IF(COUNTIF(set_e_arten,L3)=0,\'⚠ ... nicht definiert.\',\'\'))))).',
            'parameter' => [],
        ],
        'EUE-037' => [
            'logik' => 'Datenvalidierung E!F3:F1000/A!F3:F1000/AV!G3:G50/WEB!F3:F100 = \'Jänner,...,Dezember\'; Tag E!G3:G1000/AV!H3:H50/WEB!G3:G100 decimal 1.0–31.0 (A!G ohne Validierung; das Reiseblatt hat weder Monats- noch Tagesvalidierung); Formelchecks \'⚠ Ungültiger Monat.\' in E!B3 und A!B3 sowie \'• Tag fehlt\' in A!B3.',
            'parameter' => [],
        ],
        'EUE-038' => [
            'logik' => 'A!Q3 = IF(L3="","",L3*IF(P3="",1,P3)); A!P3 = IF(OR(K3="",J3="",COUNTIF(set_a_arten,J3)=0),"",IF(INDEX(set_a_ba,MATCH(J3,set_a_arten,0),0)="","",INDEX(set_a_ba,MATCH(J3,set_a_arten,0),0))).',
            'parameter' => [],
        ],
        'EUE-039' => [
            'logik' => 'A!R3 = IF(OR(L3="",M3=""),"",IF(M3=\'IG-Erwerb 20 %\',L3*0.2,IF(M3=\'IG-Erwerb 10 %\',L3*0.1,IF(M3=\'IG-Erwerb 13 %\',L3*0.13,IF(M3=\'IG-Erwerb 4.9 %\',L3*0.049,\'\'))))).',
            'parameter' => [
                [
                    'name' => 'IG-Sätze',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '20/10/13/4,9 %',
                ],
            ],
        ],
        'EUE-040' => [
            'logik' => 'A!S3 = IF(OR(L3="",M3=""),"",IF(M3=\'Reverse Charge 20 %\',L3*0.2,IF(M3=\'Reverse Charge 10 %\',L3*0.1,IF(M3=\'Reverse Charge 13 %\',L3*0.13,\'\')))).',
            'parameter' => [
                [
                    'name' => 'RC-Sätze',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '20/10/13 %',
                ],
            ],
        ],
        'EUE-041' => [
            'logik' => 'A!O3 = IF(OR(L3="",K3="",P3=0),"",IFERROR(IF(INDEX(set_a_e1a_arten,MATCH(K3,set_a_arten,0))="","9230 - Übrige Betriebsausgaben",INDEX(set_a_e1a_arten,MATCH(K3,set_a_arten,0))),"")).',
            'parameter' => [],
        ],
        'EUE-042' => [
            'logik' => 'A!B3 = IF(L3="","",CONCATENATE(IF(AND(E3="",NOT(P3=0)),\'• Belegnummer fehlt! Jede Betriebsausgabe muss durch einen Beleg beweisbar sein.\'&CHAR(10),\'\'), ... IF(O3="","",IF(ue_sv_modus=\'Freiberufliche (FSVG)\',\'\',IF(COUNTIF(O3,\'9100*\')>0,IF(OR(E3="",COUNTIF(web_belegnummern,E3)=0),\'• Wareneinkauf? Kein Eintrag im WEB gefunden.\',\'\',\'\'),\'\'))), IF(M3<>"",IF(ISNA(MATCH(M3,{Reverse Charge...;IG-Erwerb...},0)),\'⚠ Umsatzsteuer-Art existiert nicht.\',\'\',\'\'),\'\'))).',
            'parameter' => [],
        ],
        'EUE-043' => [
            'logik' => 'A!B3: IF(COUNTIF(O3,\'9100*\')>0, IF(OR(E3="",COUNTIF(web_belegnummern,E3)=0),\'• Wareneinkauf? Kein Eintrag im WEB gefunden.\'&CHAR(10),\'\'),\'\') — nur wenn ue_sv_modus<>\'Freiberufliche (FSVG)\'.',
            'parameter' => [],
        ],
        'EUE-044' => [
            'logik' => 'A!D3 = IF(OR(L3="",P3=0),"",1); A!D4 = IF(OR(L4="",P4=0),"",IF(ROW()=3,1,IF(D3<>"",D3+1,COUNTIFS(L$3:L3,"<>",P$3:P3,"<>0")+1))).',
            'parameter' => [],
        ],
        'EUE-045' => [
            'logik' => 'WEB!P3 = IF(OR(M3="",N3="",COUNTIF(set_a_arten,M3)=0),"",IF(INDEX(set_a_ba,MATCH(M3,set_a_arten,0),0)="","",INDEX(set_a_ba,MATCH(M3,set_a_arten,0),0))); WEB!Q3 = IF(OR(N3="",COUNTIF(E3,\'WE*\')=0),"",N3*IF(P3="",1,P3)).',
            'parameter' => [],
        ],
        'EUE-046' => [
            'logik' => 'WEB!B3: IF(AND(COUNTIF(E3,\'WE*\')=0,COUNTIF(a_belegnummern,\'\'&E3)),\'• Betr. Ausgabe im A-Blatt unter #\'&INDEX(a_laufende_nummern,MATCH(E3,a_belegnummern,0))&\' zu finden\',\'\'); IF(AND(COUNTIF(E3,\'WE*\')=0,E3=""),\'• Belegnummer aus A-Blatt fehlt noch!\',\'\'); IF(...=0,\'⚠ Belegnummer im A-Blatt nicht gefunden\',\'\').',
            'parameter' => [],
        ],
        'EUE-047' => [
            'logik' => 'WEB!B3 = IF(N3="","",CONCATENATE(IF(F3="",\'⚠ Monat fehlt\',IF(ISNA(MATCH(F3,{Jänner...},0)),\'⚠ Ungültiger Monat.\',\'\')),IF(G3="",\'⚠ Tag fehlt\',\'\'),IF(H3="",\'⚠ Lieferant fehlt\',\'\'),IF(I3="",\'⚠ Warenbezeichnung fehlt\',\'\'), IF(AND(COUNTIF(E3,\'WE*\'),M3=""),\'⚠ Ausgaben-Art fehlt\',...))).',
            'parameter' => [],
        ],
        'EUE-048' => [
            'logik' => 'Einstellungen!R-Spalte: \'Unternehmer-Lohn\' und \'Private Ausgaben\' haben Anteil 0; A!P3 übernimmt 0, A!Q3 = L3*0 = 0.',
            'parameter' => [
                [
                    'name' => 'Betriebsanteil Privat',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 0,
                ],
            ],
        ],
        'EUE-049' => [
            'logik' => 'AV!S3 = IF(F3=j, ROUND(N3/O3*IF(ISNA(MATCH(G3,{Juli...Dezember},0)),1,0.5),2), ...). Bei Folgejahren greift die Konsistenzprüfung gegen die Vorjahres-AfA.',
            'parameter' => [
                [
                    'name' => 'Halbjahresfaktor',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '0,5',
                ],
                [
                    'name' => 'Ganzer-Jahres-Faktor',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 1,
                ],
            ],
        ],
        'EUE-050' => [
            'logik' => 'AV!T3 = IF(N3="","",IF(O3=0,IF(Q3="",N3,Q3),IF(ISNUMBER(S3),ROUND(MAX(IF(Q3="",N3-S3,IF(AND(Q3<1,Q3>=0),Q3,Q3-S3)),0.01),2),\'\'))).',
            'parameter' => [
                [
                    'name' => 'Mindest-Restbuchwert',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 0.01,
                ],
            ],
        ],
        'EUE-051' => [
            'logik' => 'AV!B3: IF(AND(F3=j,N3<>"",N3<1000),\'• Unter GWG-Grenze (1000€) - evtl. sofort im A-Blatt erfassen\'&CHAR(10),\'\').',
            'parameter' => [
                [
                    'name' => 'GWG-Grenze',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 1000,
                ],
            ],
        ],
        'EUE-052' => [
            'logik' => 'AV!O3:O50 decimal 2.0–99.0; AV!B3: IF(NOT(O3>0),\'⚠ Nutzungsjahre fehlen\',IF(O3=1,\'• Anlagegüter sind entweder nicht abnutzbar (0) oder haben mind. 2 Jahre Nutzungsjahre.\',\'\')).',
            'parameter' => [
                [
                    'name' => 'Nutzungsjahre Minimum',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 2,
                ],
                [
                    'name' => 'Nutzungsjahre Maximum',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 99,
                ],
            ],
        ],
        'EUE-053' => [
            'logik' => 'AV!B3: IF(AND(ISNUMBER(S3),S3>1),IF(AND(ISERR(SEARCH(\'9130\',V3)),ISERR(SEARCH(\'9135\',V3)),ISERR(SEARCH(\'9134\',V3)),ISERR(SEARCH(\'9500\',V3))),\'⚠ Ausgaben-Art muss zu einer AfA-Kennzahl zugeordnet sein\',\'\'),\'\').',
            'parameter' => [],
        ],
        'EUE-054' => [
            'logik' => 'AV!B3: IF(AND(F3=j,INDEX(set_a_ba,MATCH(M3,set_a_arten,0),0)<0.5,INDEX(...)<>""),\'⚠ Gehört nicht ins Anlagenverzeichnis da der betriebliche Anteil der AUSGABEN-ART unter < 50 % (Privatvermögen) liegt.\',\'\').',
            'parameter' => [
                [
                    'name' => 'Mindest-Betriebsanteil',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 0.5,
                ],
            ],
        ],
        'EUE-055' => [
            'logik' => 'AV!B3: IF(AND(F3=j,bet<1,bet<>"",COUNTIF(e_belegnummern,\'\'&E3)=0),\'• Die AUSGABEN-ART hat einen Privatanteil (\'&bet*100&\'%). Im Tabellenblatt E ist unter der Belegnummer \'&E3&\' keine Buchung einer \'\'Entnahme\'\' (\'&ROUND(S3*(1-bet),2)&\'€) zu finden.\',\'\') — S3 = Brutto-Kosten.',
            'parameter' => [
                [
                    'name' => 'Privatanteil-Bereich',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '50 % < Anteil < 100 %',
                ],
            ],
        ],
        'EUE-056' => [
            'logik' => 'AV!B3: IF(AND(F3<>j,P3<>"",Q3<>"",O3>0,NOT(ABS(P3-IFERROR(IF(AND(j-F3=1,MONAT in H2),P3/O3*2,P3/O3),0))<=0.01)),\'⚠ Abschreibung \'&(j-1)&\' passt nicht zu Kosten/Nutzungsjahre (erwartet: \'&ROUND(...,2)&\'€)\',\'\').',
            'parameter' => [
                [
                    'name' => 'Toleranz AfA',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 0.01,
                ],
            ],
        ],
        'EUE-057' => [
            'logik' => 'AV!B3: IF(AND(F3=j,P3<>""),\'⚠ Abschreibung \'&(j-1)&\' entfernen\',\'\'); IF(AND(F3<>j,F3<>"",Q3=""),\'⚠ Restbuchwert \'&(j-1)&\' fehlt\',\'\'); IF(AND(...,NOT(ROUND(IF(j-F3=1 ... ,P3*2*O3-P3,...),0)=ROUND(Q3,0))),\'⚠ Restbuchwert \'&(j-1)&\' stimmt nicht (Differenz: \'&...&\')\',\'\').',
            'parameter' => [],
        ],
        'EUE-058' => [
            'logik' => 'AV!B3: IF(AND(F3<>"",F3>j),\'⚠ Jahr liegt in der Zukunft\'&CHAR(10),\'\').',
            'parameter' => [],
        ],
        'EUE-059' => [
            'logik' => 'AV!B3: IF(E3="",\'⚠ Belegnummer fehlt. Die Rechnung muss leicht auffindbar sein.\',\'\'), IF(F3="",\'⚠ Jahr fehlt\',\'\'), IF(G3="",\'⚠ Monat fehlt\',\'\'), IF(ISBLANK(H3),\'• Tag fehlt\',\'\'), IF(OR(N3<>"",P3<>"",Q3<>""),IF(J3="",\'• Bezeichnung fehlt\',\'\'),\'\').',
            'parameter' => [],
        ],
        'EUE-060' => [
            'logik' => 'AV!D3 = IF(S3="","",1); AV!D4 = IF(S4="","",IF(ROW()=3,1,IF(D3<>"",D3+1,COUNTIFS(S$3:S4,"<>")+1))).',
            'parameter' => [],
        ],
        'EUE-061' => [
            'logik' => 'AV!V3 = IF(OR(M3="",COUNTIF(set_a_arten,M3)=0),"",IF(INDEX(set_a_e1a_arten,MATCH(M3,set_a_arten,0))="","9130 - Anlagegüter",INDEX(set_a_e1a_arten,MATCH(M3,set_a_arten,0)))).',
            'parameter' => [],
        ],
        'EUE-062' => [
            'logik' => 'FR!AB3: IF(Y3=\'PKW oder Kombi\', IF(j>=2025,0.5,0.42) + IF(O3="",0,MIN(O3,6))*IF(j>=2025,0.15,0.05), ...); FR!AC3 = AA3*AB3.',
            'parameter' => [
                [
                    'name' => 'KM-Geld PKW',
                    'jahre' => [2024],
                    'wert' => 0.42,
                ],
                [
                    'name' => 'KM-Geld PKW',
                    'jahre' => [2025],
                    'wert' => 0.5,
                ],
                [
                    'name' => 'KM-Geld PKW',
                    'jahre' => [2026],
                    'wert' => 0.5,
                ],
                [
                    'name' => 'Mitfahrerzuschlag PKW',
                    'jahre' => [2024],
                    'wert' => 0.05,
                ],
                [
                    'name' => 'Mitfahrerzuschlag PKW',
                    'jahre' => [2025],
                    'wert' => 0.15,
                ],
                [
                    'name' => 'Mitfahrerzuschlag PKW',
                    'jahre' => [2026],
                    'wert' => 0.15,
                ],
            ],
        ],
        'EUE-063' => [
            'logik' => 'FR!AB3: IF(Y3=\'Motorrad\', IF(j>=2026,0.25,IF(j=2025,IF(OR(F3="",MONTH(F3)>6),0.25,0.5),0.24)) + IF(O3="",0,MIN(O3,1))*IF(j>=2025,0.15,0.05), ...).',
            'parameter' => [
                [
                    'name' => 'KM-Geld Motorrad',
                    'jahre' => [2024],
                    'wert' => 0.24,
                ],
                [
                    'name' => 'KM-Geld Motorrad',
                    'jahre' => [2025],
                    'wert' => '0,50 H1 / 0,25 H2',
                ],
                [
                    'name' => 'KM-Geld Motorrad',
                    'jahre' => [2026],
                    'wert' => 0.25,
                ],
                [
                    'name' => 'Mitfahrerzuschlag Motorrad',
                    'jahre' => [2024],
                    'wert' => 0.05,
                ],
                [
                    'name' => 'Mitfahrerzuschlag Motorrad',
                    'jahre' => [2025],
                    'wert' => 0.15,
                ],
                [
                    'name' => 'Mitfahrerzuschlag Motorrad',
                    'jahre' => [2026],
                    'wert' => 0.15,
                ],
            ],
        ],
        'EUE-064' => [
            'logik' => 'FR!AB3: IF(Y3=\'Fahrrad oder E-Bike\', IF(j>=2026,0.25,IF(j=2025,IF(OR(F3="",MONTH(F3)>6),0.25,0.5),0.38)),\'\').',
            'parameter' => [
                [
                    'name' => 'KM-Geld Fahrrad',
                    'jahre' => [2024],
                    'wert' => 0.38,
                ],
                [
                    'name' => 'KM-Geld Fahrrad',
                    'jahre' => [2025],
                    'wert' => '0,50 H1 / 0,25 H2',
                ],
                [
                    'name' => 'KM-Geld Fahrrad',
                    'jahre' => [2026],
                    'wert' => 0.25,
                ],
            ],
        ],
        'EUE-065' => [
            'logik' => 'FR!Z3: IF(Y3=\'Fahrrad oder E-Bike\',IF(j<=2024,1500,3000),IF(OR(Y3=\'PKW oder Kombi\',Y3=\'Motorrad\'),30000,\'\')); Z4 zieht bereits verbuchte Kilometer per SUMIF ab; AA3 = IF($Z3="","",MIN($Z3,MAX(0,IF(AND($Q3="",$P3=""),$R3,N($Q3)-N($P3))))).',
            'parameter' => [
                [
                    'name' => 'Fahrrad-Kontingent',
                    'jahre' => [2024],
                    'wert' => 1500,
                ],
                [
                    'name' => 'Fahrrad-Kontingent',
                    'jahre' => [2025],
                    'wert' => 3000,
                ],
                [
                    'name' => 'Fahrrad-Kontingent',
                    'jahre' => [2026],
                    'wert' => 3000,
                ],
                [
                    'name' => 'KFZ-Kontingent',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 30000,
                ],
            ],
        ],
        'EUE-066' => [
            'logik' => 'FR!AB3 nutzt MIN(O3,6) bei PKW und MIN(O3,1) bei Motorrad; FR!B3 warnt \'⚠ Maximal 6 MITFAHRER bei PKW und Kombi möglich.\' bzw. \'⚠ Maximal 1 MITFAHRER bei Motorrad möglich.\' und \'⚠ MITFAHRER sollte bei diesem Transportmittel leer oder 0 sein.\'',
            'parameter' => [
                [
                    'name' => 'Max Mitfahrer PKW',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 6,
                ],
                [
                    'name' => 'Max Mitfahrer Motorrad',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 1,
                ],
            ],
        ],
        'EUE-067' => [
            'logik' => 'FR!B3: IF(AND(P3="",Q3="",R3=""),\'⚠ ANFANG-KM und END-KM oder alternativ DISTANZ (KM) angeben\',\'\'); IF(AND(NOT(R3=""),OR(NOT(P3=""),NOT(Q3=""))),\'⚠ Entweder Anfang-/Endestand ODER Distanz angeben\',\'\'); AA3 nutzt R3 wenn P/Q leer, sonst N(Q3)-N(P3).',
            'parameter' => [],
        ],
        'EUE-068' => [
            'logik' => 'FR!AN3 = MAX(SUM(AL3*IF(V3=\'1 Mahlzeit\',IF(T3=\'Österreich\',AM3-(AM3/2),AM3),IF(V3=\'2 Mahlzeiten\',IF(T3=\'Österreich\',0,AM3-(AM3*2/3)),AM3))),0); AM3 = INDEX(set_reisen_verpfelgungspauschalen,MATCH(T3,set_reisen_laender,0),0).',
            'parameter' => [
                [
                    'name' => '1 Mahlzeit Inland',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 'AM3/2',
                ],
                [
                    'name' => '1 Mahlzeit Ausland',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 'AM3 (voll)',
                ],
                [
                    'name' => '2 Mahlzeiten Inland',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 0,
                ],
                [
                    'name' => '2 Mahlzeiten Ausland',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 'AM3/3',
                ],
            ],
        ],
        'EUE-069' => [
            'logik' => 'FR!AQ3 = IF(AP3="","",IF(AND(W3=0,W3<>""),IF(T3=\'Österreich\',IF(j<2024,4.4,4.5),5.85),INDEX(set_reisen_naechtigungen,MATCH(T3,set_reisen_laender,0),0))); AR3 = AQ3*AP3.',
            'parameter' => [
                [
                    'name' => 'Inland Nächtigung ohne Beleg',
                    'jahre' => [2024],
                    'wert' => 4.5,
                ],
                [
                    'name' => 'Inland Nächtigung ohne Beleg',
                    'jahre' => [2025],
                    'wert' => 4.5,
                ],
                [
                    'name' => 'Inland Nächtigung ohne Beleg',
                    'jahre' => [2026],
                    'wert' => 4.5,
                ],
                [
                    'name' => 'Ausland Nächtigung Pauschale',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 5.85,
                ],
            ],
        ],
        'EUE-070' => [
            'logik' => 'FR!AF3 = SUM($AI3,N($AJ2>0)); FR!AG3 = SUM($AI3,N($AJ2>0)) — die beiden Zähler sind in Zeile 3 identisch. Die kumulierten SUMIFS/COUNTIFS mit 35-Tage-Fenster (5-Tage-Regel) stehen in FR!AF4, die ohne Zeitfenster (15-Tage-Regel) in FR!AG4; AH3 liefert den Reisestatus; AL3 begrenzt die Zwölftel auf 5×12 bzw. 15×12 abzüglich bereits verbuchter Zwölftel.',
            'parameter' => [
                [
                    'name' => 'Tagesgeld-Grenze',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 5,
                ],
                [
                    'name' => 'Nächtigungsgeld-Grenze',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 15,
                ],
            ],
        ],
        'EUE-071' => [
            'logik' => 'FR!AH3: IF(OR(AND(AI3<>"",AI3>0),AJ3>=3),\'Hin-Rückfahrt\',IF(AE3>=3,\'Hin-Rückfahrt\',\'Nein (Unter 3 Stunden)\')); AT3 = IF(AH3=\'Hin-Rückfahrt\',0,...); die Hinfahrt muss bei einer Einzelzeile mindestens 50 km betragen (AH3: \'Nein (HINFAHRT < 25 km)\' bei R<50 und Q-P<50); bei verketteten Hin-/Rückfahrten gilt kumulativ < 25 km (AH4 mit COUNTIFS). B4 prüft, dass Veranlassung und Einsatzort bei Hin-/Rückfahrt leer bleiben.',
            'parameter' => [
                [
                    'name' => 'Zeitgrenze Hin-/Rückfahrt',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 3,
                ],
                [
                    'name' => 'Hinfahrt-Minimum Einzelzeile',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 50,
                ],
                [
                    'name' => 'Hinfahrt-Minimum verkettet',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 25,
                ],
            ],
        ],
        'EUE-072' => [
            'logik' => 'FR!AW3 = CONCATENATE(ROUND(SUMIF(beleg,BT),0),\' : \',MAX(0,ROUND(SUMIF(beleg,PT),0))); FR!AX3 = IF(BT/(BT+MAX(0,PT))>=90%,100%,IF(...<10%,0%,...)); FR!BA3 = IF(AND($S3="",$AC3=""),"",IF($K3="Privat",0,SUM($S3,$AC3)*IF($AX3="",1,$AX3))); FR!BD3 = SUM(BA3,BB3,BC3).',
            'parameter' => [
                [
                    'name' => 'Untere Rundungsgrenze',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '10 %',
                ],
                [
                    'name' => 'Obere Rundungsgrenze',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '90 %',
                ],
            ],
        ],
        'EUE-073' => [
            'logik' => 'FR!BA3 = IF(AND(S3="",AC3=""),"",IF(K3=\'Privat\',0,SUM(S3,AC3)*IF(AX3="",1,AX3))); AH3 = IF(K3=\'Privat\',\'Nein (VERANLASSUNG: Privat)\',...); AT3 = IF(K3=\'Privat\',0,...).',
            'parameter' => [],
        ],
        'EUE-074' => [
            'logik' => 'FR!AV3 = ... MAX(0,NETWORKDAYS(IF(G3<=TIME(12,0,0),F3,F3+1),IF(I3>TIME(12,0,0),IF(H3="",F3,H3),IF(H3="",F3,H3-1)),set_feiertage)).',
            'parameter' => [],
        ],
        'EUE-075' => [
            'logik' => 'FR!B3 = IF(ISNUMBER(F3),IF(YEAR(F3)<>j,\'⚠ Jahr im START DATUM stimmt nicht mit Wirtschaftsjahr (Einstellungen) überein\',\'\',\'\'),\'⚠ Ungültiges START DATUM\'); analog für H3/END DATUM.',
            'parameter' => [],
        ],
        'EUE-076' => [
            'logik' => 'FR!B4: IF(AND(G3<>"",I3=""),\'⚠ END ZEIT fehlt\',\'\'); IF(AND(G3<>"",I3<>"",OR(F3=H3,AND(F3<>"",H3="")),(I3-G3)<0),\'⚠ END ZEIT kann nicht vor/gleich START ZEIT sein bei gleichem Datum.\',\'\'); IF(AND(E4=E3,OR((SUM(F4,G4)-SUM(IF(H3<>"",H3,F3),I3))*24*60<0,...>2)),\'• START DATUM und START ZEIT sollte bei mehrteiligen Reise dort fortsetzen...\',\'\').',
            'parameter' => [],
        ],
        'EUE-077' => [
            'logik' => 'FR!B3: IF(E3="",\'⚠ BELEGNUMMER durch Referenz auf das Fahrtenbuch, Reisekostenabrechnung oder andere Belege ist verpflichtend\',\'\'); FR!B4: IF(AND(E3<>E4,COUNTIF(E$3:E4,E4)>0),\'⚠ BELEGNUMMER bereits für andere Reise vorhanden. Zeilen einer zusammengehörigen Reise sollten hintereinander sein.\',\'\').',
            'parameter' => [],
        ],
        'EUE-078' => [
            'logik' => 'FR!B4: IF(AND(T4="",OR(S4<>"",W4<>"",AL4<>"",AQ4<>"")),\'⚠ LAND / REGION auswählen\',\'\'); IF(AND(N4="",OR(...)),\'⚠ TRANSPORTMITTEL auswählen\',\'\'); IF(N4<>"",IF(Y4="",\'⚠ TRANSPORTMITTEL nicht im Tabellenblatt Einstellungen gefunden.\',\'\'),\'\').',
            'parameter' => [],
        ],
        'EUE-079' => [
            'logik' => 'FR!B4: IF(AND(OR(K4<>"",U4<>""),OR(AND(E4<>E3,E4=E5),AND(E4<>E5,E4=E3))),\'⚠ VERANLASSUNG und EINSATZORT / GEBIET müssen für die Zeilen der Hin- oder Rückreise LEER bleiben.\',\'\').',
            'parameter' => [],
        ],
        'EUE-080' => [
            'logik' => 'FR!AE3 = IF(E3="",0,SUMIFS(fr_stunden,fr_belege,E3)) mit fr_stunden=Stunden!F:F und fr_belege=FR!E:E; AH3 nutzt AE3>=3.',
            'parameter' => [],
        ],
        'EUE-081' => [
            'logik' => 'FR!BA3 = IF(AND(S3="",AC3=""),"",IF(K3=\'Privat\',0,SUM(S3,AC3)*IF(AX3="",1,AX3))); BB3 = IF(AN3="","",AN3); BC3 = IF(AR3="","",AR3); BD3 = IF(AND(BA3="",BB3="",BC3=""),"",SUM(BA3,BB3,BC3)); fr_betriebsausgaben=FR!$BD:$BD; Ü!F12/F13 addieren SUMIF(fr_monate,Monat,fr_betriebsausgaben); Projekte nutzt SUMIFS(fr_betriebsausgaben,fr_projekte,...,fr_monate,...).',
            'parameter' => [],
        ],
        'EUE-082' => [
            'logik' => 'UST!D5=\'000\', D6=\'016\', D7=\'057\', D10=\'070\', D11=\'072\', D12=\'073\', D13=\'008\', D14=\'125\'; D5/D10 sind Gesamt-/Zwischensummen, D6/D7/D11:D14 werden aus E/A-Blatt gespeist.',
            'parameter' => [],
        ],
        'EUE-083' => [
            'logik' => 'UST!E6 = IF(SUMIFS(e_betriebseinnahmen,e_monate,E$4)=0,\'\',SUMIFS(e_betriebseinnahmen,e_monate,E$4)); E5 übernimmt E6 als Gesamtbetrag der Bemessungsgrundlage.',
            'parameter' => [],
        ],
        'EUE-084' => [
            'logik' => 'UST!E7 = IF(SUMIFS(a_ust_rc,a_ust_arten,\'Reverse Charge*\',a_monate,E$4)=0,\'\',ROUNDDOWN(SUMIFS(a_ust_rc,a_ust_arten,\'Reverse Charge*\',a_monate,E$4),2)).',
            'parameter' => [],
        ],
        'EUE-085' => [
            'logik' => 'UST!E11 = IF(SUMIFS(a_brutto,a_ust_arten,\'IG-Erwerb 20 %\',a_monate,E$4)=0,\'\',ROUNDDOWN(SUMIFS(...),2)); E12/E13/E14 analog für 10 %/13 %/4.9 %; E10 = SUM(E11:E14) als KZ 070.',
            'parameter' => [],
        ],
        'EUE-086' => [
            'logik' => 'UST!E20 = IF(AND(E7="",E11="",E12="",E13="",E14=""),\'\',SUM(E7,N(E11)*0.2,N(E12)*0.1,N(E13)*0.13,N(E14)*0.049)).',
            'parameter' => [
                [
                    'name' => 'USt-Sätze',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '20/10/13/4,9 %',
                ],
            ],
        ],
        'EUE-087' => [
            'logik' => 'UST!H5 = IF(SUM(E5:G5)=0,\'\',SUM(E5:G5)); H6/H7/H10/H20 analog; M5=SUM(J5:L5), S5=SUM(P5:R5), X5=SUM(U5:W5).',
            'parameter' => [],
        ],
        'EUE-088' => [
            'logik' => 'UST!C2 = UPPER(\'1. Umsatzsteuervoranmeldung\'); UST!AB2 = UPPER(\'2. Umsatzsteuererklärung\'); UST!AD4 = set_wirtschaftsjahr.',
            'parameter' => [],
        ],
        'EUE-089' => [
            'logik' => 'Ü!R17 = IF(j=2024,12816,IF(j=2025,13308,IF(j=2026,13539,\'?\'))); S17 = IF(N(j)<=2026,20%,\'?\'); U17 = (MAX(ue_gesamteinkommen-R17,0)-MAX(ue_gesamteinkommen-R18,0))*S17.',
            'parameter' => [
                [
                    'name' => 'Tarifgrenze Stufe 1',
                    'jahre' => [2024],
                    'wert' => 12816,
                ],
                [
                    'name' => 'Tarifgrenze Stufe 1',
                    'jahre' => [2025],
                    'wert' => 13308,
                ],
                [
                    'name' => 'Tarifgrenze Stufe 1',
                    'jahre' => [2026],
                    'wert' => 13539,
                ],
                [
                    'name' => 'Steuersatz Stufe 1',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '20 %',
                ],
            ],
        ],
        'EUE-090' => [
            'logik' => 'Ü!R18 = IF(j=2024,20818,IF(j=2025,21617,IF(j=2026,21992,\'?\'))); S18 = IF(OR(j=2024,j=2025,j=2026),30%,\'?\'); U18 = (MAX(Einkommen-R18,0)-MAX(Einkommen-R19,0))*S18.',
            'parameter' => [
                [
                    'name' => 'Tarifgrenze Stufe 2',
                    'jahre' => [2024],
                    'wert' => 20818,
                ],
                [
                    'name' => 'Tarifgrenze Stufe 2',
                    'jahre' => [2025],
                    'wert' => 21617,
                ],
                [
                    'name' => 'Tarifgrenze Stufe 2',
                    'jahre' => [2026],
                    'wert' => 21992,
                ],
                [
                    'name' => 'Steuersatz Stufe 2',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '30 %',
                ],
            ],
        ],
        'EUE-091' => [
            'logik' => 'Ü!R19 = IF(j=2024,34513,IF(j=2025,35836,IF(j=2026,36458,\'?\'))); S19 = IF(OR(j=2024,j=2025,j=2026),40%,\'?\'); U19 = (MAX(Einkommen-R19,0)-MAX(Einkommen-R20,0))*S19.',
            'parameter' => [
                [
                    'name' => 'Tarifgrenze Stufe 3',
                    'jahre' => [2024],
                    'wert' => 34513,
                ],
                [
                    'name' => 'Tarifgrenze Stufe 3',
                    'jahre' => [2025],
                    'wert' => 35836,
                ],
                [
                    'name' => 'Tarifgrenze Stufe 3',
                    'jahre' => [2026],
                    'wert' => 36458,
                ],
                [
                    'name' => 'Steuersatz Stufe 3',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '40 %',
                ],
            ],
        ],
        'EUE-092' => [
            'logik' => 'Ü!R20 = IF(j=2024,66612,IF(j=2025,69166,IF(j=2026,70365,\'?\'))); S20 = IF(j<=2026,48%,\'?\'); U20 = (MAX(Einkommen-R20,0)-MAX(Einkommen-R21,0))*S20.',
            'parameter' => [
                [
                    'name' => 'Tarifgrenze Stufe 4',
                    'jahre' => [2024],
                    'wert' => 66612,
                ],
                [
                    'name' => 'Tarifgrenze Stufe 4',
                    'jahre' => [2025],
                    'wert' => 69166,
                ],
                [
                    'name' => 'Tarifgrenze Stufe 4',
                    'jahre' => [2026],
                    'wert' => 70365,
                ],
                [
                    'name' => 'Steuersatz Stufe 4',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '48 %',
                ],
            ],
        ],
        'EUE-093' => [
            'logik' => 'Ü!R21 = IF(j=2024,99266,IF(j=2025,103072,IF(j=2026,104859,\'?\'))); S21 = IF(j<=2026,50%,\'?\'); U21 = MAX(ue_gesamteinkommen-R21,0)*S21.',
            'parameter' => [
                [
                    'name' => 'Tarifgrenze Stufe 5',
                    'jahre' => [2024],
                    'wert' => 99266,
                ],
                [
                    'name' => 'Tarifgrenze Stufe 5',
                    'jahre' => [2025],
                    'wert' => 103072,
                ],
                [
                    'name' => 'Tarifgrenze Stufe 5',
                    'jahre' => [2026],
                    'wert' => 104859,
                ],
                [
                    'name' => 'Steuersatz Stufe 5',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '50 %',
                ],
            ],
        ],
        'EUE-094' => [
            'logik' => 'U17=(MAX(E-R17,0)-MAX(E-R18,0))*20%; U18=(MAX(E-R18,0)-MAX(E-R19,0))*30%; U19=(MAX(E-R19,0)-MAX(E-R20,0))*40%; U20=(MAX(E-R20,0)-MAX(E-R21,0))*48%; U21=MAX(E-R21,0)*50%; U23=SUM(U17:U21).',
            'parameter' => [],
        ],
        'EUE-095' => [
            'logik' => 'Ü!U14 = IF(modus=\'Nur Betrieb\',N(U10)+N(U11),IF(modus=\'Nur Vermietung\',N(U11)+N(U12)+N(U13),N(U10)+N(U11)+N(U12)+N(U13))); U10 = Gewinn minus Grundfreibetrag; U11 = KZ 245; U12 = E1B-Ergebnis; U13 = Prognose Vermietung.',
            'parameter' => [],
        ],
        'EUE-096' => [
            'logik' => 'E1A!J6 = IF(set_wirtschaftsjahr<2024,30000,33000); definierter Name e1a_grundfreibetrag_grenze=E1A!$J$6.',
            'parameter' => [
                [
                    'name' => 'Grundfreibetrag Grenze',
                    'jahre' => [2024],
                    'wert' => 33000,
                ],
                [
                    'name' => 'Grundfreibetrag Grenze',
                    'jahre' => [2025],
                    'wert' => 33000,
                ],
                [
                    'name' => 'Grundfreibetrag Grenze',
                    'jahre' => [2026],
                    'wert' => 33000,
                ],
            ],
        ],
        'EUE-097' => [
            'logik' => 'E1A!J7 = IF(set_wirtschaftsjahr>=2022,15%,13%); definierter Name e1a_grundfreibetrag_satz=E1A!$J$7.',
            'parameter' => [
                [
                    'name' => 'Grundfreibetrag Satz',
                    'jahre' => [2024],
                    'wert' => '15 %',
                ],
                [
                    'name' => 'Grundfreibetrag Satz',
                    'jahre' => [2025],
                    'wert' => '15 %',
                ],
                [
                    'name' => 'Grundfreibetrag Satz',
                    'jahre' => [2026],
                    'wert' => '15 %',
                ],
            ],
        ],
        'EUE-098' => [
            'logik' => 'E1A!K4 = SUM(e1a_betriebseinnahmen)-SUM(e1a_betriebsausgaben); K5 = MAX(MIN(K4*e1a_grundfreibetrag_satz,e1a_grundfreibetrag_grenze*e1a_grundfreibetrag_satz),0); K9 = N(K4)-N(K5). Ü!U9 = MAX(MIN(SUM(U7:U8)*satz,grenze*satz),0); U10 = SUM(U7:U8)-N(U9).',
            'parameter' => [],
        ],
        'EUE-099' => [
            'logik' => 'E1A!E11 = SUM(e_betriebseinnahmen)-SUM(E12:E14)-SUMIF(e_est_kennzahlen,\'E1B*\',...); E17 = SUMIF(a_est_kennzahlen,\'9100*\',a_betriebsausgaben)+SUM(web_betriebsausgaben); E20 = A+AV; E24 = A(9160*)+SUM(fr_betriebsausgaben); E38 = SUM(A,AV,WEB,FR)-SUM(E17:E37)-E1B-Ausstattung.',
            'parameter' => [],
        ],
        'EUE-100' => [
            'logik' => 'E1B!E7 = SUMIF(e_est_kennzahlen,\'E1B*\',e_betriebseinnahmen); E10 = SUMIF(av_est_kennzahlen,\'E1B 9500*\',av_betriebsausgaben); E12:E14 = SUMIF(a_est_kennzahlen,\'E1B <KZ>*\',a_betriebsausgaben); E15 = SUMIF(a_est_kennzahlen,\'E1B*\',a_betriebsausgaben)+SUMIF(av_est_kennzahlen,\'E1B*\',av_betriebsausgaben)-SUM(E10:E14); E18 = SUM(e1b_einnahmen)-SUM(e1b_werbungskosten), wobei e1b_werbungskosten=E1B!$E$10:$E$15.',
            'parameter' => [],
        ],
        'EUE-101' => [
            'logik' => 'E1A!C40 = IF(E32<>"" AND E32<>0,\'\',IF(E40=\'\',\'\',IF(AND(E40<=1200,E40>300),\'9217\',IF(E40<=300,\'9215\',\'\')))); D40 zeigt \'Kleines/Großes Arbeitsplatzpauschale\' oder \'Falscher Betrag\'; Validierung E40 decimal 25.0–1200.0.',
            'parameter' => [
                [
                    'name' => 'Kleine Pauschale bis',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 300,
                ],
                [
                    'name' => 'Große Pauschale bis',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 1200,
                ],
                [
                    'name' => 'Eingabe Minimum',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 25,
                ],
            ],
        ],
        'EUE-102' => [
            'logik' => 'E1A!C39 = IF(AND($E32<>"",$E32<>0),"",IF($E39="","","9216 ")); E1A!D39 zeigt bei gesetztem 9275 oder bei E40>300 nur einen Warnhinweis (\'Wert löschen …\'), löscht C39 aber nicht; Validierung E39 decimal 0.0–300.0.',
            'parameter' => [
                [
                    'name' => 'Eingabe Maximum',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 300,
                ],
            ],
        ],
        'EUE-103' => [
            'logik' => 'Ü!U11 = \'NICHTSELBSTÄNDIGE EINKÜNFTE (KZ 245)\' (Eingabe); U27 = SUM((MAX(U11-R17,0)-MAX(U11-R18,0))*S17, ... , MAX(U11-R21,0)*S21); U25 = SUM((MAX(U11+U12-R17,0)-...)*..., ...) - U27; U24 = IF(modus=\'Nur Bruttolohn\',\'\',N(U23)-N(U25)-N(U27)); R26 = \'- EINKOMMENSTEUER-VORAUSZAHLUNG\'; R28 = \'- BEZAHLTE LOHNSTEUER (KZ 260)\'; U29 = IF(N(U26)+N(U28)=0,ue_est,ue_est-N(U26)-N(U28)).',
            'parameter' => [],
        ],
        'EUE-104' => [
            'logik' => 'Ü!U5 = \'Ja/Nein\' (Validierung); R5 = \'Gesamt/Nur Betrieb/Nur Vermietung\' (Validierung); U4 = \'PROGNOSE\'; U8 = IF(AND(U5=\'Ja\',modus<>\'Nur Vermietung\'),(SUM(e1a_betriebseinnahmen)-SUM(e1a_betriebsausgaben))/SUMPRODUCT((ue_gewinn_pro_monat<>"")*1)*12-(SUM(...)),\'\'); U13 analog für Vermietung; die Verteilung der Steuer auf die Monate erfolgt in J12/K12; I13 ist die EST/SV-Basis, I15 die Prognose und I16 die Verlustbasis.',
            'parameter' => [],
        ],
        'EUE-105' => [
            'logik' => 'Ü!R24/R25 = IF(OR(modus=\'Nur Bruttolohn\',modus=\'Nur andere Gewinne\',...),\'\',...); U24 = IF(modus=\'Nur Bruttolohn\',\'\',...); AA8 = IF(modus=\'Nur andere Gewinne\',0,N(e1a_9225)); N4 = IF(modus=\'Nur andere Gewinne\',\'\',\'REINGEWINN/H\').',
            'parameter' => [],
        ],
        'EUE-106' => [
            'logik' => 'Ü!Y17 = IF(modus=\'Mit Prognose\',\'PROGNOSTIZIERTE SV-BEITRÄGE\',IF(modus=\'Nur andere Gewinne\',\'FÜR ANDERE EINKÜNFTE ZU ZAHLENDE SV-BEITRÄGE\',\'= ZU ZAHLENDE SV-BEITRÄGE\')); R24 = IF(OR(modus=\'Nur Bruttolohn\',modus=\'Nur andere Gewinne\',modus=\'Nur Vermietung\'),\'\',...); R25 = IF(OR(modus=\'Nur Bruttolohn\',modus=\'Nur andere Gewinne\'),\'\',...); U24/U25 = IF(modus=\'Nur Bruttolohn\',\'\',...); AA8 = IF(modus=\'Nur andere Gewinne\',0,N(e1a_9225)); I13 verzweigt auf die Prognoseeinstellung U5 (Ja/Nein), N4 auf den Modus \'Nur andere Gewinne\'.',
            'parameter' => [],
        ],
        'EUE-107' => [
            'logik' => 'Y5 Datenvalidierung \'Gewerbetreibende (GSVG),Neue Selbständige (GSVG),Freiberufliche (FSVG),Nur Unfallversicherung\'; definierter Name ue_sv_modus=Ü!$Y$5; Z13 setzt die SVS auf \'-\' bei FSVG/UV, Z15 setzt die PV nur bei UV auf \'-\' und wählt bei FSVG den 20-Prozent-Satz (I64), Z14 setzt die KV auf \'-\' bei Jungunternehmer/UV.',
            'parameter' => [],
        ],
        'EUE-108' => [
            'logik' => 'AA5 Datenvalidierung \'Ja,Nein\' (Wert \'Ja\'); ue_sv_jungunternehmer=Ü!$AA$5; Z14 = IF(OR(AA5=\'Ja\',SV-Modus=\'Nur Unfallversicherung\'),\'-\',set_sv_kv_satz); AA14 = IF(modus=\'Nur Unfallversicherung\',\'\',IF(ue_sv_kv_satz=\'-\',set_sv_kv_min_beitrag*12,MAX(BMG*KV%,MindestKV*12))) → 37,47×12 = 449,64. Z13 = IF(OR(modus=\'Freiberufliche (FSVG)\',modus=\'Nur Unfallversicherung\'),\'-\',set_sv_svs_satz) ohne Jungunternehmer-Bedingung → 0,0153; AA13 = IF(ue_sv_svs_satz=\'-\',\'\',IF(ue_sv_kv_satz=\'-\',set_sv_kv_min_beitrag/set_sv_kv_satz*12*ue_sv_svs_satz,MAX(ue_sv_bmg*ue_sv_svs_satz,set_sv_kv_min_beitrag/set_sv_kv_satz*12*ue_sv_svs_satz))) = 101,169 (MAX-basiert). PV (Z15/AA15) hat keine Jungunternehmer-Ausnahme.',
            'parameter' => [
                [
                    'name' => 'Mindest-KV Jungunternehmer',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 449.64,
                ],
            ],
        ],
        'EUE-109' => [
            'logik' => 'AA7 = ue_est_bmg (=Ü!U10); AA8 = IF(modus=\'Nur andere Gewinne\',0,N(e1a_9225)); AA9 = MIN(SUM(AA7:AA8),set_sv_bgl_max*12); definierter Name ue_sv_bmg=Ü!$AA$9.',
            'parameter' => [
                [
                    'name' => 'Deckelung Beitragsgrundlage',
                    'jahre' => [2024],
                    'wert' => 84840,
                ],
                [
                    'name' => 'Deckelung Beitragsgrundlage',
                    'jahre' => [2025],
                    'wert' => 90300,
                ],
                [
                    'name' => 'Deckelung Beitragsgrundlage',
                    'jahre' => [2026],
                    'wert' => 97020,
                ],
            ],
        ],
        'EUE-110' => [
            'logik' => 'AA14 = IF(ue_sv_modus=\'Nur Unfallversicherung\',\'\',IF(ue_sv_kv_satz=\'-\',set_sv_kv_min_beitrag*12,MAX(ue_sv_bmg*ue_sv_kv_satz,set_sv_kv_min_beitrag*12))).',
            'parameter' => [
                [
                    'name' => 'KV-Satz',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '6,8 %',
                ],
                [
                    'name' => 'Mindest-KV ×12',
                    'jahre' => [2024],
                    'wert' => 425.4,
                ],
                [
                    'name' => 'Mindest-KV ×12',
                    'jahre' => [2025],
                    'wert' => 449.64,
                ],
                [
                    'name' => 'Mindest-KV ×12',
                    'jahre' => [2026],
                    'wert' => 449.64,
                ],
            ],
        ],
        'EUE-111' => [
            'logik' => 'AA15 = IF(ue_sv_pv_satz=\'-\',\'\',MAX(ue_sv_bmg*ue_sv_pv_satz,IF(modus=\'Freiberufliche (FSVG)\',set_sv_fsvg_pv_min_beitrag*12,IF(modus=\'Neue Selbständige (GSVG)\',set_sv_gsvg_ns_pv_min_beitrag*12,set_sv_gsvg_pv_min_beitrag*12)))).',
            'parameter' => [
                [
                    'name' => 'PV-Satz GSVG',
                    'jahre' => [2024],
                    'wert' => '18,5 %',
                ],
                [
                    'name' => 'PV-Satz GSVG',
                    'jahre' => [2025],
                    'wert' => '18,5 %',
                ],
                [
                    'name' => 'PV-Satz GSVG',
                    'jahre' => [2026],
                    'wert' => '18,5 %',
                ],
                [
                    'name' => 'PV-Satz FSVG',
                    'jahre' => [2024],
                    'wert' => '20 %',
                ],
                [
                    'name' => 'PV-Satz FSVG',
                    'jahre' => [2025],
                    'wert' => '20 %',
                ],
                [
                    'name' => 'PV-Satz FSVG',
                    'jahre' => [2026],
                    'wert' => '20 %',
                ],
            ],
        ],
        'EUE-112' => [
            'logik' => 'AA12 = set_sv_uv*12; AA13 = IF(ue_sv_svs_satz=\'-\',\'\',IF(ue_sv_kv_satz=\'-\',set_sv_kv_min_beitrag/set_sv_kv_satz*12*ue_sv_svs_satz,MAX(ue_sv_bmg*ue_sv_svs_satz,set_sv_kv_min_beitrag/set_sv_kv_satz*12*ue_sv_svs_satz))).',
            'parameter' => [
                [
                    'name' => 'SVS-Satz',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => '1,53 %',
                ],
                [
                    'name' => 'UV ×12',
                    'jahre' => [2024],
                    'wert' => 136.2,
                ],
                [
                    'name' => 'UV ×12',
                    'jahre' => [2025],
                    'wert' => 144.84,
                ],
                [
                    'name' => 'UV ×12',
                    'jahre' => [2026],
                    'wert' => 155.4,
                ],
            ],
        ],
        'EUE-113' => [
            'logik' => 'Ü!I12 = IF(ue_sv_modus=\'Nur Unfallversicherung\',set_sv_uv,SUM(set_sv_uv,IF(ue_sv_svs_satz=\'-\',0,set_sv_kv_min_beitrag/set_sv_kv_satz*ue_sv_svs_satz),set_sv_kv_min_beitrag,IF(modus=\'Freiberufler (FSVG)\',set_sv_fsvg_pv_min_beitrag,IF(modus=\'Neue Selbständige (GSVG)\',set_sv_gsvg_ns_pv_min_beitrag,set_sv_gsvg_pv_min_beitrag)))).',
            'parameter' => [],
        ],
        'EUE-114' => [
            'logik' => 'AA17 = SUM(AA12:AA15); AA18 = IF(AA17="","",AA17/12); definierte Namen ue_sv=Ü!$AA$17 und ue_svbeitrag=Ü!$AA$18.',
            'parameter' => [],
        ],
        'EUE-115' => [
            'logik' => 'Ü!AA8 = IF(modus=\'Nur andere Gewinne\',0,N(e1a_9225)); e1a_9225=E1A!$E$33; E1A!E33 = SUMIF(a_est_kennzahlen,\'9225*\',a_betriebsausgaben); in Ü!F12 werden 9225-Beträge aus den Ausgaben herausgerechnet.',
            'parameter' => [],
        ],
        'EUE-131' => [
            'logik' => 'Ü!Y5 Datenvalidierung \'Gewerbetreibende (GSVG),Neue Selbständige (GSVG),Freiberufliche (FSVG),Nur Unfallversicherung\'; Ü!Z15 und Ü!AA15 prüfen auf \'Freiberufliche (FSVG)\' und verwenden set_sv_fsvg_pv_satz bzw. set_sv_fsvg_pv_min_beitrag*12; Ü!I12 = IF(ue_sv_modus=\'Freiberufler (FSVG)\',set_sv_fsvg_pv_min_beitrag,IF(ue_sv_modus=\'Neue Selbständige (GSVG)\',set_sv_gsvg_ns_pv_min_beitrag,set_sv_gsvg_pv_min_beitrag)) — der String-Unterschied \'Freiberufliche\' gegenüber \'Freiberufler\' führt zum GSVG-Fallback.',
            'parameter' => [],
        ],
        'EUE-116' => [
            'logik' => 'Monat!P5 Datenvalidierung \'OFF,Jänner,...,Dezember\'; m_monat=Monat!$P$5; P7 = IF(OR(m_monat=\'\',m_monat=\'OFF\'),\'💡 Rechenaufwendige Kalkulation: Bitte nach Verwendung wieder auf OFF setzten!\',\'\'); alle INDEX/SMALL-Formeln prüfen OR(m_monat=\'OFF\',m_monat=\'\').',
            'parameter' => [],
        ],
        'EUE-117' => [
            'logik' => 'Monat!J13 = SUMIFS(a_betriebsausgaben,a_monate,m_monat); J14 = SUMIFS(web_betriebsausgaben,web_monate,m_monat); J15 = SUMIFS(av_betriebsausgaben,av_monate,m_monat,av_jahre,j)+IF(m_monat=\'Jänner\',SUMIFS(av_betriebsausgaben,av_jahre,\'< \'&j),0); J16 = SUMIFS(fr_betriebsausgaben,fr_monate,m_monat); J20 = SUMIFS(e_betriebseinnahmen,e_monate,m_monat); J23 = N(J20)-N(J17).',
            'parameter' => [],
        ],
        'EUE-118' => [
            'logik' => 'Monat!A254 = IF(OR(m_monat=\'OFF\',m_monat=\'\'),\'\',IFERROR(SMALL(IF(e_laufende_nummern<>"",IF(e_monate=m_monat,ROW(e_laufende_nummern),\'\'),\'\'),ROW($AE1)),\'\')); A104 nutzt ROUNDUP(ROW($AE1)/2,0) für WEB; A54 berücksichtigt im Jänner auch Vorjahres-Anlagen; anschließend INDEX der Spalten (D404/F404 usw.).',
            'parameter' => [],
        ],
        'EUE-119' => [
            'logik' => 'BE-ART!E3 = IF(SUMIFS(e_betriebseinnahmen,e_einnahmen_arten,$C3,e_monate,E$2)=0,\'\',SUMIFS(...)); Q3 = SUM(E3:P3); E153 = SUM(E3:E152); Q153 = SUM(Q3:Q152); Q154 = IF(Q$153=\'\',\'\',IF(ROUND(SUM(e_betriebseinnahmen),1)=ROUND(Q$153,1),\'✓✓\',\'Fehler (\'&...&\'€)\')); C3 blendet Arten mit Anteil 0 aus.',
            'parameter' => [],
        ],
        'EUE-120' => [
            'logik' => 'BA-ART!E3 = IF(AND(SUMIFS(a_betriebsausgaben,a_ausgaben_arten,$C3,a_monate,E$2)=0,SUMIFS(av,...)=0,SUMIFS(web,...)=0),\'\',SUM(...)); E153 = SUMIFS(fr_betriebsausgaben,fr_monate,E$2); Q154 = SUM(Q3:Q153); Q155 = IF(ROUND(SUM(a,av,web,fr),1)=ROUND(Q$154,1),\'✓✓\',\'Fehler (...)\'); C3 blendet Arten mit Anteil 0 aus.',
            'parameter' => [],
        ],
        'EUE-121' => [
            'logik' => 'Projekte!E3 = IF(SUMIFS(e_betriebseinnahmen,e_projekte,E$1,e_monate,$B3)=0,\'\',SUMIFS(...)); E10 = SUM(A-9225, WEB, FR); E4 = SUMIFS(av, av_jahre<j) + ...; D5 = N(D3)-N(D4); D7 = D5/D6; D78 = SUM(h_stunden)-SUM(E78:X78); D75/D77 = Summenzeilen; E78 = SUMIFS(h_stunden,h_projekte,E$1).',
            'parameter' => [],
        ],
        'EUE-122' => [
            'logik' => 'ZA!E7 = IF(SUMIFS(e_brutto,e_zahlarten,$B7,e_monate,E$1,e_brutto,\'>0\')=0 AND SUMIFS(a_brutto,a_zahlarten,$B7,a_monate,E$1,a_brutto,\'<0\')=0,\'\',SUMIFS(e...)-SUMIFS(a...)); E10 = ... + web_preise (WE*); E13 = N(INDEX(set_zahlarten_startsaldo,MATCH($B7,set_zahlarten,0),0)) + SUM(E7:E7)+SUM(E8:E8)-SUM(E10:E10)-SUM(E11:E11); E14 = ✓/✓✓-Prüfung mit Delta-Anzeige.',
            'parameter' => [],
        ],
        'EUE-123' => [
            'logik' => 'Stunden!C3:C199 = Monatsliste (Validierung); E3:E199 = set_projekte; F3:F199 = Stunden; h_stunden=Stunden!$F:$F, h_monate=Stunden!$C:$C, h_projekte=Stunden!$E:$E; FR!AE3 = SUMIFS(fr_stunden,fr_belege,E3); Ü!M12 = SUMIFS(h_stunden,h_monate,$C12); Monat/Projekte nutzen SUMIFS(h_stunden,...).',
            'parameter' => [],
        ],
        'EUE-124' => [
            'logik' => 'Monat!O29 = IF(OR(m_monat=\'OFF\',m_monat=\'\'),\'\',INDEX(ust_daten,MATCH($M29,ust_kennzahlen,0),MATCH(m_monat,ust_monate,0))); ust_daten=UST!$E$5:$X$13, ust_kennzahlen=UST!$D$5:$D$13, ust_monate=UST!$E$4:$X$4.',
            'parameter' => [],
        ],
        'EUE-125' => [
            'logik' => 'Ü!I12 = IF(ue_sv_modus=\'Nur Unfallversicherung\',set_sv_uv,SUM(set_sv_uv,IF(ue_sv_svs_satz=\'-\',0,set_sv_kv_min_beitrag/set_sv_kv_satz*ue_sv_svs_satz),set_sv_kv_min_beitrag,IF(modus=\'Freiberufler (FSVG)\',set_sv_fsvg_pv_min_beitrag,IF(modus=\'Neue Selbständige (GSVG)\',set_sv_gsvg_ns_pv_min_beitrag,set_sv_gsvg_pv_min_beitrag)))) (cached 160,80); I13 = SUM($U$24,N($U$25),ue_sv)-$I$12*IF($U$5=\'Ja\',12,12-COUNTIF(ue_gewinn_pro_monat,"")); I14 = ABS(MIN(ue_gewinn_pro_monat)); I16 = SUM(IF(ue_gewinn_pro_monat="",IF($U$5=\'Ja\',$I$15+$I$14,0),ue_gewinn_pro_monat+$I$14)) (Array).',
            'parameter' => [
                [
                    'name' => 'Mindest-SV monatlich',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 160.8,
                ],
            ],
        ],
        'EUE-126' => [
            'logik' => 'Ü!J12 = IF(modus=\'Nur Vermietung\',...,(IF(AND($U$5=\'Ja\',$G12=""),$I$15,N($G12))+$I$14)/$I$16*$I$13+$I$12) (anteilige EST+SV); K12 = IF(modus=\'Nur Vermietung\',IF(AND($G12=\'\',$U$5=\'Nein\'),\'\',IF(SUM(ue_gewinn_pro_monat)=0,\'\',(...)/(SUM(ue_gewinn_pro_monat)+$I$14*12)*($U$23-$U$27))),IF($I$16=0,...,(IF(AND($U$5=\'Ja\',$G12=\'\'),$I$15,N($G12))+$I$14)/$I$16*$I$13+$I$12)) → N($G12)-N($J12) (Reingewinn, cached -219,60); N5 = K5/M5 (Reingewinn pro Stunde); M12 = SUMIFS(h_stunden,h_monate,$C12).',
            'parameter' => [],
        ],
        'EUE-127' => [
            'logik' => 'Bedingte Formatierung containsText mit Formel NOT(ISERROR(SEARCH(\'⚠\',B3))) für E!B3:B1000, A!B3:B1000, AV!B3:B50, FR!B3:B102, WEB!B3:B100; zusätzlich notContainsBlanks LEN(TRIM(B3))>0. Monat!P7 beginsWith \'💡\'.',
            'parameter' => [],
        ],
        'EUE-128' => [
            'logik' => 'Bedingte Formatierung colorScale in Ü!E7:E10, F7:F10, G7:G10, E12:E23, F12:F23, G12:G23, N7:N10 und N12:N23; BE-ART!E3:P152, Q3:Q152, R3:R152; BA-ART!E3:P153, Q3:Q153, R3:R153; Projekte zeilenweise D3:Y3 bis D79:X79; ZA!E14:P14, E23:P23, …, E95:P95 containsText \'✓\' (disjunkte Zeilen).',
            'parameter' => [],
        ],
        'EUE-129' => [
            'logik' => 'BE-ART!E154/Q154: IF(ROUND(SUMIFS(e_betriebseinnahmen,...),1)=ROUND(E$153,1),\'✓\',\'Fehler (\'&...&\'€)\'); BA-ART!E155/Q155 analog für a+av+web+fr; E1A!E38/E1B!E15 als Restgrößen; ZA!E14: ✓/✓✓ oder \'E-Fehler Δ\'/\'A-Fehler Δ\'.',
            'parameter' => [
                [
                    'name' => 'Abgleich-Rundung',
                    'jahre' => [2024, 2025, 2026],
                    'wert' => 1,
                ],
            ],
        ],
        'EUE-130' => [
            'logik' => 'A!D1001 = \'Ebenso viele Einnahmen erwünscht\' und E!E1001 = \'Gratulation zu so vielen Einnahmen!\' kennzeichnen die Footer-Zeile. Die dortigen \'20/10/13/5 % VST\'- und \'10 %-Vorsteuer\'-Formeln haben keine Entsprechung in den aktiven Spaltenüberschriften (A!P2=\'BTR. ANTEIL\', A!Q2=\'BTR. AUSGABE\', A!R2=\'UST ERWERB\', A!S2=\'UST RC\') und nicht in der Validierung set_a_ust_arten (AC3:AC9).',
            'parameter' => [],
        ],
    ];

    /**
     * @return array<string, array{logik: string, parameter: list<array{name: string, jahre: list<int>, wert: int|float|string}>}>
     */
    public static function all(): array
    {
        return self::RULES;
    }
}
