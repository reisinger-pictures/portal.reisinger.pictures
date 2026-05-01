import { useState, useEffect } from 'react';

export interface LMStudioResponse {
    title?: string;
    description?: string;
    keywords?: string;
    detected_city?: string;
}

export function useLMStudio() {
    const [isAvailable, setIsAvailable] = useState(false);
    const [modelId, setModelId] = useState<string | null>(null);

    useEffect(() => {
        fetch('http://127.0.0.1:1234/v1/models')
            .then(res => res.json())
            .then(data => {
                if (data?.data?.[0]?.id) {
                    setModelId(data.data[0].id);
                    setIsAvailable(true);
                }
            })
            .catch(() => {
                setIsAvailable(false);
            });
    }, []);

    const getCompressedBase64 = async (imageUrl: string, maxSide = 2048): Promise<string> => {
        const res = await fetch(imageUrl);
        const blob = await res.blob();
        const objectUrl = URL.createObjectURL(blob);
        
        const img = await new Promise<HTMLImageElement>((resolve, reject) => {
            const image = new Image();
            image.onload = () => { URL.revokeObjectURL(objectUrl); resolve(image); };
            image.onerror = (err) => { URL.revokeObjectURL(objectUrl); reject(err); };
            image.src = objectUrl;
        });

        let { width, height } = img;
        if (width > maxSide || height > maxSide) {
            if (width > height) {
                height = Math.round((height * maxSide) / width);
                width = maxSide;
            } else {
                width = Math.round((width * maxSide) / height);
                height = maxSide;
            }
        }

        const canvas = document.createElement("canvas");
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext("2d");
        ctx?.drawImage(img, 0, 0, canvas.width, canvas.height);
        return canvas.toDataURL("image/jpeg", 0.8);
    };

    const generateMetadata = async (imageUrl: string, globalContext: string, specificContext: string): Promise<LMStudioResponse> => {
        const base64DataUrl = await getCompressedBase64(imageUrl);

        const prompt = `Du bist ein KI-Assistent für SEO-Bildbeschreibungen und Foto-Metadaten. Analysiere das Bild.
Globaler Kontext (für alle Bilder relevant): ${globalContext || 'Keiner'}
Spezifischer Kontext (nur für dieses Bild): ${specificContext || 'Keiner'}

Antworte AUSSCHLIESSLICH mit einem validen JSON-Objekt ohne Markdown-Formatierung:
{
    "title": "Kurzer, prägnanter Titel (max 50 Zeichen)",
    "description": "Detaillierte Bildbeschreibung (1-2 Sätze). Keine Einleitungen wie 'Hier ist'.",
    "keywords": "3-8 relevante Schlagwörter, kommagetrennt",
    "detected_city": "Name der Stadt (nur wenn aufgrund von Landmarken/Kontext eindeutig erkennbar, sonst leer)"
}`;

        const res = await fetch('http://127.0.0.1:1234/v1/chat/completions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model: modelId,
                messages: [
                    { role: 'system', content: 'Du bist ein nützlicher Assistent, der ausschließlich JSON ausgibt.' },
                    { role: 'user', content: [
                        { type: 'text', text: prompt },
                        { type: 'image_url', image_url: { url: base64DataUrl } }
                    ]}
                ],
                temperature: 0.2
            })
        });

        if (!res.ok) throw new Error('LM Studio API Error');
        const data = await res.json();
        const text = data.choices?.[0]?.message?.content || '{}';
        const cleanText = text.replace(/\x60\x60\x60json/g, '').replace(/\x60\x60\x60/g, '').trim();
        return JSON.parse(cleanText);
    };

    return { isAvailable, modelId, generateMetadata };
}