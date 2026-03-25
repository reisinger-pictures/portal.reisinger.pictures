---
domain: infrastructure
topic: email-system
status: active
---

# Technical Concept: Email System & Notifications

## 1. Hardcoded Templates
- To simplify the architecture and deployment, email templates are **hardcoded** as Laravel Blade views (`resources/views/emails/`).
- The previous database-driven `EmailTemplate` model has been removed.


## 2. Custom Messages
- For manual triggers (e.g., the "Send Email" action in the gallery view), the frontend provides a text area. 
- This custom text is injected into a standardized, branded HTML Blade layout to ensure a consistent corporate identity.
- - Emails should be previewed in the browser before sending.

## 3. Local Testing
- The `Mailpit` container catches all outgoing emails during local development.
- **Testing:** Playwright and archunit tests MUST query the Mailpit API to extract tokens and verify email delivery instead of mocking the mailer.
