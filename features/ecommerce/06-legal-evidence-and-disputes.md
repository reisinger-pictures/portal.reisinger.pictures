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

## Related
- [Licensing, Pricing & Cart](../ecommerce/01-licensing-and-cart.md) — invoice_snapshots origin and withdrawal waiver during checkout
