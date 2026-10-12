import { describe, expect, it } from 'vitest';
import { renderMarkdown } from './markdown';

describe('renderMarkdown', () => {
    it('never emits raw HTML or script from authored or pasted text', () => {
        const out = renderMarkdown('<script>alert(1)</script> and <img src=x onerror=alert(1)>');
        expect(out).not.toContain('<script');
        expect(out).not.toContain('<img');
        expect(out).toContain('&lt;script&gt;');
    });

    it('escapes code, names the block and makes it focusable', () => {
        const out = renderMarkdown('```sql\nSELECT * FROM t WHERE a < 1;\n```');
        expect(out).toContain('SELECT * FROM t WHERE a &lt; 1;');
        expect(out).toContain('aria-label="SQL, 1 line, scrolls sideways"');
        expect(out).toContain('tabindex="0"');
        expect(out).toContain('data-action="copy"');
    });

    it('treats indented text as a plan block too', () => {
        expect(renderMarkdown('Plan:\n\n    1  Sort\n    2    Seq Scan')).toContain('2 lines');
    });
});
