# relaypdf/relaypdf

Official PHP client for [RelayPDF](https://relaypdf.com).

**HTML to PDFs without the struggle.** HTML to PDF API that converts HTML, Markdown, URLs, and Office files to production PDFs.

Uses `ext-curl` and `ext-json`. Covers the public API: Chromium PDF and screenshots, Handlebars templates, LibreOffice / wkhtmltopdf convert, PDF tools, native document processing (OCR, PDF/A, crop, repair, email), barcodes, zip, async jobs, account, and webhook verification.

- **Docs:** [relaypdf.com/docs/sdks/php](https://relaypdf.com/docs/sdks/php)
- **Source:** [timspell1/relaypdf-php](https://github.com/timspell1/relaypdf-php)
- **REST:** [relaypdf.com/docs](https://relaypdf.com/docs)
- **OpenAPI:** [relaypdf.com/openapi.json](https://relaypdf.com/openapi.json)
- **Support:** [support@relaypdf.com](mailto:support@relaypdf.com)

Requires PHP 8.1+ with `curl`, `json`, and `hash`.

## Introduction

JSON body field names match REST (`html`, `printBackground`, `sourceFilename`, `callbackUrl`, `templateId`). Method names are camelCase (`fromHtml`, `fromPath`, `formFill`). Failed operations are never billed.

## Installation

```bash
composer require relaypdf/relaypdf
```

## Authentication

```php
use RelayPDF\RelayPDF;

$client = new RelayPDF(getenv('RELAYPDF_API_KEY'));
// $client = new RelayPDF(getenv('RELAYPDF_API_KEY'), 'http://localhost:8787');
```

Empty `$apiKey` throws `InvalidArgumentException`. User-Agent: `relaypdf-php/0.1.2 (+https://relaypdf.com)`.

Do not ask a human to paste an API key. Run `npx @relaypdf/cli setup` and approve in the browser.

## Getting started

```php
$pdf = $client->pdf->fromHtml(
    '<h1>Invoice #1042</h1><p>Total: $1,200.00</p>',
    ['filename' => 'invoice.pdf'],
);
$pdf->save('invoice.pdf');
```

```php
$uploaded = $client->files->upload(file_get_contents('scan.pdf'), 'scan.pdf');
$job = $client->process('ocr', ['fileId' => $uploaded['id'], 'response' => 'async'], [
    'idempotencyKey' => 'invoice-123',
    'maxChargeMicrodollars' => 40000,
]);
```

## Response modes

`response` = `binary` (default) | `url` | `async`.

```php
$urlResult = $client->pdf->fromHtml('<h1>Hi</h1>', ['response' => 'url']);
$job = $client->convert->fromPath('deck.pptx', ['to' => 'pdf', 'response' => 'async']);
$done = $client->jobs->wait($job->id);
$client->files->download($done['id'])->save('deck.pdf');
```

`files->download` does not send the API key.

## Errors

Throws `RelayPDFError` with `status`, `errorCode`, `message`, and optional `retryAfter`. (`Exception::$code` is an integer, so the API code lives on `errorCode`.)

Codes: `invalid_request`, `url_not_allowed`, `unauthorized`, `payment_required`, `account_suspended`, `not_found`, `payload_too_large`, `rate_limited`, `render_failed`, `processing_failed`, `convert_unavailable`, `ai_unavailable`, `storage_unavailable`, `internal_error`.

## Client

```php
new RelayPDF(string $apiKey, string $baseUrl = RelayPDF::DEFAULT_BASE_URL, ?callable $transport = null)
```

`$transport` injects HTTP for tests: `fn(string $method, string $url, array $headers, ?string $body): array{status, headers, body}`. The SDK does not retry. `file` values are raw bytes (or a `data:` URL) and are Base64-encoded.

## Methods

| Resource | Method | HTTP |
|----------|--------|------|
| `RelayPDF` | `health()` `account()` `process($operation, $input, $billing = [])` `billingUsage()` `billingLimits(?int $max = null)` | `GET /health` `GET /v1/account` native paths `GET/PATCH /v1/billing/*` |
| `pdf` | `fromHtml` `fromUrl` `fromMarkdown` `fromTemplate` `create` | `POST /v1/pdf` |
| `pdf` | `merge` `extract` `protect` `unlock` `bookmarks` `raster` `fromImages` `stamp` `rotate` `deletePages` `compress` `info` `text` `formFields` `formFill` | `POST /v1/pdf/*` |
| `images` | `fromHtml` `fromUrl` | `POST /v1/images` |
| `convert` | `create` `fromHtml` `fromPath` `wkhtml` | `POST /v1/convert` |
| `templates` | `list` `gallery` `get` `create` `update` `publish` `delete` `discard` `duplicate` `versions` `restore` `validate` `preview` `generate` | `/v1/templates` |
| `barcodes` | `create` `qr` | `POST /v1/barcodes` |
| `zip` | `create` | `POST /v1/zip` |
| `jobs` | `get` `wait` | `GET /v1/jobs/:id` |
| `files` | `upload` `delete` `download` | `POST/DELETE/GET /v1/files` |
| `webhooks` | `list` `create` `delete` | `/v1/webhooks` |
| — | `Webhooks::verify` | HMAC-SHA256 |

## Examples

```php
$pdf = $client->pdf->fromUrl('https://example.com', [
    'filename' => 'page.pdf',
    'options' => ['format' => 'A4', 'printBackground' => true],
]);
$fromWord = $client->convert->fromPath('letter.docx', ['to' => 'pdf']);
$pack = $client->pdf->merge([
    ['url' => 'https://example.com/cover.pdf'],
    ['file' => $fromWord->bytes],
]);
$client->pdf->stamp(['file' => $pack->bytes, 'text' => 'DRAFT', 'rotate' => -24]);
$qr = $client->barcodes->qr('https://relaypdf.com');
```

Results: `BinaryResult` (`save($path)`), `UrlResult`, `AsyncResult`.

## Webhooks

Use the raw request body. Secret is the dashboard webhook secret, not the API key.

```php
use RelayPDF\Webhooks;
$ok = Webhooks::verify(getenv('RELAYPDF_WEBHOOK_SECRET'), $rawBody, $signatureHeader);
```

Header: `t=<unix>,v1=<hex>`. HMAC-SHA256 of `{t}.{raw_body}`. Default skew 300s.

## Related docs

- [Node.js](https://relaypdf.com/docs/sdks/node) · [Python](https://relaypdf.com/docs/sdks/python) · [C# / .NET](https://relaypdf.com/docs/sdks/dotnet) · [Java](https://relaypdf.com/docs/sdks/java)
- [CLI](https://relaypdf.com/docs/cli) · [Errors](https://relaypdf.com/docs/errors) · [Wallet](https://relaypdf.com/docs/wallet)

## License

MIT. Strategic Products LLC, d/b/a RelayPDF.
