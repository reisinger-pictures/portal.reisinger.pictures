# Dialog-Höhenvertrag — `ModalShell` / `ModalDialogShell`

> **Status:** Soll-Zustand.
> Domain: `tech`
> Stand: 2026-09-28
> Betrifft: `frontend/src/ui/components/ModalShell.tsx`,
> `frontend/src/ui/components/ModalDialogShell.tsx`.

## 1. Der gemessene Defekt

daisyUI 5 setzt `.modal-box` auf `max-height: 100vh` und
`overflow-y: auto` (verifiziert an `daisyui@5.7.20`,
`node_modules/daisyui/components/modal.css`). Die Box ist damit **die
Scrollregion** des Dialogs. In der Standard-Layoutform rendert `ModalShell`
Header, Body und Footer als **eine** scrollende Spalte — der Footer liegt damit
am Ende genau dieser Spalte.

Daraus folgt der Defekt, der in der Screenshot-Prüfung vom 2026-09-27
gemessen wurde: bei einem Formular-Dialog, dessen Inhalt eine Viewport-Höhe
überschreitet, rutscht die Submit-Zeile unter die Falz. Sie ist nicht
falsch, nicht abgeschnitten, nicht ausgegraut — sie ist nur **unterhalb des
sichtbaren Bereichs** und nur erreichbar, indem der Benutzer das gesamte
Formular wegscrollt. Im Galerie-Editor (zehn Felder plus vier Optionskarten)
war „Speichern" damit unerreichbar.

Das ist kein Anwendungsfehler, sondern eine Eigenschaft des Containers: wer
`.modal-box` als Scrollregion benutzt, hat per Konstruktion keinen
sichtbaren Footer. Wer das nicht bemerkt, baut den Defekt in jedes Formular mit
viel Inhalt ein.

## 2. Der Vertrag: `scrollableBody`, opt-in

`ModalShell` bekommt eine **opt-in**-Prop `scrollableBody` (Default `false`).
Aktiv schiebt die Shell die Flex-Kette auf die Box, gibt dem Body eine eigene
Scrollregion und hält den Footer darunter fest:

| Element | Klasse | Aufgabe |
|---|---|---|
| `.modal-box` | `max-h-80vh`/`max-h-90vh` (über `height`) `flex flex-col` | begrenzt die Höhe, wird Flex-Column |
| Head-Wrapper (`bodyHead`) | `shrink-0` | hält den Kopf außerhalb der Scrollregion |
| Body-Wrapper | `flex-1 min-h-0 overflow-y-auto pr-2` | scrollt den Inhalt, nicht den Footer |
| Footer-Wrapper | `shrink-0` | verhindert, dass der Footer weggedrückt wird |

Die `90vh` sind **nicht** frei gewählt: das ist genau die Höhe, die die Dialoge,
die das Layout von Hand gebaut haben, bereits als `boxClassName` mitgegeben
haben. Der geteilte Modus landet damit auf der Höhe, auf die sich das Repo
schon geeinigt hatte, und nicht auf einer zweiten, konkurrierenden.

> **Historische Formulierung, ausdrücklich nicht fortgeschrieben — inzwischen
> überholt (D-11).** Dieser Absatz stand ursprünglich mit der Zahl „**drei**
> Dialoge". Diese Zahl war schon vor der Bereinigung falsch: die Migration der
> elf eigenen `modal-box`-Dialoge hat die Menge verändert, und eine Zahl über
> eine Menge zu schreiben, die sich im selben Zug bewegt, ergibt zuverlässig
> eine falsche Zahl. Der letzte Satz beschrieb damals **drei handgerollte
> begrenzte Boxen** (`GalleryAccessModal`, `PhotographerTeamModal`, `AIBatchEditModal`),
> die Inhalt **oberhalb** der Scrollregion festhalten mussten. **Diese drei
> sind mit D-11 auf die Shell migriert und handrollen nichts mehr**; sie hängen
> ihren Kopf jetzt über `bodyHead` auf (§2.2). Die damals fehlende
> Shell-Fähigkeit existiert seither, §6.3 führt die verbleibende Menge (null).

`shrink-0` liegt beim **Shell**, nicht beim Aufrufer. Deshalb gilt die
Zusage „der Footer wird nie weggeschnitten" für jeden Aufrufer und hängt nicht
daran, dass jedes Footer-Markup sich selbst die Klasse merkt.

**Das `<form>` ist Teil des Vertrags, nicht Beiwerk.** Wird
`onFormSubmit` gesetzt, bekommt das Formular im aktiven Modus
`flex flex-col flex-1 min-h-0`. Ohne diese Klasse bleibt es ein
unklassierter Block, und die automatische Mindestgröße eines Flex-Items ist
seine Inhaltshöhe: ein `flex-1`-Body unterhalb eines unklassierten Forms lässt
das Formular wachsen, statt zu scrollen, und die Box wächst mit. Die
Flex-Kette wird deshalb an das Formular gehandelt — dem einzigen Element
zwischen Box und Body. Enter-to-submit und `type="submit"` bleiben davon
unberührt, die Submit-Zeile liegt in beiden Fällen **innerhalb** des Forms.

`ModalDialogShell` reicht `scrollableBody` **und** `boxClassName` an
`ModalShell` durch. `boxClassName` fehlte dort vorher, und genau daran hing
der Modus: `GalleryModal` gab deshalb auf dem gemeinsamen Formular-Shell auf,
rendete `ModalShell` direkt, besaß ein eigenes `<form>` und duplizierte
die Submit-Zeile wörtlich — dasselbe Submit-Markup an zwei Stellen, frei zu
driften. Das war genau die Kopie, die auftreiben musste.

**Die Kopie ist weg.** Seit `ModalDialogShell` `boxClassName` durchreicht,
rendert `GalleryModal` den gemeinsamen Formular-Shell (`ModalDialogShell`,
Import in `GalleryModal.tsx:15`, Verwendung ab `GalleryModal.tsx:184`) und
nutzt den gemeinsamen Submit-Pfad. Das ist der Dialog, an dem der geteilte
Modus zuerst bewiesen wurde; die spätere Welle hat ihn auf weitere Dialoge
ausgedehnt.

### 2.1 Die benannte Höhe — `height`

Die Box-Höhe ist eine **benannte** Prop mit **statischer Klasse je Wert**; einen
freien Wert gibt es nicht. Deklariert ist sie in `ModalShell.tsx` als
`BOUNDED_HEIGHT_CLASS` mit genau zwei Einträgen:

| Wert | Klasse |
|---|---|
| `'80vh'` | `max-h-80vh` |
| `'90vh'` (Default) | `max-h-90vh` |

Beide Klassen sind `@utility`-Definitionen in `frontend/src/index.css`
(`@utility max-h-80vh` → `max-height: 80vh`, `@utility max-h-90vh` →
`max-height: 90vh`). Die Benennung ist keine Formsache: eine interpolierte
Klasse (`` `max-h-${value}` ``) sieht wie eine Lösung aus und verschwindet im
Produktions-Bundle, weil Tailwinds Content-Scan sie nicht als Literal findet.
Die Prop wirkt nur im begrenzten Modus; ohne `scrollableBody` wird sie
verworfen (wie `bodyClassName`), weil es keine begrenzte Box gibt, die sie
fassen könnte.

Der Anlass war ein Stapel: `GalleryAccessModal` und `PhotographerTeamModal`
trugen `max-h-80vh` per `boxClassName`, der Opt-in fügt aber seine eigene
`max-h-90vh` hinzu. Bei zwei Regeln gleicher Spezifität entscheidet die
Reihenfolge im Stylesheet, nicht die Absicht des Aufrufers. Mit
`height="80vh"` emittiert die Shell genau **eine** `max-h-*`-Klasse.

### 2.2 Der Kopf-Slot — `bodyHead`

Ein Dialog mit Inhalt **über** der Scrollregion hat sich bis D-11 einen
zweiten, eigenen begrenzten Box gebaut. `bodyHead` ist die geteilte Antwort:
die Shell rendert ihn zwischen Header und Body, in einem eigenen
`shrink-0`-Wrapper und **außerhalb** der Scrollregion. Ein Suchfeld, ein
Zugriffs-Select oder ein Kontext-Eingabefeld bleibt damit erreichbar, während
die Liste darunter scrollt.

Die Sperre ist die des Shells, nicht die des Aufrufers: der `shrink-0`-Wrapper
gehört zum Slot, statt eine Klasse zu sein, die jeder Dialog sich merken muss.
Der Slot wirkt nur dort, wo die Shell eine Scrollregion besitzt, über die er
gespannt werden kann (`scrollableBody`); ohne den Opt-in rendert er an
derselben Stelle, pinnt aber nichts — es gibt keine Region, über der er läge.

Der Rahmen der Liste wandert mit dem Scrollport. War der
`flex-1 overflow-y-auto border rounded-box` bisher ein dialog-eigener
Container, ist die Scrollregion jetzt der Body des Shells; der Rahmen wird über
`bodyClassName` an genau diese Region gehängt, damit er nicht mit dem Inhalt
wegscrollt.

Drei Dialoge sind mit diesem Slot migriert (D-11): `GalleryAccessModal`,
`PhotographerTeamModal` und `AIBatchEditModal`.

### 2.3 `scroll-fade-bottom` — die Affordance an der Scroll-Grenze

**Was sie tut.** `scroll-fade-bottom` ist eine Tailwind-4-`@utility` in
`frontend/src/index.css`:

```css
mask-image: linear-gradient(to bottom, #000 calc(100% - 2rem), transparent 100%);
```

Die Maske hält den Inhalt bis 2rem vor dem Ende der Padding-Box voll sichtbar
und blendet das letzte Band nach transparent aus. Eine Scrollregion, die mitten
in einer Zeile endet, liest sich sonst als Rendering-Fehler, nicht als „unten
ist mehr"; die Ausblendung macht den Schnitt zur Affordance. `#000` und
`transparent` sind hier keine Farben, sondern Alpha-Werte — der Maskenkanal
läuft von deckend nach verborgen.

**Wo die Grenze liegt — und warum.** Die Maske muss auf dem Element liegen, das
**scrollt** (`overflow-y-auto`), nicht auf dem Element, das den Scrollport
**begrenzt**. Eine Maske beschreibt die eigene Malfläche; nur ein
Scroll-Container hat einen festen Rand, unter dem der Inhalt beim Scrollen
durchläuft. Auf einer nicht scrollenden Box, die den Body nur umschließt, läuft
nichts unter dieser Grenze durch: die Ausblendung maskiert dort **nichts**, und
der Schnitt bleibt sichtbar. Im bounded Modus ist die Box
(`max-h-90vh flex flex-col`) genau so ein umschließender Rahmen; die
Scrollregion ist der Body (`flex-1 min-h-0 overflow-y-auto`, §2). Deshalb
wandert `scroll-fade-bottom` mit dem Scrollport auf den Body — zusammen mit dem
`pb-10`/`pb-8`, das den Inhalt von dem 2rem breiten Maskenband freihält.

**Der gemessene Fix an `ModelDetailModal`.** Der Dialog trug
`overflow-y-auto pb-10 scroll-fade-bottom` noch auf der Box, während der Body
bereits scrollte. Die Box blendete damit nichts mehr aus, und die Grenze schnitt
„Linz"/„Österreich" mitten durch die Glyphen — der Befund aus der UI-Review. Der
Fix verschiebt den Scrollport und die Ausblendung gemeinsam auf den Body:

```tsx
boxClassName="max-w-4xl"
bodyClassName="pb-10 scroll-fade-bottom"
scrollableBody
```

`ModalShell` reicht `bodyClassName` nur auf dem `scrollableBody`-Zweig an den
Body weiter (ohne den Opt-in ist die Prop wirkungslos), und der Aufrufer bleibt
dafür zuständig, das Padding an der Maske zu halten: `pb-8` ist exakt die
Maskenbreite von 2rem, `pb-10` (2.5rem) lässt Luft. Wäre die Maske auf der Box
geblieben, hätte sie nichts maskiert, weil die Box seither nicht mehr scrollt.
Der Footer braucht kein solches Padding — er liegt außerhalb der Maske, und
genau das ist der Punkt.

**Merksatz.** `scroll-fade-bottom` gehört auf dasselbe Element, das auch
scrollt: im Default-Layout (die Box ist die Scrollregion) auf die Box, im
bounded Modus auf den Body. Scrollport und Ausblendung werden immer **zusammen**
verschoben.

## 3. Warum opt-in und nicht Default

Der Default ist die **richtige** Form für einen Dialog, der passt. Ein
Default-Wechsel ist hier keine Verbreitung einer Verbesserung, sondern das
Verschieben einer Grenze unter **alle 28 Dialoge**, die durch diese Shells
rendern (§6), und das an einem Tag. Drei Gründe dagegen:

- **Der Default ist für einen passenden Dialog korrekt.** Header, Body und
  Footer in einer Spalte ist für den Normalfall die richtige Antwort; die
  bounded Layoutform für einen Dialog, der ohnehin nicht scrollt, ist
  zusätzliche Struktur ohne zusätzlichen Nutzen.
- **Drei Dialoge bauten sich das bounded Layout bis D-11 selbst — inzwischen
  migriert.** `GalleryAccessModal` und `PhotographerTeamModal` trugen
  `max-h-80vh flex flex-col` per `boxClassName`, `AIBatchEditModal`
  `h-90vh flex flex-col` — jeweils mit einem eigenen `flex-1 overflow-y-auto`
  als Body. Das war eine zweite, parallele Implementierung derselben Idee, und
  genau der Grund, aus dem ein **Default**-Wechsel hier nicht trägt: er hätte
  ihnen eine zweite, verschachtelte Scrollregion gegeben. D-11 hat ihnen
  stattdessen den fehlenden Shell-Baustein gegeben (`bodyHead` plus benannte
  Höhe, §2.1/§2.2) und sie damit auf dieselbe Lösung gezogen.
  `GalleryModal` war der Dialog, an dem die geteilte Lösung zuerst durchgezogen
  wurde (§2); `RatingStatusModal` und die Kamera-Anleitung in
  `ManagementFtpInbox` sind ebenfalls auf `scrollableBody` umgestellt (§6.2,
  Nr. 19 und 24).
- **Einige Dialoge haben die Grenze bewusst gestellt.** `ModelDetailModal`
  blendet die letzten `2rem` per `scroll-fade-bottom` aus und hält `pb-10`
  frei davon — das liest sich nur auf dem Element als „unten ist mehr", das
  tatsächlich scrollt (§2.3). Genau dieses Detail ist an dem Dialog bereits
  aufgefallen: als der Body zur Scrollregion wurde, beschrieb die auf der Box
  verbliebene Ausblendung ein Band, unter dem nichts mehr durchlief, und der
  Hinweis zeigte ins Leere. Der Fix hat Maske und Scrollport gemeinsam auf den
  Body verschoben. Ein Default-Wechsel müsste diese Verschiebung für alle 28
  Dialoge auf einmal leisten, statt sie pro Dialog zu entscheiden.

Der Preis des Defaults wäre also eine sichtbare Änderung überall, um die
Dialoge zu reparieren, deren Inhalt die Viewport-Höhe tatsächlich
überschreitet — genau die, die den Opt-in gesetzt haben (§6.2). Opt-in dreht
die Beweislast um: wer umschaltet, kann begründen, dass sein Inhalt zu groß ist.

## 4. Die Regel

**Ein Dialog schaltet genau einmal um, und nur dann, wenn sein Inhalt die
Viewport-Höhe überschreiten kann.** Kein Dialog schaltet aus Bequemlichkeit um,
keiner schaltet für „kann ja nicht schaden" um, und ein umgestellter Dialog
schaltet nicht wieder zurück, wenn sein Inhalt gerade kurz ist.

Praktisch heißt das: `scrollableBody` wird gesetzt, **wenn** der Dialog
mindestens ein Formular oder eine Liste enthält, die bei realer Datenmenge
über die Falz wächst. Wer es nicht begründen kann, lässt es aus.

## 5. Bekannte Folgepunkte

Ein Vertrag ohne seine offenen Enden wird missverstanden. Die folgenden Punkte
sind inzwischen erledigt und stehen als Beleg hier.

- **`ModelDetailModal` — erledigt 2026-09-27.** Der Dialog hat den
  `bodyClassName`-Escape-Hatch bekommen und umgestellt: statt
  `boxClassName="max-w-4xl max-h-90vh overflow-y-auto pb-10 scroll-fade-bottom"`
  trägt er `scrollableBody` plus `bodyClassName="pb-10 scroll-fade-bottom"`.
  Die Ausblendung liegt damit auf dem Body, dem Element, das tatsächlich
  scrollt (§2.3). Auf der Box hätte sie nichts mehr maskiert, weil die Box
  seither nicht mehr scrollt. Der Punkt bleibt hier, weil der Vertrag damit an
  einem echten Dialog durchgezogen ist und nicht nur beschrieben.
- **Die drei handgerollten begrenzten Boxen — erledigt mit D-11 (2026-09-28).**
  `GalleryAccessModal`, `PhotographerTeamModal` und `AIBatchEditModal` hängen
  ihren Kopf jetzt über `bodyHead` außerhalb der Scrollregion und nennen ihre
  Höhe über `height` (`80vh`, `80vh`, `90vh`), statt eine zweite `.modal-box`
  zu bauen (§2.1/§2.2). Damit ist die zweite, parallele Implementierung aus §3
  weg; §6.3 führt die verbleibende Menge (null).
- **Die elf Dialoge, die ihr eigenes `modal-box` bauten, sind migriert.**
  Über `ModalShell` laufen `UserPermissionsModal`, `ModelInviteDialog` und der
  Inline-Dialog in `ManagementOrdersView`; über `ModalDialogShell`
  `TextSnippetModal`, `ProductModal`, `CustomerModal`, `CreateUserModal`,
  `ProjectModal`, `CouponFormDrawer` und die Inline-Dialoge in
  `ManagementOrgsView` und `ManagementOrgDetailView`. Drei von ihnen haben
  dabei den bounded Modus bekommen, weil ihr Inhalt die Falz real
  überschreitet: `ModelInviteDialog` (unbegrenzte Einladungsliste),
  `TextSnippetModal` (Editor) und `CouponFormDrawer`. Damit ist die Gruppe der
  eigenen `modal-box` leer — `ModalShell` ist im Frontend die einzige Stelle,
  die überhaupt ein `<dialog>` oder eine `.modal-box` erzeugt (§6). Der Vertrag
  ist damit nicht mehr die bevorzugte, sondern die einzige Art, wie hier ein
  Dialog entsteht; ein neu aufkommender Dialog hat keine Ausnahme mehr, die er
  zitieren könnte.

## 6. Das Dialog-Inventar

Alles, was in `frontend/src/` einen Dialog erzeugt, steht hier. Die Zahlen in
§3 und §5 stammen aus dieser Liste, nicht aus einer Schätzung.

### 6.1 Zählregel

Ein **Dialog** ist eine Renderstelle, die ein `<dialog>`-Element erzeugt. In
diesem Frontend ist das äquivalent zu einem JSX-Aufruf von `ModalShell` oder
`ModalDialogShell`, denn `ModalShell` ist die einzige Datei, die `<dialog>` und
`.modal-box` schreibt (verifiziert über `grep -rn 'modal-box'` und
`grep -rn '<dialog'` in `frontend/src/`, ohne Tests).

Gezählt wird **pro Renderstelle, nicht pro Instanz und nicht pro Aufrufer**.
Konkret heißt das:

- `ModalDialogShell` zählt als *ein* Dialog, nicht als zwei. Sein eigener
  interner Aufruf von `ModalShell` ist derselbe Dialog, kein zweiter.
- Der globale Bestätigungsdialog in `UIProvider` zählt **einmal**, auch wenn
  `confirm()` an Dutzenden Stellen aufgerufen wird. Gezählt wird, wo er
  gerendert wird, nicht wo er benutzt wird.
- Verschachtelte Dialoge zählen einzeln: `AIGalleryDefaultsModal` in
  `GalleryMetadataDefaultsModal` und `GalleryGroupModal` (über
  `GalleryModals`) sind eigene Renderstellen.
- `VolumePresetSettingsCard` zählt mit: der Dialog steckt in der Card, ist
  aber eine eigene Renderstelle.
- Keine Dialoge sind: Toasts (`UIProvider`, `role="alert"`), Dropdowns und
  Popovers. `CouponFormDrawer` **ist** einer — der Name ist historisch, der
  Dialog rendert über `ModalDialogShell`.
- Testdateien (`__tests__/`, `*.test.tsx`) zählen nicht mit.

**Die Shell-Props, auf denen dieses Inventar aufsetzt.** `ModalShell` besitzt die
Box und gibt sie dem Aufrufer über drei Hebel: `boxClassName` (freie Klassen),
`testId` (optionales `data-testid` **auf der `.modal-box`**, D-18) sowie
`height`/`bodyHead` (§2.1/§2.2). `ModalDialogShell` reicht `scrollableBody`,
`boxClassName` und `testId` durch; `height`/`bodyHead` sind dort **nicht**
exponiert, weil kein Formular-Dialog sie bisher braucht. `testId` ist die
Antwort auf den Wrapper, den `ModelInviteDialog` sonst nur für
`data-testid="model-invite-dialog"` um seinen Inhalt gelegt hätte: der Hook
sitzt auf der Box, die die Shell ohnehin rendert, und ohne Wert rendert die Box
kein `data-testid` — die übrigen Dialoge bleiben byte-gleich.

### 6.2 Gruppe A — über die Shells: 28

| # | Dialog | Shell | `scrollableBody` | Datei |
|---|---|---|---|---|
| 1 | Galerie anlegen/bearbeiten | `ModalDialogShell` | ja | `ui/components/GalleryModal.tsx` |
| 2 | Meta-Galerie anlegen/bearbeiten | `ModalDialogShell` | nein | `ui/components/GalleryGroupModal.tsx` |
| 3 | Änderungshistorie | `ModalShell` | nein | `ui/components/PhotoHistoryModal.tsx` |
| 4 | Globaler Bestätigungsdialog | `ModalDialogShell` | nein | `ui/components/UIProvider.tsx` |
| 5 | KI-Beschriftung | `ModalShell` | ja | `ui/management/components/AIBatchEditModal.tsx` |
| 6 | KI-Vorgaben-Vorschlag | `ModalShell` | nein | `ui/management/components/AIGalleryDefaultsModal.tsx` |
| 7 | Rabattcode anlegen/bearbeiten | `ModalDialogShell` | ja | `ui/management/components/CouponFormDrawer.tsx` |
| 8 | Nutzer einladen | `ModalDialogShell` | nein | `ui/management/components/CreateUserModal.tsx` |
| 9 | Kunde anlegen/bearbeiten | `ModalDialogShell` | nein | `ui/management/components/CustomerModal.tsx` |
| 10 | Nachricht an Kunden | `ModalShell` | nein | `ui/management/components/EmailComposerModal.tsx` |
| 11 | Nutzer-Zugriff (Galerie) | `ModalShell` | ja | `ui/management/components/GalleryAccessModal.tsx` |
| 12 | Metadaten-Vorgaben | `ModalShell` | nein | `ui/management/components/GalleryMetadataDefaultsModal.tsx` |
| 13 | Einladungen verwalten | `ModalShell` | nein | `ui/management/components/InviteModal.tsx` |
| 14 | Model-Detail | `ModalShell` | ja | `ui/management/components/ModelDetailModal.tsx` |
| 15 | Einladung erstellen | `ModalShell` | ja | `ui/management/components/ModelInviteDialog.tsx` |
| 16 | Fotografen-Team | `ModalShell` | ja | `ui/management/components/PhotographerTeamModal.tsx` |
| 17 | Katalog-Eintrag | `ModalDialogShell` | nein | `ui/management/components/ProductModal.tsx` |
| 18 | Projekt anlegen/bearbeiten | `ModalDialogShell` | nein | `ui/management/components/ProjectModal.tsx` |
| 19 | Bewertungen & Status | `ModalShell` | ja | `ui/management/components/RatingStatusModal.tsx` |
| 20 | Tarif-Rechner | `ModalShell` | nein | `ui/management/components/ShootingCalculatorModal.tsx` |
| 21 | Textbaustein | `ModalDialogShell` | ja | `ui/management/components/TextSnippetModal.tsx` |
| 22 | Nutzer bearbeiten | `ModalShell` | nein | `ui/management/components/UserPermissionsModal.tsx` |
| 23 | Volume-Preset | `ModalDialogShell` | nein | `ui/management/components/VolumePresetSettingsCard.tsx` |
| 24 | Kamera einrichten | `ModalShell` | ja | `ui/management/ManagementFtpInbox.tsx` |
| 25 | Angebot kalkulieren & senden | `ModalShell` | nein | `ui/management/ManagementOrdersView.tsx` |
| 26 | Nutzer in Organisation einladen | `ModalDialogShell` | nein | `ui/management/ManagementOrgDetailView.tsx` |
| 27 | Organisation anlegen | `ModalDialogShell` | nein | `ui/management/ManagementOrgsView.tsx` |
| 28 | Auftrag anlegen/bearbeiten | `ModalDialogShell` | nein | `ui/photographer/components/PhotoJobModal.tsx` |

15 über `ModalShell` direkt, 13 über `ModalDialogShell`. Davon **10** mit
`scrollableBody` (Nr. 1, 5, 7, 11, 14, 15, 16, 19, 21, 24), 18 im
Default-Layout. Keine handgerollte begrenzte Box mehr, siehe §6.3. Der Zähler
ist aus der `ja`-Spalte dieser Tabelle reproduzierbar (Repo-Root):
`grep -E '^\| [0-9]+ \|' features/tech/08-dialog-height-contract.md | grep -c '| ja |'`
→ 10.

### 6.3 Gruppe B — eigenes Markup: 0

Es gibt keine Renderstelle in `frontend/src/`, die eine `.modal-box` oder ein
`<dialog>` selbst baut. Wer in diesem Frontend einen Dialog baut, benutzt
zwangsläufig eine der beiden Shells und erbt damit `role="dialog"`,
`aria-modal`, den zugänglichen Namen, die Fokusfalle, Escape und den
Backdrop-Klick.

Die frühere Teilmenge, die §3 als „zweite, parallele Implementierung" führte,
ist **leer**. `RatingStatusModal` und die Kamera-Anleitung in
`ManagementFtpInbox` waren früher auf `scrollableBody` umgestellt worden
(§6.2, Nr. 19 und 24); die letzten drei — `GalleryAccessModal`,
`PhotographerTeamModal`, `AIBatchEditModal` — sind mit D-11 nachgezogen und
hängen ihren Kopf über `bodyHead` auf (§2.2). Kein Dialog setzt seinen
begrenzten Box mehr selbst.

| Dialog | vorher `boxClassName` | heute |
|---|---|---|
| `GalleryAccessModal` | `max-w-2xl flex flex-col max-h-80vh` | `height="80vh"` + `bodyHead` |
| `PhotographerTeamModal` | `max-w-2xl flex flex-col max-h-80vh` | `height="80vh"` + `bodyHead` |
| `AIBatchEditModal` | `w-11/12 max-w-7xl h-90vh flex flex-col` | `height="90vh"` + `bodyHead` |

Ein Dialog aus Gruppe A kann beides kombinieren — der Modus und eine eigene
Höhe. `TextSnippetModal` tut das: `scrollableBody` plus
`boxClassName="max-w-4xl h-80vh"`. Die feste Höhe bleibt die engere der beiden
Grenzen, die Box ist also so hoch wie immer; der Modus hat hier nur die
Scrollregion verschoben. Das ist kein Widerspruch, sondern der Normalfall für
einen Dialog mit fester Höhe.

### 6.4 Woher die Zahlen kommen

Die frühere Zahl **18** war zu Recht, aber nur für einen früheren Stand. Sie ist
gegen `HEAD` (`161dc9e`) nachgerechnet und geht auf:

| Stand | über die Shells | eigenes `modal-box` | Summe |
|---|---|---|---|
| `HEAD` | 18 | 11 | 29 |
| Arbeitsstand | 28 | 0 | 28 |

Die 18 waren die Aufrufstellen von `ModalShell`/`ModalDialogShell` in `HEAD`,
abzüglich des internen Aufrufs in `ModalDialogShell` selbst. Danach kamen zwei
Veränderungen: die elf Dialoge aus §5 sind dazugekommen (+11), und
`frontend/src/ui/client/components/LicenseSelectorModal.tsx` wurde gelöscht,
ohne Ersatz (−1) — der Pfad existiert nicht mehr. 18 + 11 − 1 = 28.

Wer diese Zahl künftig zitiert, muss sie gegen
`grep -rnE '<Modal(Shell|DialogShell)' frontend/src` neu zählen. Die Liste oben
ist der Soll-Zustand, keine Dauerzahl.
