import { describe, expect, it } from 'vitest';
import {
    confidenceLevel,
    escapeHtml,
    linkCitationMarkers,
    markdownToPlain,
    plainToHtml,
    referencedCitations,
} from '@/lib/citations';

const label = (n: number) => `Source ${n}`;

describe('linkCitationMarkers', () => {
    it('turns [n] markers into citation buttons', () => {
        const html = linkCitationMarkers(
            '<p>Refunds take 14 days [1].</p>',
            1,
            label,
        );

        expect(html).toBe(
            '<p>Refunds take 14 days <button type="button" class="cite-ref" data-cite="1" aria-label="Source 1">1</button>.</p>',
        );
    });

    it('leaves markers outside the citation range and inside tags untouched', () => {
        const html = linkCitationMarkers(
            '<a title="[1]">x</a> [3] [2]',
            2,
            label,
        );

        expect(html).toContain('title="[1]"');
        expect(html).toContain('[3]');
        expect(html).toContain('data-cite="2"');
    });

    it('does nothing when there are no citations', () => {
        expect(linkCitationMarkers('<p>[1]</p>', 0, label)).toBe('<p>[1]</p>');
    });

    it('escapes the accessible label', () => {
        const html = linkCitationMarkers('[1]', 1, () => 'a "quoted" <label>');

        expect(html).toContain(
            'aria-label="a &quot;quoted&quot; &lt;label&gt;"',
        );
    });
});

describe('text helpers', () => {
    it('lists referenced citations in order of first use', () => {
        expect(referencedCitations('See [2], then [1] and [2] again.')).toEqual(
            [2, 1],
        );
    });

    it('strips markdown for the typing preview', () => {
        expect(
            markdownToPlain(
                '## Title\n\n**Bold** and *em* with [link](https://x.test) and `code`',
            ),
        ).toBe('Title\n\nBold and em with link and code');
        expect(markdownToPlain('- one\n- two')).toBe('• one\n• two');
    });

    it('escapes plain text into paragraphs', () => {
        expect(plainToHtml('a < b\nnext\n\nsecond')).toBe(
            '<p>a &lt; b<br>next</p><p>second</p>',
        );
        expect(escapeHtml(`<script>alert('x')</script>`)).toBe(
            '&lt;script&gt;alert(&#39;x&#39;)&lt;/script&gt;',
        );
    });

    it('buckets confidence', () => {
        expect(confidenceLevel(0.9)).toBe('high');
        expect(confidenceLevel(0.5)).toBe('medium');
        expect(confidenceLevel(0.2)).toBe('low');
        expect(confidenceLevel(null)).toBe('unknown');
    });
});
