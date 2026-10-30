import { describe, expect, it } from 'vitest';
import {
    acceptAttribute,
    acceptLabel,
    extensionOf,
    fileKind,
    matchesAccept,
    titleFromFilename,
    validateUpload,
} from '@/lib/files';

const accept = ['pdf', '.docx', 'text/plain', 'image/*'];

describe('file helpers', () => {
    it('extracts extensions', () => {
        expect(extensionOf('Policy.Final.PDF')).toBe('pdf');
        expect(extensionOf('README')).toBe('');
    });

    it('matches extensions, exact MIME types and wildcards', () => {
        expect(matchesAccept({ name: 'a.pdf', type: '' }, accept)).toBe(true);
        expect(matchesAccept({ name: 'a.docx', type: '' }, accept)).toBe(true);
        expect(
            matchesAccept({ name: 'notes', type: 'text/plain' }, accept),
        ).toBe(true);
        expect(
            matchesAccept({ name: 'scan.png', type: 'image/png' }, accept),
        ).toBe(true);
        expect(
            matchesAccept(
                { name: 'app.exe', type: 'application/x-msdownload' },
                accept,
            ),
        ).toBe(false);
        expect(matchesAccept({ name: 'anything.bin', type: '' }, [])).toBe(
            true,
        );
    });

    it('builds the accept attribute and a readable label', () => {
        expect(acceptAttribute(accept)).toBe('.pdf,.docx,text/plain,image/*');
        expect(acceptLabel(accept)).toBe('PDF, DOCX, TXT, IMAGE');
        expect(acceptLabel(['.pdf', 'application/pdf', '.md'])).toBe('PDF, MD');
    });

    it('validates uploads', () => {
        expect(
            validateUpload(
                { name: 'a.pdf', type: 'application/pdf', size: 1024 },
                accept,
                20,
            ),
        ).toBeNull();
        expect(
            validateUpload(
                { name: 'a.pdf', type: 'application/pdf', size: 0 },
                accept,
                20,
            )?.key,
        ).toBe('empty');
        expect(
            validateUpload({ name: 'a.exe', type: '', size: 10 }, accept, 20)
                ?.key,
        ).toBe('type');
        expect(
            validateUpload(
                { name: 'a.pdf', type: '', size: 21 * 1024 * 1024 },
                accept,
                20,
            ),
        ).toEqual({
            key: 'size',
            params: { max: 20 },
        });
    });

    it('derives a title from the file name', () => {
        expect(titleFromFilename('Q3_refund-policy.v2.pdf')).toBe(
            'Q3 refund policy v2',
        );
    });

    it('classifies file kinds', () => {
        expect(fileKind('application/pdf', 'x')).toBe('pdf');
        expect(fileKind('', 'contract.docx')).toBe('doc');
        expect(fileKind('text/csv', 'data.csv')).toBe('sheet');
        expect(fileKind('image/jpeg', 'scan.jpg')).toBe('image');
        expect(fileKind('text/markdown', 'notes.md')).toBe('text');
        expect(fileKind('text/html', 'page.html')).toBe('web');
        expect(fileKind('application/zip', 'a.zip')).toBe('other');
    });
});
