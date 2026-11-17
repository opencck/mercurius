<?php

declare(strict_types=1);

namespace OpenCCK\Test\Sandbox;

/**
 * The text of a document comes out in the sandbox, as the participant: an untrusted file is never parsed in the app.
 *
 * @group sandbox
 */
final class ExtractTextTest extends SandboxTestCase {
    private const SCRIPT = 'python3 /opt/mercurius/extract_text.py';

    /** A one-page PDF with one line of text, offsets computed. */
    private static function pdf(string $line): string {
        $stream = "BT /F1 18 Tf 72 720 Td ({$line}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    public function testOfficeDocumentsAndPdfGiveTheirText(): void {
        $made = $this->sh(7, <<<'SH'
            python3 - <<'PY'
            from docx import Document
            from pptx import Presentation
            from openpyxl import Workbook
            d = Document(); d.add_paragraph('Отчёт за неделю'); t = d.add_table(rows=1, cols=2); t.cell(0, 0).text = 'ячейка'; d.save('report.docx')
            p = Presentation(); s = p.slides.add_slide(p.slide_layouts[1]); s.shapes.title.text = 'Слайд про кошек'; p.save('deck.pptx')
            w = Workbook(); w.active['A1'] = 'цена'; w.active['B1'] = 42; w.save('sheet.xlsx')
            PY
            SH);
        self::assertSame(0, $made->exitCode, $made->stderr);
        $this->files->write(7, self::limits(), 'hello.pdf', self::pdf('Hello PDF'));
        $this->files->write(7, self::limits(), 'note.rtf', '{\rtf1\ansi{\fonttbl{\f0 Arial;}}{\*\generator x;}\f0 Plain \b rich\b0  text\par}');

        $cases = ['report.docx' => ['Отчёт за неделю', 'ячейка'], 'deck.pptx' => ['Слайд про кошек', '--- слайд 1 ---'], 'sheet.xlsx' => ["цена\t42"], 'hello.pdf' => ['Hello PDF'], 'note.rtf' => ['Plain rich text']];
        foreach ($cases as $file => $expected) {
            $result = $this->sh(7, self::SCRIPT . ' ' . escapeshellarg($file) . ' 20000');
            self::assertSame(0, $result->exitCode, $file . ': ' . $result->stderr);
            foreach ($expected as $line) {
                self::assertStringContainsString($line, $result->stdout, $file);
            }
            self::assertStringNotContainsString('generator', $result->stdout, $file);
        }
    }

    public function testAnUnknownKindIsCode2(): void {
        $this->files->write(7, self::limits(), 'blob.xyz', 'binary');

        self::assertSame(2, $this->sh(7, self::SCRIPT . ' blob.xyz 100')->exitCode);
    }

    public function testABrokenFileIsCode1AndTheLimitCutsTheText(): void {
        $this->files->write(7, self::limits(), 'broken.docx', 'not a zip');
        self::assertSame(1, $this->sh(7, self::SCRIPT . ' broken.docx 100')->exitCode);

        $this->files->write(7, self::limits(), 'long.pdf', self::pdf(str_repeat('word ', 40)));
        $result = $this->sh(7, self::SCRIPT . ' long.pdf 50');
        self::assertSame(0, $result->exitCode);
        self::assertLessThanOrEqual(50, mb_strlen($result->stdout));
    }
}
