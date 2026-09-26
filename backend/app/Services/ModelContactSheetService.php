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
 *
 * Labels/Codes kommen ausschließlich aus {@see ModelQuestionnaire} — die
 * Farb-/Label-Logik wird nicht dupliziert, sondern über die dort zentral
 * definierten `EXPERIENCE_LABELS`/`WILLINGNESS_LABELS`/Kategorien aufgelöst.
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

        $data = array_merge([
            'variant' => $variant,
            'isExternal' => $external,
            'generatedAt' => now(),
            'brandName' => $brand->name,
            'primaryColor' => $brand->primaryColor,
            'secondaryColor' => $brand->secondaryColor,
            'logoDataUri' => $this->logoDataUri($brand),
            'watermarkText' => $external ? $brand->name : null,
            'photos' => $this->photos($profile, $external),
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
     * @return array<int, array<string, mixed>>
     */
    private function photos(ModelProfile $profile, bool $external): array
    {
        $primaryId = $profile->photos->firstWhere('is_primary', true)?->id;

        $photos = $profile->photos
            ->when($external, fn ($collection) => $collection->where('visibility', ModelPhoto::VISIBILITY_PUBLIC))
            ->take(self::MAX_PHOTOS)
            ->values();

        $resolved = [];
        foreach ($photos as $photo) {
            $embedded = $this->embedPhoto($photo, $photo->id === $primaryId, $external);
            if ($embedded !== null) {
                $resolved[] = $embedded;
            }
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

        return $resolved;
    }

    /**
     * Entschlüsselt ein Foto und baut eine Data-URI inkl. Anzeigemaßen.
     *
     * @return array<string, mixed>|null
     */
    private function embedPhoto(ModelPhoto $photo, bool $isPrimary, bool $external): ?array
    {
        if (! $photo->path || ! $this->fileStore->exists($photo->path)) {
            return null;
        }

        $contents = $this->fileStore->getDecrypted($photo->path);
        if ($contents === '') {
            return null;
        }

        $dataUri = $this->imageDataUri($contents, $photo->mime_type);
        if ($dataUri === null) {
            return null;
        }

        $size = @getimagesizefromstring($contents) ?: null;
        [$maxWidth, $maxHeight] = $isPrimary ? [520, 360] : [250, 190];
        [$width, $height] = $this->fit((int) ($size[0] ?? 0), (int) ($size[1] ?? 0), $maxWidth, $maxHeight);

        return [
            'dataUri' => $dataUri,
            // Intern bleibt der Originalname; extern wird die Caption in
            // `photos()` generisch ersetzt (kein PII-Leak über Dateinamen).
            'caption' => $external ? null : ($photo->original_name ?: basename($photo->path)),
            'visibility' => $photo->visibility,
            'isPrimary' => $isPrimary,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Baut eine Data-URI. WebP wird über GD nach JPEG normalisiert, damit
     * das PDF nicht an einem fehlenden DomPDF/Imagick-Codec scheitert.
     */
    private function imageDataUri(string $contents, ?string $mime): ?string
    {
        $mime = $mime ?: 'image/jpeg';

        if ($mime === 'image/webp' && function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $image = @imagecreatefromstring($contents);
            if ($image !== false) {
                ob_start();
                $ok = imagejpeg($image, null, 88);
                $jpeg = (string) ob_get_clean();
                imagedestroy($image);

                if ($ok && $jpeg !== '') {
                    return 'data:image/jpeg;base64,'.base64_encode($jpeg);
                }
            }
        }

        return 'data:'.$mime.';base64,'.base64_encode($contents);
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
