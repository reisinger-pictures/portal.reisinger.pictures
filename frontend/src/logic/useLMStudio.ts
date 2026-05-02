import { useState, useEffect } from 'react';
import { getCompressedBase64 } from './utils/ImageHelper';
import { z } from 'zod';

export const lmStudioResponseSchema = z.object({
    title: z.string().optional(),
    description: z.string().optional(),
    // Erlaubt sowohl String als auch Array und konvertiert ein Array sicher in einen komma-separierten String
    keywords: z.union([z.string(), z.array(z.string())]).transform(v => Array.isArray(v) ? v.join(', ') : v).optional(),
    location: z.string().optional(),
    detected_city: z.string().optional()
});

export type LMStudioResponse = z.infer<typeof lmStudioResponseSchema>;

export function useLMStudio(enabled: boolean = true) {
    const [isAvailable, setIsAvailable] = useState(false);
    const [modelId, setModelId] = useState<string | null>(null);
    const [baseUrl, setBaseUrl] = useState(() => localStorage.getItem('lmstudio_url') || 'http://127.0.0.1:1234');

    useEffect(() => {
        if (!enabled) return;
        
        fetch(baseUrl + '/v1/models')
            .then(res => res.json())
            .then(data => {
                if (data?.data?.[0]?.id) {
                    setModelId(data.data[0].id);
                    setIsAvailable(true);
                } else {
                    setIsAvailable(false);
                    console.info(`ℹ️ LM Studio: Keine Modelle unter ${baseUrl} gefunden. KI-Features sind deaktiviert.`);
                }
            })
            .catch(() => {
                setIsAvailable(false);
                setModelId(null);
                console.info(`ℹ️ LM Studio: Unter ${baseUrl} nicht erreichbar. Lokale KI-Features sind deaktiviert.`);
            });
    }, [baseUrl, enabled]);

    const updateBaseUrl = (url: string) => {
        localStorage.setItem('lmstudio_url', url);
        setBaseUrl(url);
    };

    const generateMetadata = async (imageUrl: string, globalContext: string, specificContext: string, signal?: AbortSignal): Promise<LMStudioResponse> => {
        const base64DataUrl = await getCompressedBase64(imageUrl);

        const systemPrompt = "Du bist ein professioneller Senior-Bildredakteur für eine internationale Premium-Stockfoto-Agentur. Deine Aufgabe ist die präzise, objektive und maximal markttaugliche Verschlagwortung (Keywording) und Beschreibung von Bildern.\n\nREGELN FÜR METADATEN:\n1. TITEL: SEO-optimiert, prägnant, 70-150 Zeichen. Nenne Hauptmotiv und Setting direkt.\n2. BESCHREIBUNG: Beantworte journalistisch W-Fragen (Wer, was, wo, wann, warum) in 1-3 flüssigen Sätzen. Verwende NIEMALS Phrasen wie 'Das Bild zeigt' oder 'Man sieht'. Beschreibe direkt das Geschehen.\n3. KEYWORDS: Generiere exakt 20-30 Keywords. Mische literale Begriffe (Objekte, Personen, Kleidung, Farben, Architektur), Aktionen (z.B. 'laufen', 'arbeiten') und emotionale/abstrakte Konzepte (z.B. 'Freiheit', 'Teamwork', 'Zukunft'). Trenne strikt mit Komma.\n4. LOCATION: Identifiziere architektonische Merkmale, Point of Interests (POI) oder Landmarken so präzise wie möglich. Halluziniere niemals Eigennamen von Personen oder Orten, wenn sie nicht aus dem Bild oder Kontext ableitbar sind!\n5. FORMAT: Antworte AUSSCHLIESSLICH im validen JSON-Format ohne Markdown-Wrapper.";

        const userPrompt = "Analysiere das beigefügte Bild unter Berücksichtigung der folgenden Hintergrundinformationen:\n- Globaler Kontext: " + (globalContext || 'Keiner') + "\n- Spezifischer Bild-Kontext: " + (specificContext || 'Keiner') + "\n\nExtrahiere die Metadaten. Fülle die Werte auf Deutsch aus und nutze exakt folgendes JSON-Schema:\n{\n  \"title\": \"<Aussagekräftiger Titel>\",\n  \"description\": \"<Detaillierte Beschreibung>\",\n  \"keywords\": \"<20-30 Keywords, kommagetrennt>\",\n  \"location\": \"<Spezifischer Ort, Gebäude, Bezirk, Landmarke. Leer lassen falls absolut unbekannt>\",\n  \"detected_city\": \"<Name der Stadt, falls aus dem Bild oder Kontexten eindeutig ableitbar>\"\n}";

        try {
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
                signal: signal
            });

            if (!res.ok) throw new Error('LM Studio API Error');
            const data = await res.json();
            const text = data.choices?.[0]?.message?.content || '{}';
            
            // Regex to clean potential markdown blocks from AI output
            const cleanText = text.replace(/```json/g, '').replace(/```/g, '').trim();
            
            const parsed = JSON.parse(cleanText);
            const validationResult = lmStudioResponseSchema.safeParse(parsed);
            
            if (!validationResult.success) {
                console.error("Zod Validation Error:", validationResult.error);
                throw new Error("KI Output entspricht nicht dem erwarteten Format.");
            }
            
            return validationResult.data;
        } catch (e: unknown) {
            console.error("Fehler bei der KI Generierung oder JSON Parsing:", e);
            throw e;
        }
    };

    return { isAvailable, modelId, baseUrl, updateBaseUrl, generateMetadata };
}
