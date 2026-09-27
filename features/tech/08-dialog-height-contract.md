# Dialog-Höhenvertrag — `ModalShell` / `ModalDialogShell`

> **Status:** Soll-Zustand.
> Domain: `tech`
> Stand: 2026-09-27
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
| `.modal-box` | `max-h-90vh flex flex-col` | begrenzt die Höhe, wird Flex-Column |
| Body-Wrapper | `flex-1 min-h-0 overflow-y-auto pr-2` | scrollt den Inhalt, nicht den Footer |
| Footer-Wrapper | `shrink-0` | verhindert, dass der Footer weggedrückt wird |

Die `90vh` sind **nicht** frei gewählt: das ist genau die Höhe, die die drei
Dialoge, die das Layout vorher von Hand gebaut haben, bereits als
`boxClassName` mitgegeben haben. Der geteilte Modus landet damit auf der Höhe,
auf die sich das Repo schon geeinigt hatte, und nicht auf einer zweiten,
konkurrierenden.

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
rendert `ModalShell` direkt, besitzt ein eigenes `<form>` und dupliziert die
Submit-Zeile wörtlich — dasselbe Submit-Markup an zwei Stellen, frei zu
driften. Das war genau die Kopie, die auftreiben musste.

## 3. Warum opt-in und nicht Default

Der Default ist die **richtige** Form für einen Dialog, der passt. Ein
Default-Wechsel ist hier keine Verbreitung einer Verbesserung, sondern das
Verschieben einer Grenze unter **alle 18 Dialoge**, die durch diese Shells
rendern, und das an einem Tag. Drei Gründe dagegen:

- **Der Default ist für einen passenden Dialog korrekt.** Header, Body und
  Footer in einer Spalte ist für den Normalfall die richtige Antwort; die
  bounded Layoutform für einen Dialog, der ohnehin nicht scrollt, ist
  zusätzliche Struktur ohne zusätzlichen Nutzen.
- **Drei Dialoge haben sich das bounded Layout bereits selbst gebaut.**
  `RatingStatusModal`, die Kamera-Anleitung in `ManagementFtpInbox` und
  `GalleryModal` haben per `boxClassName` plus `flex-1 overflow-y-auto` eine
  zweite, parallele Implementierung derselben Idee. Ein Default-Wechsel würde
  ihnen eine **zweite, verschachtelte** Scrollregion geben, während die
  geteilte Lösung sie schrittweise ablösen soll.
- **Einige Dialoge haben die Grenze bewusst gestellt.** `ModelDetailModal`
  blendet die letzten `2rem` per `scroll-fade-bottom` aus und hält `pb-10`
  frei davon. Das liest sich nur deshalb als „unten ist mehr", weil die Box
  selbst das Ding ist, das scrollt. Wird der Body zur Scrollregion und die
  Box zum Rahmen, beschreibt die Ausblendung weiterhin ein Band, das nicht
  mehr am Scroll-Rand liegt — der Hinweis zeigt ins Leere.

Der Preis des Defaults wäre also eine sichtbare Änderung überall, um zwei
Dialoge zu reparieren, deren Inhalt die Viewport-Höhe tatsächlich
überschreitet. Opt-in dreht die Beweislast um: wer umschaltet, kann begründen,
dass sein Inhalt zu groß ist.

## 4. Die Regel

**Ein Dialog schaltet genau einmal um, und nur dann, wenn sein Inhalt die
Viewport-Höhe überschreiten kann.** Kein Dialog schaltet aus Bequemlichkeit um,
keiner schaltet für „kann ja nicht schaden" um, und ein umgestellter Dialog
schaltet nicht wieder zurück, wenn sein Inhalt gerade kurz ist.

Praktisch heißt das: `scrollableBody` wird gesetzt, **wenn** der Dialog
mindestens ein Formular oder eine Liste enthält, die bei realer Datenmenge
über die Falz wächst. Wer es nicht begründen kann, lässt es aus.

## 5. Bekannte Folgepunkte

Beides ist offen und bewusst nicht in diesem Change erledigt; beides steht
hier, weil ein Vertrag ohne seine offenen Enden missverstanden wird.

- **`ModelDetailModal` hat das Symptom weiterhin.** Es rendert über
  `ModalShell` mit `boxClassName="max-w-4xl max-h-90vh overflow-y-auto
  pb-10 scroll-fade-bottom"` — die Box ist also weiterhin die Scrollregion,
  nur in Handarbeit. Ihm fehlt ein **`bodyClassName`-Escape-Hatch** an
  `ModalShell`, um den Scrollbereich überhaupt zu adressieren, statt die
  scrollfähigkeit über die Box zu erzwingen. Mit diesem Schalter muss die
  Ausblendung neu eingestellt werden: `scroll-fade-bottom` und das `pb-10`
  sind gegen den Schnitt der **Box** getuntet und verlieren ihren Sinn,
  sobald der **Body** scrollt.
- **Die Dialoge, die ihr eigenes `modal-box` bauen, sind die zweite
  Migrationswelle.** `UserPermissionsModal`, `TextSnippetModal`,
  `ModelInviteDialog`, `ProductModal`, `CustomerModal`, `CreateUserModal`,
  `ProjectModal` und `CouponFormDrawer` gehen nicht durch die Shells und
  bringen ihre Scrollregion deshalb selbst mit. Das ist **keine
  Einzeiler-Änderung**: jeder von ihnen trägt Markup, das die Shells nicht
  kennen, und eine Umstellung ist pro Dialog eine eigene Entscheidung
  darüber, ob er in den bounded Modus gehört. Zusätzlich rendern
  `ManagementOrdersView`, `ManagementOrgsView` und `ManagementOrgDetailView`
  ebenfalls eigene `.modal-box`-Container; die Liste hier ist damit nicht
  abschließend, sie ist der Kern der Welle.
