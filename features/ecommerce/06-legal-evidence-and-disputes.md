---
domain: ecommerce
topic: legal-evidence-and-disputes
status: active
---

# Technical Concept: Legal Evidence & Dispute Handling

## 1. The "Evidence Package" (Stripe Disputes)
When a customer purchases digital goods, they must explicitly waive their right of withdrawal (\`withdrawal_waived\` flag). 
If a customer initiates a chargeback (Dispute) via Stripe, the photographer must provide evidence that the digital goods were delivered.

**The Evidence Package consists of:**
1. **The Invoice (PDF):** Generated automatically and stored immutably in \`invoice_snapshots\`. Contains the IP address of the buyer at the time of checkout.
2. **The Audit Log:** The \`download_logs\` table tracks exactly when the specific user (or their guest session) downloaded the files (single images or ZIPs).
3. **The Webhook Confirmation:** The \`Order\` status transitioning from \`pending_payment\` to \`paid\`, triggered by Stripe's \`payment_intent.succeeded\`.

## 2. Access Control During Disputes
When a Stripe webhook triggers \`charge.dispute.created\`, the system reacts immediately to mitigate further damages:
- **Lockout:** The order status is changed to \`disputed\`.
- **Download Prevention:** The \`DownloadController\` actively checks the order status. If the status is \`disputed\`, \`refunded\`, or \`cancelled\`, all ZIP and high-res single-image downloads associated with this order are blocked (HTTP 403 Forbidden).
- **Notification:** The internal accounting team is notified via email about the dispute so they can manually gather the Evidence Package and upload it to the Stripe Dashboard.

## 3. Rücktrittsrechts-Compliance (übernommener SOLL-Zustand)

Der Shop verkauft ausschließlich digitale Inhalte; Kaufverträge erfordern daher eine ausdrücklich erklärte und protokollierte Zustimmung zum Widerrufsverzicht. Gesetzesquelle ist durchgängig **§ 18 Abs. 1 Z 11 FAGG** (Fern- und Auswärtsgeschäfte-Gesetz, digitale Inhalte).

**1. Widerrufsverzicht-Zustimmung (Checkbox):** Pflichtfeld \`withdrawal_waived\` im Checkout — eine eigene, nicht vorausgewählte Checkbox direkt über dem Kaufen-Button. Der Text nennt ausdrücklich den sofortigen Download sowie das Erlöschen des Rücktritts- bzw. Widerrufsrechts. Die Checkbox wird nur bei echten Käufen gerendert; Angebots-Requests (Quote-Requests) sind ausgenommen. Server-seitig erzwungen: \`CheckoutService\` antwortet mit HTTP 422, wenn \`! $isQuoteRequest && ! $request->boolean('withdrawal_waived')\`. Die Prüfung erfolgt vor der Token-Verarbeitung und gilt damit auch für den \`quote_token\`-Flow (Offer-Kauf).

**2. Protokollierung (Beweislast):** Die Zustimmung wird zweifach persistiert:
- auf der Order: \`orders.withdrawal_waived\` (boolean) + \`orders.withdrawal_consent_at\` (Timestamp),
- unveränderlich im Invoice-Snapshot: \`customer_details.withdrawal_consent\` mit \`waived\`, \`at\` (ISO-8601) und \`text\` (wörtlicher Zustimmungstext mit §-18-Abs-1-Z-11-FAGG-Referenz).

**3. Bestätigung auf dauerhaftem Datenträger:** Bei gesetzter Zustimmung enthalten die Kaufmail (\`InvoiceMail\`) sowie das Rechnungs-PDF einen Absatz, der die Zustimmung samt Erlöschen des Rücktrittsrechts im Wortlaut bestätigt — inklusive Zustimmungs-Zeitstempel („… (erteilt am …)“ bzw. „Ihre Zustimmung wurde am … erteilt.“). Damit ist die Bestätigungs-Pflicht auf dauerhaftem Datenträger erfüllt. Ohne Zustimmung wird dieser Absatz nicht ausgegeben.

**4. Rechtstexte:** Die AGB/Lizenzbedingungen (Frontend-Route \`/license-terms\`) enthalten die digitale-Inhalte-Klausel (§ 18 Abs. 1 Z 11 FAGG) und verweisen auf die Widerrufsbelehrung; die Frontend-Route \`/widerruf\` ist die Widerrufsbelehrung mit dem Erlöschen-Absatz.

**5. Mischkorb-Entscheidung:** Der Shop verkauft ausschließlich digitale Produkte (keine physischen Waren); damit entfällt die § 13a-FAGG-Rücktrittsbutton-Pflicht für physische Artikel. Sollte künftig physischer Versand dazukommen, wird der Rücktrittsbutton für diese Artikel wieder erforderlich.

## Related
- [Licensing and downloads](02-licensing-and-downloads.md) — download_logs / invoice_snapshots origin
- [Stripe checkout flow](09-stripe-checkout-flow.md) — server-side checkout enforcement and payment confirmation
