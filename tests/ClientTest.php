<?php

declare(strict_types=1);

namespace RelayPDF\Tests;

use PHPUnit\Framework\TestCase;
use RelayPDF\AsyncResult;
use RelayPDF\BinaryResult;
use RelayPDF\RelayPDF;
use RelayPDF\RelayPDFError;
use RelayPDF\UrlResult;
use RelayPDF\Webhooks;

final class ClientTest extends TestCase
{
    public function testHtmlPdfBinary(): void
    {
        $captured = [];
        $client = new RelayPDF('pdf_live_test', RelayPDF::DEFAULT_BASE_URL, function (string $method, string $url, array $headers, ?string $body) use (&$captured) {
            $captured = compact('method', 'url', 'headers', 'body');
            return [
                'status' => 200,
                'headers' => [
                    'content-type' => 'application/pdf',
                    'content-disposition' => 'attachment; filename="hello.pdf"',
                    'x-relaypdf-id' => 'pdf_test',
                    'x-relaypdf-size' => '8',
                ],
                'body' => '%PDF-1.4',
            ];
        });
        $result = $client->pdf->fromHtml('<h1>Hi</h1>', ['filename' => 'hello.pdf']);
        $this->assertSame('https://api.relaypdf.com/v1/pdf', $captured['url']);
        $this->assertSame('Bearer pdf_live_test', $captured['headers']['Authorization']);
        $this->assertStringStartsWith('relaypdf-php/', $captured['headers']['User-Agent']);
        $this->assertSame(['html' => '<h1>Hi</h1>', 'filename' => 'hello.pdf'], json_decode($captured['body'], true));
        $this->assertInstanceOf(BinaryResult::class, $result);
        $this->assertSame('binary', $result->kind);
        $this->assertSame('hello.pdf', $result->filename);
        $this->assertSame('%PDF-1.4', $result->bytes);
    }

    public function testEncodesFileBytes(): void
    {
        $bodies = [];
        $client = $this->client(function (string $method, string $url, array $headers, ?string $body) use (&$bodies) {
            $bodies[] = json_decode((string) $body, true);
            return ['status' => 200, 'headers' => ['content-type' => 'application/pdf'], 'body' => '%PDF'];
        });
        $client->pdf->merge([
            ['url' => 'https://example.com/a.pdf'],
            ['file' => 'PDFB'],
        ]);
        $client->convert->create(['file' => 'DOCX', 'sourceFilename' => 'letter.docx', 'to' => 'pdf']);
        $this->assertSame(base64_encode('PDFB'), $bodies[0]['files'][1]['file']);
        $this->assertSame(base64_encode('DOCX'), $bodies[1]['file']);
    }

    public function testUrlAndAsyncResults(): void
    {
        $n = 0;
        $client = $this->client(function () use (&$n) {
            $n++;
            if ($n === 1) {
                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => json_encode([
                        'id' => 'pdf_1',
                        'status' => 'completed',
                        'url' => 'https://api.relaypdf.com/v1/files/pdf_1',
                        'filename' => 'doc.pdf',
                        'sizeBytes' => 12,
                        'expiresAt' => '2026-08-19T00:00:00.000Z',
                    ]),
                ];
            }
            return [
                'status' => 202,
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode(['id' => 'pdf_2', 'pollUrl' => 'https://api.relaypdf.com/v1/jobs/pdf_2']),
            ];
        });
        $stored = $client->pdf->fromMarkdown('# Hi', ['response' => 'url']);
        $this->assertInstanceOf(UrlResult::class, $stored);
        $job = $client->convert->fromHtml('<h1>Hi</h1>', ['to' => 'docx', 'response' => 'async']);
        $this->assertInstanceOf(AsyncResult::class, $job);
        $this->assertStringContainsString('/v1/jobs/pdf_2', $job->pollUrl);
    }

    public function testRateLimitError(): void
    {
        $client = $this->client(fn () => [
            'status' => 429,
            'headers' => ['content-type' => 'application/json', 'retry-after' => '10'],
            'body' => json_encode(['error' => ['code' => 'rate_limited', 'message' => 'Slow down.']]),
        ]);
        try {
            $client->barcodes->qr('https://example.com');
            $this->fail('expected error');
        } catch (RelayPDFError $err) {
            $this->assertSame(429, $err->status);
            $this->assertSame('rate_limited', $err->errorCode);
            $this->assertSame(10, $err->retryAfter);
        }
    }

    public function testAccount(): void
    {
        $captured = [];
        $client = $this->client(function (string $method, string $url, array $headers) use (&$captured) {
            $captured = compact('url', 'headers');
            return [
                'status' => 200,
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode([
                    'plan' => 'free',
                    'rateTier' => 'free',
                    'wallet' => ['balanceMillicents' => 500000, 'autoReloadEnabled' => false],
                ]),
            ];
        });
        $account = $client->account();
        $this->assertSame('https://api.relaypdf.com/v1/account', $captured['url']);
        $this->assertSame(500000, $account['wallet']['balanceMillicents']);
    }

    public function testWebhook(): void
    {
        $secret = 'whsec_test';
        $body = '{"type":"job.completed"}';
        $timestamp = 1700000000;
        $digest = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $header = "t={$timestamp},v1={$digest}";
        $this->assertTrue(Webhooks::verify($secret, $body, $header, 10 ** 12));
        $this->assertFalse(Webhooks::verify('wrong', $body, $header, 10 ** 12));
    }

    public function testRequiresKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RelayPDF('');
    }

    public function testProcessAndUpload(): void
    {
        $captured = [];
        $client = $this->client(function (string $method, string $url, array $headers, ?string $body) use (&$captured) {
            $captured[] = compact('method', 'url', 'headers', 'body');
            if (str_ends_with($url, '/v1/files')) {
                return [
                    'status' => 201,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => json_encode(['id' => 'upload_test', 'filename' => 'scan.pdf', 'sizeBytes' => 3]),
                ];
            }
            return [
                'status' => 202,
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode([
                    'id' => 'doc_test',
                    'status' => 'processing',
                    'pollUrl' => 'https://api.relaypdf.com/v1/jobs/doc_test',
                ]),
            ];
        });
        $uploaded = $client->files->upload('abc', 'scan.pdf');
        $job = $client->process('ocr', ['fileId' => $uploaded['id'], 'response' => 'async'], [
            'idempotencyKey' => 'invoice-1',
            'maxChargeMicrodollars' => 40000,
        ]);
        $this->assertSame('upload_test', $uploaded['id']);
        $this->assertSame('https://api.relaypdf.com/v1/files', $captured[0]['url']);
        $this->assertSame('scan.pdf', $captured[0]['headers']['X-Filename']);
        $this->assertSame('abc', $captured[0]['body']);
        $this->assertSame('https://api.relaypdf.com/v1/pdf/ocr', $captured[1]['url']);
        $this->assertSame('invoice-1', $captured[1]['headers']['Idempotency-Key']);
        $this->assertSame('40000', $captured[1]['headers']['X-RelayPDF-Max-Charge-Microdollars']);
        $this->assertInstanceOf(AsyncResult::class, $job);
    }

    private function client(callable $transport): RelayPDF
    {
        return new RelayPDF('pdf_live_test', RelayPDF::DEFAULT_BASE_URL, $transport);
    }
}
