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

        const systemPrompt = "Du bist ein professioneller Bildredakteur und Indexierer für eine internationale Premium-Stockfoto-Agentur. Deine Aufgabe ist es, Bilder für den kommerziellen und redaktionellen Verkauf optimal mit Metadaten auszustatten.\n\nDeine Regeln:\n1. Formuliere präzise, objektiv und werblich/redaktionell nutzbar.\n2. Verwende NIEMALS Phrasen wie 'Das Bild zeigt', 'Man sieht', 'Hier ist'. Beschreibe direkt das Motiv.\n3. Der Titel ist SEO-optimiert, auf den Punkt gebracht und zwischen 70 und 150 Zeichen lang.\n4. Die Beschreibung beantwortet Wer, Was, Wo und Warum in 1-3 flüssigen Sätzen.\n5. Nenne 15 bis 25 Keywords (Mischung aus sichtbaren Objekten, Aktionen und abstrakten/emotionalen Konzepten wie z.B. 'Zusammenhalt', 'Freiheit').\n6. Antworte AUSSCHLIESSLICH im reinen JSON-Format. Nutze keine Markdown-Blöcke und schreibe keinen Text vor oder nach dem JSON.";

        const userPrompt = "Analysiere das beigefügte Bild unter Berücksichtigung der folgenden Hintergrundinformationen des Fotografen:\nGlobaler Kontext: " + (globalContext || 'Keiner') + "\nSpezifischer Kontext: " + (specificContext || 'Keiner') + "\n\nExtrahiere die Metadaten und gib exakt dieses JSON-Schema zurück. Fülle die Werte auf Deutsch aus:\n{\n  \"title\": \"<SEO-optimierter, aussagekräftiger Titel>\",\n  \"description\": \"<Detaillierte journalistische oder werbliche Beschreibung>\",\n  \"keywords\": \"<15-25 Keywords, strikt kommagetrennt>\",\n  \"location\": \"<Spezifischer Ort, Point of Interest, Landmarke, Gebäude oder Stadtteil. Leer lassen, falls unbekannt>\",\n  \"detected_city\": \"<Name der Stadt, falls aus dem Bild oder den Kontexten eindeutig ableitbar>\"\n}";

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
