---
domain: infrastructure
topic: watermark-refactoring
status: active
---

# Technical Concept: Watermark Refactoring (Automated Startup Detection)

## 1. Architectural Shift (No Uploads, Pure Opacity Management)
- **Single Source of Truth:** Die SVG-Logos der Brands liegen fest im Frontend Repository (`/public/brands/.../safari-pinned-tab.svg`). Ein Dateiupload über das Admin-Dashboard ist nicht mehr möglich.
- **Opacity Control:** Der Admin steuert über das Dashboard ausschließlich den Regler für die Deckkraft (`opacity`).

## 2. Server Startup & Dynamic Auto-Detection
- **Auto-Regeneration on Boot:** Das Backend überwacht die MD5-Hashes der Quell-SVGs im Frontend-Verzeichnis. Wenn der Server neu startet oder die Einstellungsseite aufgerufen wird und eine Änderung am SVG-Inhalt detektiert wird, werden die PNG-Auflösungs-Buckets (`500.png`, `1000.png`, `2000.png`) vollautomatisch im RAM neu gerastert und im persistenten Medien-Volume abgelegt.
- **Busting Stale Caches:** Nach jeder automatischen Regeneration werden alle gecachten Derivate in den Galerie-Unterordnern (`/_watermarked`) invalidiert.