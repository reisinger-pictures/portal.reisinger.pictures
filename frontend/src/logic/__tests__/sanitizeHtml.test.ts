import { describe, it, expect } from 'vitest';
import { sanitizeHtml } from '../sanitizeHtml';

describe('sanitizeHtml', () => {
    it('strips <script> tags entirely', () => {
        const result = sanitizeHtml('<script>alert(1)</script><p>ok</p>');
        expect(result).not.toContain('<script>');
        expect(result).not.toContain('alert');
        expect(result).toContain('<p>ok</p>');
    });

    it('strips event-handler attributes like onerror', () => {
        const result = sanitizeHtml('<img src="x" onerror="alert(1)">');
        expect(result).not.toContain('onerror');
        // img is not in the allow-list → removed entirely
        expect(result).not.toContain('<img');
    });

    it('strips javascript: URIs on anchors', () => {
        const result = sanitizeHtml('<a href="javascript:alert(1)">click</a>');
        expect(result).not.toContain('javascript:');
        // The anchor may survive but href must be neutralized
        expect(result).not.toMatch(/href="javascript:/i);
    });

    it('strips iframe tags', () => {
        const result = sanitizeHtml('<iframe src="https://evil.example"></iframe><p>ok</p>');
        expect(result).not.toContain('<iframe');
        expect(result).toContain('<p>ok</p>');
    });

    it('preserves allowed formatting tags', () => {
        const result = sanitizeHtml('<p>Hello <strong>world</strong> <em>!</em></p>');
        expect(result).toContain('<strong>world</strong>');
        expect(result).toContain('<em>!</em>');
    });

    it('preserves headings and ordered lists', () => {
        const result = sanitizeHtml('<h1>One</h1><h2>Two</h2><h3>Three</h3><h4>Four</h4><ol><li>Item</li></ol>');
        expect(result).toContain('<h1>One</h1>');
        expect(result).toContain('<h2>Two</h2>');
        expect(result).toContain('<h3>Three</h3>');
        expect(result).toContain('<h4>Four</h4>');
        expect(result).toContain('<ol><li>Item</li></ol>');
    });

    it('preserves safe link attributes and strips unsupported attributes', () => {
        const result = sanitizeHtml('<h2 id="heading" class="x" style="color:red">Title</h2><a href="https://example.com" target="_blank" rel="noopener" title="Example" class="x" style="color:red">Link</a>');
        expect(result).toContain('<h2>Title</h2>');
        expect(result).toContain('href="https://example.com"');
        expect(result).toContain('target="_blank"');
        expect(result).toContain('rel="noopener"');
        expect(result).toContain('title="Example"');
        expect(result).not.toContain('class=');
        expect(result).not.toContain('style=');
        expect(result).not.toContain('id=');
    });

    it('preserves table structure', () => {
        const input = '<table><thead><tr><th>H</th></tr></thead><tbody><tr><td>D</td></tr></tbody></table>';
        const result = sanitizeHtml(input);
        expect(result).toContain('<table>');
        expect(result).toContain('<thead>');
        expect(result).toContain('<tbody>');
        expect(result).toContain('<th>H</th>');
        expect(result).toContain('<td>D</td>');
    });

    it('preserves safe anchor hrefs', () => {
        const result = sanitizeHtml('<a href="https://example.com">link</a>');
        expect(result).toContain('href="https://example.com"');
    });

    it('removes disallowed attributes like style', () => {
        const result = sanitizeHtml('<p style="color:red">text</p>');
        expect(result).not.toContain('style');
        expect(result).toContain('<p>text</p>');
    });

    it('removes data-* attributes', () => {
        const result = sanitizeHtml('<p data-track="123">text</p>');
        expect(result).not.toContain('data-track');
    });

    it('handles empty string input', () => {
        expect(sanitizeHtml('')).toBe('');
    });
});
