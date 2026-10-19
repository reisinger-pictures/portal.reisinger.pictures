<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\ModelPhoto;
use App\Models\ModelProfile;
use App\Support\BrandRegistry;
use App\Values\BrandConfig;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Rendert ein druckfähiges Contact Sheet (PDF) zu einem Model-Profil.
 *
 * Zwei bewusst getrennte Varianten (strikte Privacy-Trennung, §3 des Plans):
 *  - `internal` — bis zu {@see self::MAX_PHOTOS} Fotos (public + internal),
 *    Kontaktdaten/PII, Altersnachweis-Status, voller Answers-Snapshot
 *    (Sektionen wie im Formular), Agentur, Erfahrungs-/Bereitschafts-Matrix.
 *    Kein Wasserzeichen.
 *  - `external` — ausschließlich `public`-Fotos, Anzeigename (nur
 *    Künstlername, niemals der bürgerliche Name) + Ort,
 *    Erfahrungs-/Bereitschafts-Matrix, Brand-Header und dezentes
 *    Diagonal-Wasserzeichen. Kein Geburtsdatum, kein Ausweis-Status, keine
 *    Kontakt-/Adressdaten, keine Notizen/internen Keys, keine Original-
 *    Dateinamen als Foto-Caption.
 *
 * Das Foto-Limit {@see self::MAX_PHOTOS} ist ein bewusstes Speicher-Limit und
 * gilt für BEIDE Varianten: auch `internal` rendert nie mehr als 12 Fotos,
 * selbst wenn das Profil mehr Uploads enthält (die Zusage „alle Fotos" ist
 * damit auf 12 begrenzt).
 *
 * Bilder werden entschlüsselt ({@see ModelFileStore::getDecrypted}) und als
 * Data-URI in die Blade-View eingebettet: DomPDF macht keine externen
 * Requests, und es entsteht keine dauerhafte Klartext-Ablage auf Disk.
 * Gespeicherte Fotos, die das Budget für persistierte Modellfotos
 * überschreiten oder sich nicht decodieren lassen, werden wie andere
 * unbrauchbare Fotos ausgelassen. Die Garantie lautet **keine ungeprüften
 * Bytes**: jedes eingebettete Foto hat die Byte-/MIME-/Dimensions-Vorprüfung
 * und einen erfolgreichen GD-Decode hinter sich. Sie lautet nicht „keine
 * Rohbytes" — JPEG/PNG werden danach unverändert weitergereicht.
 *
 * Ausgelassene Fotos werden **nicht stillschweigend** verworfen (D-41): der
 * Kontaktbogen nennt die Anzahl und den Grund der Auslassung
 * ({@see self::OMISSION_PRECEDENCE}). Zwei Kategorien bleiben getrennt:
 * Fotos, die an einer Prüfung gescheitert sind (Byte-Limit, nicht
 * dekodierbar, MIME-Prüfung, Größen-/Pixel-Limit, fehlende Datei), und
 * Fotos, die nie Kandidaten waren, weil {@see self::MAX_PHOTOS} das
 * Speicher-Limit gesetzt hat. Der Hinweis enthält ausschließlich Zählwerte
 * und Grundbezeichnungen — keine Dateinamen, keine Kunden-Daten, keine
 * Foto-Kennungen (PII-frei, auch in der externen Variante).
 *
 * Labels/Codes kommen ausschließlich aus {@see ModelQuestionnaire} — die
 * Farb-/Label-Logik wird nicht dupliziert, sondern über die dort zentral
 * definierten `EXPERIENCE_LABELS`/`WILLINGNESS_LABELS`/Kategorien aufgelöst.
 * Die Labels der Auslassungsgründe gehören nicht dazu: sie haben keinen
 * Katalog und stehen in {@see self::OMISSION_LABELS}.
 */
class ModelContactSheetService
{
    public const VARIANT_INTERNAL = 'internal';

    public const VARIANT_EXTERNAL = 'external';

    public const VARIANTS = [self::VARIANT_INTERNAL, self::VARIANT_EXTERNAL];

    /**
     * Bewusstes Speicher-Limit gegen die Last vieler Data-URIs in einem PDF.
     *
     * Gilt für beide Varianten: mit mehr als 12 eingebetteten Fotos wächst das
     * DomPDF-Speicherprofil über die akzeptable Grenze. Auch `internal` rendert
     * deshalb höchstens 12 Fotos — die Zusage „alle Fotos" endet hier.
     */
    public const MAX_PHOTOS = 12;

    /**
     * Gründe, warum ein Foto nicht im Kontaktbogen landet (D-41).
     *
     * Jedes ausgelassene Foto wird **genau einmal** gezählt — unter seinem
     * primären Grund. Die Reihenfolge in {@see self::OMISSION_PRECEDENCE}
     * bestimmt diesen primären Grund, wenn mehrere Gründe zutreffen.
     */
    public const OMISSION_UNAVAILABLE = 'unavailable';

    public const OMISSION_BYTE = 'byte';

    public const OMISSION_DECODE = 'decode';

    public const OMISSION_MIME = 'mime';

    public const OMISSION_DIMENSIONS = 'dimensions';

    /**
     * Präzedenz des primären Grundes (erster Treffer gewinnt):
     *
     *  1. `unavailable` — die Datei fehlt im Store bzw. der Inhalt ist leer.
     *  2. `byte` — die Bytes überschreiten `PhotoProcessingService::MAX_IMAGE_BYTES`.
     *  3. `decode` — die Bytes sind kein parsebares Raster (JPEG/PNG/WebP).
     *  4. `mime` — die erkannte MIME weicht von der gespeicherten
     *     `mime_type` ab (oder ist kein unterstützter Typ).
     *  5. `dimensions` — Kantenlänge bzw. Pixelzahl verletzen das Bild-Budget.
     *
     * `byte` steht vorn, weil der Byte-Cap die erste Prüfung der Pipeline
     * ist; `decode` steht vor `mime`, weil ein nicht parsebares Raster die
     * MIME-Abweichung erst verursacht (der MIME-Befund wäre dann eine
     * Folge, keine eigenständige Ursache).
     */
    public const OMISSION_PRECEDENCE = [
        self::OMISSION_UNAVAILABLE,
        self::OMISSION_BYTE,
        self::OMISSION_DECODE,
        self::OMISSION_MIME,
        self::OMISSION_DIMENSIONS,
    ];

    /**
     * Anzeige-Labels der Auslassungsgründe (deutsch, PII-frei).
     */
    public const OMISSION_LABELS = [
        self::OMISSION_UNAVAILABLE => 'Datei nicht verfügbar',
        self::OMISSION_BYTE => 'Byte-Limit',
        self::OMISSION_DECODE => 'nicht dekodierbar',
        self::OMISSION_MIME => 'MIME-Prüfung',
        self::OMISSION_DIMENSIONS => 'Größen-/Pixel-Limit',
    ];

    /**
     * Überschrift des Auslassungs-Hinweises im PDF.
     */
    public const OMISSION_NOTE_TITLE = 'Nicht enthaltene Fotos';

    public function __construct(
        private readonly ModelFileStore $fileStore,
        private readonly ModelQuestionnaire $questionnaire,
    ) {}

    public function render(ModelProfile $profile, string $variant): string
    {
        if (! in_array($variant, self::VARIANTS, true)) {
            throw new InvalidArgumentException("Unbekannte Contact-Sheet-Variante: {$variant}");
        }

        $profile->loadMissing(['customer', 'photos']);

        $external = $variant === self::VARIANT_EXTERNAL;
        $brand = $this->brandConfig($profile);
        $selection = $this->selectPhotos($profile, $external);

        $data = array_merge([
            'variant' => $variant,
            'isExternal' => $external,
            'generatedAt' => now(),
            'brandName' => $brand->name,
            'primaryColor' => $brand->primaryColor,
            'secondaryColor' => $brand->secondaryColor,
            'logoDataUri' => $this->logoDataUri($brand),
            'watermarkText' => $external ? $brand->name : null,
            'photos' => $selection['photos'],
            'omissions' => $selection['omissions'],
        ], $external ? $this->externalData($profile) : $this->internalData($profile));

        // `compress => 0`: Die Bilddaten liegen bereits komprimiert als
        // Data-URI-Stream vor; der Text-Content-Stream bleibt dadurch
        // inspizierbar (konsistent zur bestehenden OFFER_JWT-Marker-Logik) und
        // die Assertions der Feature-Tests können den gerenderten Text prüfen.
        return Pdf::loadView('pdf.model-contact-sheet', $data)
            ->setPaper('a4')
            ->output(['compress' => 0]);
    }

    /**
     * Interne Variante: alles inkl. PII, Altersnachweis-Status und Snapshot.
     *
     * @return array<string, mixed>
     */
    private function internalData(ModelProfile $profile): array
    {
        $customer = $profile->customer;
        $answers = $profile->answersMap();
        $age = $customer?->birthdate?->age;

        $ageProof = match (true) {
            $profile->age_proof_uploaded_at !== null => 'Vorhanden (hochgeladen am '.$profile->age_proof_uploaded_at->format('d.m.Y H:i').')',
            (bool) $profile->age_proof_required => 'Angefordert, nicht hochgeladen',
            default => 'Nicht erforderlich',
        };

        return [
            'displayName' => $customer?->name,
            'city' => $customer?->city,
            'facts' => $this->compactFacts([
                ['label' => 'Name', 'value' => $customer?->name],
                ['label' => 'Künstlername', 'value' => $answers['stage_name'] ?? null],
                ['label' => 'E-Mail', 'value' => $customer?->email],
                ['label' => 'Telefon', 'value' => $answers['phone'] ?? null],
                ['label' => 'Adresse', 'value' => $this->address($customer)],
                ['label' => 'Geburtsdatum', 'value' => $customer?->birthdate?->format('d.m.Y')],
                ['label' => 'Alter', 'value' => $age !== null ? (string) $age : null],
                ['label' => 'Kunden-ID', 'value' => $profile->customer_id],
            ]),
            'ageProofStatus' => $ageProof,
            'matrix' => $this->matrix($profile),
            'answersSections' => $this->answersSections($profile),
        ];
    }

    /**
     * Externe Variante: bewusst minimale Datenselektion ohne jede PII.
     *
     * @return array<string, mixed>
     */
    private function externalData(ModelProfile $profile): array
    {
        $customer = $profile->customer;
        $answers = $profile->answersMap();
        $stageName = trim((string) ($answers['stage_name'] ?? ''));

        return [
            // Extern NIE den bürgerlichen Namen als Fallback (PII): ohne
            // Künstlernamen bleibt nur ein generischer Platzhalter.
            'displayName' => $stageName !== '' ? $stageName : 'Model',
            'city' => $customer?->city,
            'matrix' => $this->matrix($profile),
        ];
    }

    /**
     * Erfahrungs-/Bereitschafts-Matrix über die vom Model gewählten
     * Kategorien (plus Stock), Labels zentral aus dem Fragenkatalog.
     *
     * @return array<int, array{category: string, experience: ?string, willingness: ?string}>
     */
    private function matrix(ModelProfile $profile): array
    {
        $answers = $profile->answersMap();
        $categories = $this->questionnaire->categories();
        $selected = $profile->categories();

        $rows = [];
        foreach ($categories as $key => $category) {
            if (! in_array($key, $selected, true)) {
                continue;
            }

            $rows[] = [
                'category' => $category['label'],
                'experience' => $this->experienceLabel($answers['experience_'.$key] ?? null),
                'willingness' => $this->willingnessLabel($answers['willingness_'.$key] ?? null),
            ];
        }

        $stock = $answers['willingness_stock'] ?? null;
        if (is_string($stock) && $stock !== '') {
            $rows[] = [
                'category' => 'Stock-Fotos',
                'experience' => null,
                'willingness' => $this->willingnessLabel($stock),
            ];
        }

        return $rows;
    }

    /**
     * Answers-Snapshot in die Formular-Sektionen gruppiert (nur intern).
     *
     * @return array<int, array{label: string, items: array<int, array{label: string, value: string}>}>
     */
    private function answersSections(ModelProfile $profile): array
    {
        $snapshot = $profile->answers ?? [];
        if (! is_array($snapshot) || $snapshot === []) {
            return [];
        }

        $sections = $this->questionnaire->sections();

        // Frage-Key → Sektions-Key (Reihenfolge = Katalog-Reihenfolge).
        $sectionOf = [];
        foreach ($sections as $sectionKey => $section) {
            foreach ($section['questions'] as $question) {
                $sectionOf[$question['key']] = $sectionKey;
            }
        }

        $grouped = [];
        foreach ($snapshot as $item) {
            if (! is_array($item) || ! isset($item['key'])) {
                continue;
            }

            $sectionKey = $sectionOf[(string) $item['key']] ?? 'sonstiges';
            $grouped[$sectionKey][] = [
                'label' => (string) ($item['label'] ?? $item['key']),
                'value' => $this->formatAnswerValue($item),
            ];
        }

        $result = [];
        foreach (array_keys($sections) as $sectionKey) {
            if (empty($grouped[$sectionKey])) {
                continue;
            }

            $result[] = [
                'label' => $sections[$sectionKey]['label'],
                'items' => $grouped[$sectionKey],
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function formatAnswerValue(array $item): string
    {
        $value = $item['value'] ?? null;

        if (($item['type'] ?? 'text') === 'checkbox') {
            return $value ? 'Ja' : 'Nein';
        }

        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }

        if ($value === null || $value === '') {
            return '–';
        }

        $key = (string) ($item['key'] ?? '');
        if (str_starts_with($key, 'willingness_')) {
            return $this->willingnessLabel((string) $value) ?? '–';
        }
        if (str_starts_with($key, 'experience_')) {
            return $this->experienceLabel((string) $value) ?? '–';
        }

        return (string) $value;
    }

    /**
     * Wählt die Fotos aus und zählt die Auslassungen (D-41).
     *
     * Zwei getrennte Kategorien:
     *  - **Prüfung**: Fotos, die Kandidaten waren, aber eine Prüfung nicht
     *    bestanden haben (Byte-Limit, Decode, MIME, Größe, fehlende Datei).
     *  - **Limit**: Fotos, die wegen {@see self::MAX_PHOTOS} nie Kandidat
     *    waren. Sie sind ausdrücklich **keine** fehlgeschlagene Prüfung und
     *    werden nie gegen die Prüfungsgründe gezählt.
     *
     * @return array{
     *     photos: array<int, array<string, mixed>>,
     *     omissions: array{
     *         total: int,
     *         checkTotal: int,
     *         limitTotal: int,
     *         title: string,
     *         checkLines: array<int, string>,
     *         limitLine: string|null
     *     }
     * }
     */
    private function selectPhotos(ModelProfile $profile, bool $external): array
    {
        $primaryId = $profile->photos->firstWhere('is_primary', true)?->id;

        $candidates = $profile->photos
            ->when($external, fn ($collection) => $collection->where('visibility', ModelPhoto::VISIBILITY_PUBLIC));

        // Fotos hinter dem Speicher-Limit waren nie Kandidaten: sie zählen
        // als Limit-Auslassung, nicht als gescheiterte Prüfung.
        $limitOmitted = max(0, $candidates->count() - self::MAX_PHOTOS);
        $photos = $candidates->take(self::MAX_PHOTOS)->values();

        $resolved = [];
        $reasonCounts = [];
        foreach ($photos as $photo) {
            $embedded = $this->embedPhoto($photo, $photo->id === $primaryId, $external);
            if ($embedded['photo'] !== null) {
                $resolved[] = $embedded['photo'];

                continue;
            }

            $reason = (string) $embedded['reason'];
            $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
        }

        // Hauptbild zuerst, damit die View es ohne erneute Suche rendern kann.
        usort($resolved, static fn (array $a, array $b): int => ($b['isPrimary'] <=> $a['isPrimary']));

        // Extern niemals Original-Dateinamen (PII) rendern: generische Captions
        // in Anzeige-Reihenfolge (Hauptbild = Foto 1).
        if ($external) {
            foreach (array_keys($resolved) as $index) {
                $resolved[$index]['caption'] = 'Foto '.($index + 1);
            }
        }

        return [
            'photos' => $resolved,
            'omissions' => $this->omissionNote($reasonCounts, $limitOmitted),
        ];
    }

    /**
     * Baut den sichtbaren Auslassungs-Hinweis (D-41).
     *
     * Der Hinweis nennt ausschließlich Anzahl und Grund — keine Dateinamen,
     * keine Kunden-Daten, keine Foto-Kennungen. Jedes ausgelassene Foto
     * erscheint genau einmal: eine gescheiterte Prüfung zählt in
     * `checkTotal` (mit einer Zeile je Grund in `checkLines`), das Foto-Limit
     * in `limitTotal` (mit eigener Zeile `limitLine`). Beide Kategorien
     * bleiben getrennt — die View rendert sie als getrennte Listenpunkte.
     *
     * @param  array<string, int>  $reasonCounts
     * @return array{
     *     total: int,
     *     checkTotal: int,
     *     limitTotal: int,
     *     title: string,
     *     checkLines: array<int, string>,
     *     limitLine: string|null
     * }
     */
    private function omissionNote(array $reasonCounts, int $limitOmitted): array
    {
        $checkLines = [];
        foreach (self::OMISSION_PRECEDENCE as $reason) {
            $count = $reasonCounts[$reason] ?? 0;
            if ($count > 0) {
                $checkLines[] = $this->omissionLine($count, self::OMISSION_LABELS[$reason]);
            }
        }

        $checkTotal = array_sum($reasonCounts);
        $limitLine = $limitOmitted > 0
            ? $this->omissionLine($limitOmitted, 'Foto-Limit (max. '.self::MAX_PHOTOS.')')
            : null;

        return [
            'total' => $checkTotal + $limitOmitted,
            'checkTotal' => $checkTotal,
            'limitTotal' => $limitOmitted,
            'title' => self::OMISSION_NOTE_TITLE,
            'checkLines' => $checkLines,
            'limitLine' => $limitLine,
        ];
    }

    private function omissionLine(int $count, string $label): string
    {
        return $count.' '.($count === 1 ? 'Foto' : 'Fotos').' ausgelassen: '.$label;
    }

    /**
     * Entschlüsselt ein Foto und baut eine Data-URI inkl. Anzeigemaßen.
     *
     * @return array{photo: array<string, mixed>|null, reason: string|null}
     */
    private function embedPhoto(ModelPhoto $photo, bool $isPrimary, bool $external): array
    {
        if (! $photo->path || ! $this->fileStore->exists($photo->path)) {
            return ['photo' => null, 'reason' => self::OMISSION_UNAVAILABLE];
        }

        $contents = $this->fileStore->getDecrypted($photo->path);
        if ($contents === '') {
            return ['photo' => null, 'reason' => self::OMISSION_UNAVAILABLE];
        }

        $inspection = $this->inspectRasterImage($contents, $photo->mime_type);
        if ($inspection['reasons'] !== []) {
            return ['photo' => null, 'reason' => $this->primaryReason($inspection['reasons'])];
        }

        $size = $inspection['size'];
        $dataUri = $this->imageDataUri($contents, $size['mime_type']);
        if ($dataUri === null) {
            return ['photo' => null, 'reason' => self::OMISSION_DECODE];
        }

        [$maxWidth, $maxHeight] = $isPrimary ? [520, 360] : [250, 190];
        [$width, $height] = $this->fit($size['width'], $size['height'], $maxWidth, $maxHeight);

        return [
            'photo' => [
                'dataUri' => $dataUri,
                // Intern bleibt der Originalname; extern wird die Caption in
                // `selectPhotos()` generisch ersetzt (kein PII-Leak über Dateinamen).
                'caption' => $external ? null : ($photo->original_name ?: basename($photo->path)),
                'visibility' => $photo->visibility,
                'isPrimary' => $isPrimary,
                'width' => $width,
                'height' => $height,
            ],
            'reason' => null,
        ];
    }

    /**
     * Bestimmt den primären Auslassungsgrund (D-41).
     *
     * Ein Foto kann mehrere Gründe haben (z. B. nicht parsebares Raster und
     * abweichende MIME). Es zählt genau einmal — unter dem ersten Grund aus
     * {@see self::OMISSION_PRECEDENCE}.
     *
     * @param  array<int, string>  $reasons
     */
    private function primaryReason(array $reasons): string
    {
        foreach (self::OMISSION_PRECEDENCE as $candidate) {
            if (in_array($candidate, $reasons, true)) {
                return $candidate;
            }
        }

        return $reasons[0] ?? self::OMISSION_UNAVAILABLE;
    }

    /**
     * Prüft die entschlüsselten Bytes, bevor ein Raster-Decoder sie sieht.
     *
     * Die Obergrenze ist {@see PhotoProcessingService::MAX_IMAGE_BYTES} — und
     * zwar, weil genau diese Grenze {@see ModelFileStore::storeUpload()} nach
     * dem GD-Re-Encode für das durchsetzt, was überhaupt persistiert werden
     * darf. Der Kontaktbogen spiegelt damit keine zweite Zahl (und schon gar
     * nicht das 10-MiB-Gate der **Quelldatei**), sondern liest dieselbe
     * Invariante: ein konform gespeichertes Foto kann hier nie am Byte-Cap
     * scheitern, auch wenn der Re-Encode die Upload-Datei legal aufbläht.
     *
     * Legacy: Dateien, die vor dieser Grenze gespeichert wurden, können
     * größer sein. Sie werden fail-closed ausgelassen (kein Fallback auf
     * ungeprüfte Bytes), nicht nachträglich verkleinert.
     *
     * Der **Byte-Cap steht vorn und bricht sofort ab** (Prüf-Reihenfolge wie
     * vor D-41): über-cap Bytes werden weder geparst noch von GD berührt und
     * tragen genau **einen** Grund — `byte`. Damit entfallen der Header-Parse
     * auf 20 MiB und der sekundäre `dimensions`-Grund, den das Budget
     * (`PhotoProcessingService::isWithinImageBudget()` prüft den Byte-Cap
     * ebenfalls) sonst zusätzlich liefern würde.
     *
     * Alle übrigen Gründe werden ermittelt, damit der Hinweis den primären
     * Grund nennen kann (D-41). Die Einbettungsentscheidung ändert sich
     * dadurch nicht: die Prüfung erfolgt weiterhin **vor** dem Decoder —
     * Bytes, die eine Prüfung nicht bestehen, erreichen GD nie.
     *
     * Die Methode ist `protected`, damit Tests den **Grundsatz** (nicht nur
     * dessen Darstellung im PDF) prüfen können: der Rückgabewert nennt alle
     * zutreffenden Gründe, der PDF-Contract zeigt davon nur den primären.
     *
     * @return array{size: array{width: int, height: int, mime_type: string}|null, reasons: array<int, string>}
     */
    protected function inspectRasterImage(string $contents, ?string $mime): array
    {
        $maxBytes = PhotoProcessingService::MAX_IMAGE_BYTES;
        $bytes = strlen($contents);

        // Erste Prüfung der Pipeline: der Byte-Cap bricht sofort ab — kein
        // Header-Parse auf über-cap Bytes, und genau ein Grund am Foto.
        if ($bytes < 1 || $bytes > $maxBytes) {
            return ['size' => null, 'reasons' => [self::OMISSION_BYTE]];
        }

        $reasons = [];

        $image = $this->readImageSize($contents);
        $imageMime = is_array($image) ? ($image['mime'] ?? null) : null;
        if (! is_string($imageMime)) {
            // Kein parsebares Raster: die Bytes sind weder JPEG/PNG/WebP noch
            // liefern sie Maße. Der Decoder wird dafür nicht aufgerufen.
            $reasons[] = self::OMISSION_DECODE;
        }

        $imageSize = is_array($image) ? $image : null;
        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if (! is_string($imageMime) || ! is_string($detectedMime)
            || $imageMime !== $detectedMime
            || ! in_array($detectedMime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || ($mime !== null && $detectedMime !== $mime)) {
            $reasons[] = self::OMISSION_MIME;
        }

        $width = 0;
        $height = 0;
        if (is_array($imageSize)) {
            $width = (int) ($imageSize[0] ?? 0);
            $height = (int) ($imageSize[1] ?? 0);
            // Der Byte-Cap ist bereits geprüft (siehe oben); der Budget-Check
            // entscheidet hier nur noch über Kantenlänge und Pixelzahl.
            if (! PhotoProcessingService::isWithinImageBudget($bytes, $width, $height, $maxBytes)) {
                $reasons[] = self::OMISSION_DIMENSIONS;
            }
        }

        if ($reasons !== []) {
            return ['size' => null, 'reasons' => $reasons];
        }

        return [
            'size' => ['width' => $width, 'height' => $height, 'mime_type' => (string) $detectedMime],
            'reasons' => [],
        ];
    }

    /**
     * Liest Maße und Typ aus den Bytes (Header-Parse, kein Decode).
     *
     * Als `protected`-Methode extrahiert, damit Tests zählen können, ob der
     * Header-Parse überhaupt stattfand: der Byte-Cap in
     * {@see self::inspectRasterImage()} bricht **vor** diesem Aufruf ab
     * (D-41/L-4) — über-cap Bytes werden nicht geparst.
     *
     * @return array<int|string, mixed>|false
     */
    protected function readImageSize(string $contents): array|false
    {
        return @getimagesizefromstring($contents);
    }

    /**
     * Baut eine Data-URI. WebP wird über GD nach JPEG normalisiert, damit
     * das PDF nicht an einem fehlenden DomPDF/Imagick-Codec scheitert.
     *
     * Gibt `null` zurück, wenn GD die Bytes nicht decodieren kann; der
     * Aufrufer lässt das Foto dann aus. Die Garantie ist „keine ungeprüften
     * Bytes": JPEG/PNG werden nach erfolgreichem Decode roh weitergereicht
     * (DomPDF erwartet den Original-Container), aber der Decode ist die
     * Bedingung dafür. Ein Fallback **ohne** Decode würde genau die Dateien
     * einbetten, die die Vorprüfung abweisen soll.
     */
    protected function imageDataUri(string $contents, ?string $mime): ?string
    {
        $mime = $mime ?: 'image/jpeg';

        if (! function_exists('imagecreatefromstring')
            || ($mime === 'image/webp' && ! function_exists('imagejpeg'))) {
            return null;
        }

        $image = $this->decodeRaster($contents);
        if ($image === false) {
            // Invalid stored images follow the existing omit-unusable-photo policy;
            // never pass bytes that GD could not decode through to DomPDF.
            return null;
        }

        if ($mime === 'image/webp') {
            ob_start();
            $ok = @imagejpeg($image, null, 88);
            $jpeg = (string) ob_get_clean();
            imagedestroy($image);

            return $ok && $jpeg !== '' ? 'data:image/jpeg;base64,'.base64_encode($jpeg) : null;
        }

        imagedestroy($image);

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    protected function decodeRaster(string $contents): \GdImage|false
    {
        return @imagecreatefromstring($contents);
    }

    /**
     * Skaliert auf eine Box, ohne Bilder zu vergrößern (ratio ≤ 1).
     *
     * @return array{0: int, 1: int}
     */
    private function fit(int $width, int $height, int $maxWidth, int $maxHeight): array
    {
        if ($width <= 0 || $height <= 0) {
            return [$maxWidth, $maxHeight];
        }

        $ratio = min($maxWidth / $width, $maxHeight / $height, 1.0);

        return [max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))];
    }

    private function experienceLabel(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return ModelQuestionnaire::EXPERIENCE_LABELS[$code] ?? $code;
    }

    private function willingnessLabel(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return ModelQuestionnaire::WILLINGNESS_LABELS[$code] ?? $code;
    }

    private function address(?Customer $customer): ?string
    {
        if ($customer === null) {
            return null;
        }

        $cityLine = trim(implode(' ', array_filter([(string) $customer->zip, (string) $customer->city], fn ($part) => trim($part) !== '')));

        $parts = array_filter([
            $customer->street,
            $cityLine,
            $customer->country,
        ], fn ($part) => is_string($part) && trim($part) !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @param  array<int, array{label: string, value: mixed}>  $facts
     * @return array<int, array{label: string, value: string}>
     */
    private function compactFacts(array $facts): array
    {
        $compact = [];
        foreach ($facts as $fact) {
            $value = $fact['value'];
            if ($value === null || $value === '') {
                continue;
            }

            $compact[] = ['label' => $fact['label'], 'value' => (string) $value];
        }

        return $compact;
    }

    private function brandConfig(ModelProfile $profile): BrandConfig
    {
        $brandId = $profile->brandValue() ?? BrandRegistry::currentId();

        return BrandRegistry::configForBrand($brandId) ?? BrandRegistry::configOrDefault();
    }

    /**
     * Brand-Logo aus der etablierten PDF-Asset-Quelle (`photos`-Disk,
     * `_watermarks/{prefix}watermark.svg`). Fehlt das Asset, rendert der
     * Header ohne Logo (kein externer Request).
     */
    private function logoDataUri(BrandConfig $brand): ?string
    {
        $disk = Storage::disk('photos');

        foreach (['_watermarks/'.$brand->id.'watermark.svg', '_watermarks/watermark.svg'] as $candidate) {
            $path = $disk->path($candidate);
            if (is_file($path)) {
                return 'data:image/svg+xml;base64,'.base64_encode((string) file_get_contents($path));
            }
        }

        return null;
    }
}
