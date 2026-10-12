import MarkdownIt from 'markdown-it';
import { useCallback, useRef } from 'react';

// Authored Markdown only. Raw HTML is disabled, so nothing a learner (or an author) types can inject markup
// (PRD §12 safe rendering). Code blocks are focusable, named scroll regions with Wrap and Copy (A-07, A-08).
const md = new MarkdownIt({ html: false, linkify: false, breaks: false });

const LABELS: Record<string, string> = { sql: 'SQL', text: 'Text', php: 'PHP', json: 'JSON', yaml: 'YAML', sh: 'Shell', bash: 'Shell' };

function esc(s: string): string {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function codeBlock(code: string, lang: string): string {
    const body = code.replace(/\n$/, '');
    const lines = body.split('\n').length;
    const label = LABELS[lang.toLowerCase()] ?? (lang ? esc(lang) : 'Code');
    const name = `${label}, ${lines} ${lines === 1 ? 'line' : 'lines'}`;

    return `<div class="code"><div class="code-bar"><span class="code-label">${name}</span><span class="code-actions">`
        + '<button type="button" class="link" data-action="wrap" aria-pressed="false">Wrap</button>'
        + '<button type="button" class="link" data-action="copy">Copy</button></span></div>'
        + `<pre class="code-scroll" tabindex="0" role="region" aria-label="${name}, scrolls sideways"><code>${esc(body)}</code></pre></div>`;
}

md.renderer.rules.fence = (tokens, idx) => codeBlock(tokens[idx].content, (tokens[idx].info || '').trim().split(/\s+/)[0] ?? '');
md.renderer.rules.code_block = (tokens, idx) => codeBlock(tokens[idx].content, 'text');

export function renderMarkdown(source: string, inline = false): string {
    return inline ? md.renderInline(source) : md.render(source);
}

export function Markdown({ source, inline = false, className = '' }: { source: string; inline?: boolean; className?: string }) {
    const ref = useRef<HTMLDivElement>(null);
    const onClick = useCallback((e: React.MouseEvent) => {
        const btn = (e.target as HTMLElement).closest('button[data-action]') as HTMLButtonElement | null;
        if (!btn) return;
        const block = btn.closest('.code');
        if (!block) return;
        if (btn.dataset.action === 'wrap') {
            const on = !block.classList.contains('wrap');
            block.classList.toggle('wrap', on);
            btn.setAttribute('aria-pressed', String(on));
        } else if (btn.dataset.action === 'copy') {
            const text = block.querySelector('code')?.textContent ?? '';
            void navigator.clipboard?.writeText(text).then(() => {
                btn.textContent = 'Copied';
                setTimeout(() => (btn.textContent = 'Copy'), 1500);
            });
        }
    }, []);
    const html = renderMarkdown(source, inline);

    // eslint-disable-next-line react/no-danger -- html:false above; this is the only place Markdown becomes markup
    return inline
        ? <span className={className} dangerouslySetInnerHTML={{ __html: html }} />
        : <div ref={ref} className={`md ${className}`} onClick={onClick} dangerouslySetInnerHTML={{ __html: html }} />;
}
