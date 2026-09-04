import { describe, expect, it } from 'vitest';
import {
    downloadErrorMessage,
    downloadFilename,
    downloadResponseErrorMessage,
} from '@/lib/file-download';

// Covers OUT-010 response parsing used by the retryable download control.
describe('file download', () => {
    it('uses the UTF-8 content disposition filename', () => {
        expect(
            downloadFilename(
                "attachment; filename=payroll.pdf; filename*=UTF-8''2026%E5%B9%B409%E6%9C%88_%E7%B5%A6%E4%B8%8E.pdf",
                'fallback.pdf',
            ),
        ).toBe('2026年09月_給与.pdf');
    });

    it('falls back when the filename is unavailable or malformed', () => {
        expect(downloadFilename(null, 'fallback.xlsx')).toBe('fallback.xlsx');
        expect(
            downloadFilename(
                "attachment; filename*=UTF-8''%E0%A4%A",
                'fallback.xlsx',
            ),
        ).toBe('fallback.xlsx');
    });

    it('shows the first validation error and otherwise uses a safe fallback', () => {
        expect(
            downloadErrorMessage({
                errors: { payroll: ['給与を再計算してください。'] },
            }),
        ).toBe('給与を再計算してください。');
        expect(downloadErrorMessage('<html>error</html>')).toBe(
            'ファイルを生成できませんでした。',
        );
    });

    it('does not expose an internal server error message', () => {
        expect(
            downloadResponseErrorMessage(500, {
                message: 'SQLSTATE including a secret path',
            }),
        ).toBe('ファイル生成中にエラーが発生しました。');
    });
});
