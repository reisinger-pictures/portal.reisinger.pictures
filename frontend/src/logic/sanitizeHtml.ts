import DOMPurify from 'dompurify';

/**
 * Allowed HTML elements for rich-text content rendered in the frontend.
 *
 * This allow-list mirrors the backend Symfony HtmlSanitizer configuration
 * (see AppServiceProvider html-sanitizer config) so that persist-time
 * and render-time filtering stay consistent. Defense-in-depth: even when
 * the backend already sanitizes a field (e.g. text-snippet content_html),
 * we sanitize again on the client to guard against any persist-path bypass
 * or transport tampering. For fields without backend sanitization
 * (e.g. contract terms_html), this is the only XSS defense.
 */
const ALLOWED_TAGS = [
    'p', 'h1', 'h2', 'h3', 'h4', 'strong', 'em', 'u', 's', 'ul', 'ol', 'li', 'br',
    'a', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
];

const ALLOWED_ATTR = ['href', 'target', 'rel', 'title'];

/**
 * Sanitize untrusted HTML before injecting it into the DOM via
 * dangerouslySetInnerHTML. Removes <script>, event handlers (onerror, …),
 * javascript: URIs and any tag/attribute outside the allow-list.
 *
 * Pure function: deterministic, no side effects — safe to unit-test.
 */
export function sanitizeHtml(html: string): string {
    return DOMPurify.sanitize(html, {
        ALLOWED_TAGS,
        ALLOWED_ATTR,
        ALLOW_DATA_ATTR: false,
        // Drop unknown protocols on anchors (allow http/https/mailto/tel only).
        ALLOWED_URI_REGEXP: /^(?:(?:https?|mailto|tel):|[^a-z]|[a-z+.-]+(?:[^a-z+.-:]|$))/i,
    });
}
