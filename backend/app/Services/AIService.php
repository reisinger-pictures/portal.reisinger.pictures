<?php
namespace App\Services;

use App\Models\Photo;
use App\Services\AIProviderFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIService
{
    public function isDisabled(): bool
    {
        return !config('services.ai.enabled');
    }

    public function isUnconfigured(): bool
    {
        if ($this->isDisabled()) {
            return false;
        }
        return empty(config('services.ai.api_key'));
    }

    public function isAvailable(): bool
    {
        if ($this->isDisabled()) {
            return false;
        }
        if (config('services.ai.type') === 'lmstudio') {
            return true;
        }
        return !empty(config('services.ai.api_key'));
    }

    public function generateMetadata(Photo $photo, string $globalContext = '', ?string $specificContext = null): array
    {
        $imageData = $this->loadAndCompressImage($photo);

        $systemPrompt = "Du bist ein professioneller Senior-Bildredakteur für eine internationale Premium-Stockfoto-Agentur. Deine Aufgabe ist die präzise, objektive und maximal markttaugliche Verschlagwortung (Keywording) und Beschreibung von Bildern.\n\nREGELN FÜR METADATEN:\n1. TITEL: SEO-optimiert, prägnant, 70-150 Zeichen. Nenne Hauptmotiv und Setting direkt.\n2. BESCHREIBUNG: Beantworte journalistisch W-Fragen (Wer, was, wo, wann, warum) in 1-3 flüssigen Sätzen. Verwende NIEMALS Phrasen wie 'Das Bild zeigt' oder 'Man sieht'. Beschreibe direkt das Geschehen.\n3. KEYWORDS: Generiere exakt 20-30 Keywords. Mische literale Begriffe (Objekte, Personen, Kleidung, Farben, Architektur), Aktionen (z.B. 'laufen', 'arbeiten') und emotionale/abstrakte Konzepte (z.B. 'Freiheit', 'Teamwork', 'Zukunft'). Trenne strikt mit Komma.\n4. LOCATION: Identifiziere architektonische Merkmale, Point of Interests (POI) oder Landmarken so präzise wie möglich. Halluziniere niemals Eigennamen von Personen oder Orten, wenn sie nicht aus dem Bild oder Kontext ableitbar sind!\n5. FORMAT: Antworte AUSSCHLIESSLICH im validen JSON-Format ohne Markdown-Wrapper.";

        $userPrompt = "Analysiere das beigefügte Bild unter Berücksichtigung der folgenden Hintergrundinformationen:\n- Globaler Kontext: " . ($globalContext ?: 'Keiner') . "\n- Spezifischer Bild-Kontext: " . ($specificContext ?: 'Keiner') . "\n\nExtrahiere die Metadaten. Fülle die Werte auf Deutsch aus und nutze exakt folgendes JSON-Schema:\n{\n  \"title\": \"<Aussagekräftiger Titel>\",\n  \"description\": \"<Detaillierte Beschreibung>\",\n  \"keywords\": \"<20-30 Keywords, kommagetrennt>\",\n  \"location\": \"<Spezifischer Ort, Gebäude, Bezirk, Landmarke. Leer lassen falls absolut unbekannt>\",\n  \"detected_city\": \"<Name der Stadt, falls aus dem Bild oder Kontexten eindeutig ableitbar>\"\n}";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $userPrompt],
                ['type' => 'image_url', 'image_url' => ['url' => $imageData]]
            ]]
        ];

        return $this->callAI($messages);
    }

    public function generateMetadataFromText(string $textInput, string $globalContext = ''): array
    {
        $systemPrompt = "Du bist ein professioneller Senior-Bildredakteur für eine internationale Premium-Stockfoto-Agentur. Deine Aufgabe ist die präzise, objektive und maximal markttaugliche Verschlagwortung (Keywording) und Beschreibung von Bildern basierend auf einer Textbeschreibung.\n\nREGELN FÜR METADATEN:\n1. TITEL: SEO-optimiert, prägnant, 70-150 Zeichen.\n2. BESCHREIBUNG: Beantworte journalistisch W-Fragen in 1-3 flüssigen Sätzen.\n3. KEYWORDS: Generiere exakt 20-30 Keywords. Trenne strikt mit Komma.\n4. LOCATION: Basierend auf der Beschreibung.\n5. FORMAT: Antworte AUSSCHLIESSLICH im validen JSON-Format ohne Markdown-Wrapper.";

        $userPrompt = "Basierend auf der folgenden Beschreibung eines Bildes, generiere passende Metadaten:\n\nBeschreibung: " . $textInput . "\n\nGlobaler Kontext: " . ($globalContext ?: 'Keiner') . "\n\nJSON-Schema:\n{\n  \"title\": \"<Aussagekräftiger Titel>\",\n  \"description\": \"<Detaillierte Beschreibung>\",\n  \"keywords\": \"<20-30 Keywords, kommagetrennt>\",\n  \"location\": \"<Spezifischer Ort>\"\n}";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ];

        return $this->callAI($messages);
    }

    private function callAI(array $messages): array
    {
        $provider = app(AIProviderFactory::class)->make();

        $requestBody = $provider->buildRequest(config('services.ai.model'), $messages);
        $requestBody['temperature'] = 0.2;
        $requestBody['max_tokens'] = 2000;

        if ($provider->supportsJsonMode()) {
            $requestBody['response_format'] = ['type' => 'json_object'];
        }

        $response = Http::withHeaders($provider->buildHeaders())
            ->timeout(120)
            ->post(rtrim(config('services.ai.base_url'), '/') . $provider->getEndpoint(), $requestBody);

        if (!$response->successful()) {
            Log::error('AI API call failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('AI API Fehler: ' . $response->status());
        }

        $data = $response->json();
        $content = $provider->parseResponse($data);

        $cleanContent = preg_replace('/```(?:json)?\n?/', '', $content ?? '');
        $cleanContent = trim($cleanContent);

        $parsed = json_decode($cleanContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('AI response is not valid JSON: ' . json_last_error_msg());
        }

        return [
            'title' => $parsed['title'] ?? '',
            'description' => $parsed['description'] ?? '',
            'keywords' => $parsed['keywords'] ?? '',
            'location' => $parsed['location'] ?? '',
            'detected_city' => $parsed['detected_city'] ?? '',
        ];
    }

    private function loadAndCompressImage(Photo $photo): string
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('photos');
        $path = $photo->gallery_id . '/' . $photo->filename;

        if (!$disk->exists($path)) {
            throw new \RuntimeException('Image file not found on disk');
        }

        $stream = $disk->readStream($path);
        if ($stream === false) {
            throw new \RuntimeException('Failed to open image stream for AI processing');
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'ai_img_');
        $tmpHandle = fopen($tmpPath, 'wb');
        if ($tmpHandle === false) {
            fclose($stream);
            throw new \RuntimeException('Failed to create temp file for AI image processing');
        }
        stream_copy_to_stream($stream, $tmpHandle);
        fclose($stream);
        fclose($tmpHandle);

        $image = @imagecreatefromstring(file_get_contents($tmpPath));
        unlink($tmpPath);

        if (!$image) {
            throw new \RuntimeException('Failed to decode image for AI processing');
        }

        $origWidth = imagesx($image);
        $origHeight = imagesy($image);
        $maxDim = 2048;

        if ($origWidth > $maxDim || $origHeight > $maxDim) {
            $ratio = min($maxDim / $origWidth, $maxDim / $origHeight);
            $newWidth = (int)round($origWidth * $ratio);
            $newHeight = (int)round($origHeight * $ratio);
            $resized = imagescale($image, $newWidth, $newHeight, IMG_BILINEAR_FIXED);
            if ($resized !== false) {
                imagedestroy($image);
                $image = $resized;
            }
        }

        ob_start();
        imagejpeg($image, null, 80);
        $compressed = ob_get_clean();
        imagedestroy($image);

        $base64 = base64_encode($compressed);
        return 'data:image/jpeg;base64,' . $base64;
    }
}
