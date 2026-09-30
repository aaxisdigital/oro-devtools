<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Database;

use Aaxis\Bundle\DevToolsBundle\Database\ResultExporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ResultExporterTest extends TestCase
{
    /** @var string[] */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function fileOf(Response $response): string
    {
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        $path = $response->getFile()->getPathname();
        $this->files[] = $path;

        return $path;
    }

    public function testCsvContainsTheHeaderThenTheRowsInColumnOrder(): void
    {
        $response = (new ResultExporter())->export('csv', ['id', 'name'], [
            ['name' => 'Ann', 'id' => 1],
            ['id' => 2],
        ]);

        $lines = file($this->fileOf($response), FILE_IGNORE_NEW_LINES);
        self::assertSame(['id,name', '1,Ann', '2,'], $lines);
        self::assertSame('text/csv', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename=export.csv', (string) $response->headers->get('Content-Disposition'));
    }

    public function testCsvNeutralizesFormulaInjection(): void
    {
        $rows = [['a' => '=SUM(A1)', 'b' => '+cmd', 'c' => '-1+2', 'd' => '@x', 'e' => "\tTab", 'f' => "\rCR"]];

        $response = (new ResultExporter())->export('csv', ['a', 'b', 'c', 'd', 'e', 'f'], $rows);

        $handle = fopen($this->fileOf($response), 'rb');
        fgetcsv($handle, null, ',', '"', '\\');
        $row = fgetcsv($handle, null, ',', '"', '\\');
        fclose($handle);
        self::assertSame(["'=SUM(A1)", "'+cmd", "'-1+2", "'@x", "'\tTab", "'\rCR"], $row);
    }

    public function testCsvLeavesNumbersAndOrdinaryTextAlone(): void
    {
        $rows = [['a' => '-5', 'b' => '+3.14', 'c' => 'plain', 'd' => '', 'e' => 7, 'f' => null]];

        $response = (new ResultExporter())->export('csv', ['a', 'b', 'c', 'd', 'e', 'f'], $rows);

        $handle = fopen($this->fileOf($response), 'rb');
        fgetcsv($handle, null, ',', '"', '\\');
        $row = fgetcsv($handle, null, ',', '"', '\\');
        fclose($handle);
        self::assertSame(['-5', '+3.14', 'plain', '', '7', ''], $row);
    }

    public function testTheTemporaryFileIsRemovedOnceTheResponseHasBeenSent(): void
    {
        $response = (new ResultExporter())->export('csv', ['a'], [['a' => 1]]);
        $path = $this->fileOf($response);
        self::assertFileExists($path);

        ob_start();
        $response->sendContent();
        $sent = ob_get_clean();

        self::assertSame("a\n1\n", $sent);
        self::assertFileDoesNotExist($path);
    }

    public function testJsonIsPrettyPrintedWithUnescapedUnicode(): void
    {
        $rows = [['name' => 'Zoë', 'n' => 1]];

        $response = (new ResultExporter())->export('json', ['name', 'n'], $rows);

        self::assertNotInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), $response->getContent());
        self::assertStringContainsString('Zoë', (string) $response->getContent());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename=export.json', (string) $response->headers->get('Content-Disposition'));
    }

    public function testXlsxIsAValidWorkbookWithTheHeaderAndTheRows(): void
    {
        $response = (new ResultExporter())->export('xlsx', ['id', 'note', 'meta'], [
            ['id' => 1, 'note' => '=1+1', 'meta' => ['k' => 'v']],
            ['id' => 2, 'note' => null, 'meta' => true],
        ]);

        $path = $this->fileOf($response);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $content = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $content .= $zip->getFromIndex($i);
        }
        $zip->close();
        $content = html_entity_decode($content, ENT_QUOTES);

        self::assertStringContainsString('id', $content);
        self::assertStringContainsString('note', $content);
        self::assertStringContainsString("'=1+1", $content);
        self::assertStringContainsString('{"k":"v"}', $content);
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
        self::assertStringContainsString('export.xlsx', (string) $response->headers->get('Content-Disposition'));
    }

    public function testAnUnknownFormatIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported export format "pdf".');

        (new ResultExporter())->export('pdf', ['a'], []);
    }
}
