import { useState, useEffect } from 'react';
import { getCompressedBase64 } from './utils/ImageHelper';
import { z } from 'zod';

export const aiResponseSchema = z.object({
    title: z.string().optional(),
    description: z.string().optional(),
    keywords: z.union([z.string(), z.array(z.string())]).transform(v => Array.isArray(v) ? v.join(', ') : v).optional(),
    location: z.string().optional(),
    detected_city: z.string().optional()
});

export type AIResponse = z.infer<typeof aiResponseSchema>;

const lmStudioUrlSchema = z.string().refine(val => {
    try {
        const url = new URL(val);
        return (
            url.protocol === 'http:' &&
            (url.hostname === '127.0.0.1' || url.hostname === 'localhost') &&
            url.port !== ''
        );
    } catch {
        return false;
    }
}, 'LM Studio URL must be http://127.0.0.1:PORT or http://localhost:PORT');

function getLmStudioUrl(): string {
    const raw = localStorage.getItem('lmstudio_url') || import.meta.env.VITE_LMSTUDIO_URL || 'http://127.0.0.1:1234';
    const result = lmStudioUrlSchema.safeParse(raw);
    if (!result.success) {
        console.warn('Invalid LM Studio URL configured, falling back to default', result.error);
        return 'http://127.0.0.1:1234';
    }
    return result.data;
}

type AIMode = 'server' | 'local' | 'unavailable';

export function useAI() {
    const [mode, setMode] = useState<AIMode>('unavailable');
    const [modelId, setModelId] = useState<string | null>(null);
    const [isAvailable, setIsAvailable] = useState(false);

    useEffect(() => {
        let cancelled = false;

        async function checkAvailability() {
            try {
                const res = await fetch('/api/ai/status', { credentials: 'include' });
                if (!res.ok) throw new Error('Server AI unavailable');
                const data = await res.json();
                if (!cancelled) {
                    if (data.status === 'disabled') {
                        setIsAvailable(false);
                        setMode('unavailable');
                        setModelId(null);
                        return;
                    }
                    if (data.enabled) {
                        setIsAvailable(true);
                        setMode('server');
                        setModelId(data.model);
                        return;
                    }
                }
            } catch (err) { console.error('AI metadata generation failed', err); }

            const localUrl = getLmStudioUrl();
            try {
                const res = await fetch(localUrl + '/v1/models');
                const data = await res.json();
                if (!cancelled && data?.data?.[0]?.id) {
                    setIsAvailable(true);
                    setMode('local');
                    setModelId(data.data[0].id);
                    return;
                }
            } catch (err) { console.error('AI text-based metadata generation failed', err); }

            if (!cancelled) {
                setIsAvailable(false);
                setMode('unavailable');
                setModelId(null);
            }
        }

        checkAvailability();
        return () => { cancelled = true; };
    }, []);

    const generateMetadata = async (
        photoId: string,
        globalContext: string,
        specificContext: string,
        signal?: AbortSignal
    ): Promise<AIResponse> => {
        if (mode === 'server') {
            const res = await fetch('/api/ai/generate-metadata', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ photo_id: photoId, global_context: globalContext, specific_context: specificContext }),
                credentials: 'include',
                signal
            });
            if (!res.ok) {
                const err = await res.json().catch(() => ({}));
                throw new Error(err.error || `AI API Error: ${res.status}`);
            }
            const data = await res.json();
            const validationResult = aiResponseSchema.safeParse(data);
            if (!validationResult.success) {
                throw new Error('AI response validation failed');
            }
            return validationResult.data;
        }

        const baseUrl = getLmStudioUrl();
        const photoResponse = await fetch(`/api/photos/${photoId}/context`, { credentials: 'include' });
        if (!photoResponse.ok) throw new Error('Could not fetch photo data');
        const photoData = await photoResponse.json();
        const imageUrl = photoData.photo?.url;

        if (!imageUrl) throw new Error('Photo URL not found');
        const base64DataUrl = await getCompressedBase64(imageUrl);

        const systemPrompt = "Du bist ein professioneller Senior-Bildredakteur für eine internationale Premium-Stockfoto-Agentur. Deine Aufgabe ist die präzise, objektive und maximal markttaugliche Verschlagwortung (Keywording) und Beschreibung von Bildern.\n\nREGELN FÜR METADATEN:\n1. TITEL: SEO-optimiert, prägnant, 70-150 Zeichen. Nenne Hauptmotiv und Setting direkt.\n2. BESCHREIBUNG: Beantworte journalistisch W-Fragen (Wer, was, wo, wann, warum) in 1-3 flüssigen Sätzen. Verwende NIEMALS Phrasen wie 'Das Bild zeigt' oder 'Man sieht'. Beschreibe direkt das Geschehen.\n3. KEYWORDS: Generiere exakt 20-30 Keywords. Mische literale Begriffe (Objekte, Personen, Kleidung, Farben, Architektur), Aktionen (z.B. 'laufen', 'arbeiten') und emotionale/abstrakte Konzepte (z.B. 'Freiheit', 'Teamwork', 'Zukunft'). Trenne strikt mit Komma.\n4. LOCATION: Identifiziere architektonische Merkmale, Point of Interests (POI) oder Landmarken so präzise wie möglich. Halluziniere niemals Eigennamen von Personen oder Orten, wenn sie nicht aus dem Bild oder Kontext ableitbar sind!\n5. FORMAT: Antworte AUSSCHLIESSLICH im validen JSON-Format ohne Markdown-Wrapper.";

        const userPrompt = "Analysiere das beigefügte Bild unter Berücksichtigung der folgenden Hintergrundinformationen:\n- Globaler Kontext: " + (globalContext || 'Keiner') + "\n- Spezifischer Bild-Kontext: " + (specificContext || 'Keiner') + "\n\nExtrahiere die Metadaten. Fülle die Werte auf Deutsch aus und nutze exakt folgendes JSON-Schema:\n{\n  \"title\": \"<Aussagekräftiger Titel>\",\n  \"description\": \"<Detaillierte Beschreibung>\",\n  \"keywords\": \"<20-30 Keywords, kommagetrennt>\",\n  \"location\": \"<Spezifischer Ort, Gebäude, Bezirk, Landmarke. Leer lassen falls absolut unbekannt>\",\n  \"detected_city\": \"<Name der Stadt, falls aus dem Bild oder Kontexten eindeutig ableitbar>\"\n}";

        const res = await fetch(baseUrl + '/v1/chat/completions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model: modelId,
                response_format: { type: "json_object" },
                messages: [
                    { role: 'system', content: systemPrompt },
                    { role: 'user', content: [
                        { type: 'text', text: userPrompt },
                        { type: 'image_url', image_url: { url: base64DataUrl } }
                    ]}
                ],
                temperature: 0.2
            }),
            signal
        });

        if (!res.ok) throw new Error('LM Studio API Error');
        const data = await res.json();
        const text = data.choices?.[0]?.message?.content || '{}';
        const cleanText = text.replace(/```json/g, '').replace(/```/g, '').trim();
        const parsed = JSON.parse(cleanText);
        const validationResult = aiResponseSchema.safeParse(parsed);
        if (!validationResult.success) {
            throw new Error('AI output does not match expected format');
        }
        return validationResult.data;
    };

    const generateMetadataFromText = async (
        textInput: string,
        globalContext: string = ''
    ): Promise<AIResponse> => {
        const res = await fetch('/api/ai/generate-metadata-text', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text_input: textInput, global_context: globalContext }),
            credentials: 'include',
        });
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            throw new Error(err.error || `AI Text API Error: ${res.status}`);
        }
        const data = await res.json();
        const validationResult = aiResponseSchema.safeParse(data);
        if (!validationResult.success) {
            throw new Error('AI text response validation failed');
        }
        return validationResult.data;
    };

    const updateBaseUrl = (url: string) => {
        const result = lmStudioUrlSchema.safeParse(url);
        if (result.success) {
            localStorage.setItem('lmstudio_url', url);
        } else {
            console.warn('Attempted to save invalid LM Studio URL', result.error);
        }
    };

    return { isAvailable, mode, modelId, generateMetadata, generateMetadataFromText, updateBaseUrl };
}
