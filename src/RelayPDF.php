<?php

declare(strict_types=1);

namespace RelayPDF;

final class BinaryResult
{
    public function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly ?string $filename,
        public readonly int $sizeBytes,
        public readonly string $contentType,
        public readonly string $bytes,
    ) {
    }

    public function save(string $path): void
    {
        file_put_contents($path, $this->bytes);
    }
}

final class UrlResult
{
    public function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly string $status,
        public readonly string $url,
        public readonly string $filename,
        public readonly int $sizeBytes,
        public readonly string $expiresAt,
    ) {
    }
}

final class AsyncResult
{
    public function __construct(
        public readonly string $kind,
        public readonly string $id,
        public readonly string $status,
        public readonly string $pollUrl,
    ) {
    }
}

final class RelayPDF
{
    public const DEFAULT_BASE_URL = 'https://api.relaypdf.com';
    public const VERSION = '0.1.1';
    public const USER_AGENT = 'relaypdf-php/' . self::VERSION . ' (+https://relaypdf.com)';

    public readonly PdfResource $pdf;
    public readonly ImagesResource $images;
    public readonly BarcodesResource $barcodes;
    public readonly ZipResource $zip;
    public readonly ConvertResource $convert;
    public readonly TemplatesResource $templates;
    public readonly JobsResource $jobs;
    public readonly FilesResource $files;
    public readonly WebhooksResource $webhooks;

    /** @var callable(string, string, array, ?string): array{status:int, headers:array<string,string>, body:string} */
    private $transport;

    public readonly string $baseUrl;

    public function __construct(
        public readonly string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?callable $transport = null,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey is required.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? [self::class, 'curlTransport'];
        $this->pdf = new PdfResource($this);
        $this->images = new ImagesResource($this);
        $this->barcodes = new BarcodesResource($this);
        $this->zip = new ZipResource($this);
        $this->convert = new ConvertResource($this);
        $this->templates = new TemplatesResource($this);
        $this->jobs = new JobsResource($this);
        $this->files = new FilesResource($this);
        $this->webhooks = new WebhooksResource($this);
    }

    /** Input accepts url, base64 file or an uploaded fileId. */
    public function process(string $operation, array $input, array $billing = []): BinaryResult|UrlResult|AsyncResult
    {
        $paths = [
            'ocr' => '/v1/pdf/ocr',
            'pdfa' => '/v1/pdf/pdfa',
            'crop' => '/v1/pdf/crop',
            'resize' => '/v1/pdf/resize',
            'repair' => '/v1/pdf/repair',
            'optimize' => '/v1/pdf/optimize',
            'attachments' => '/v1/pdf/attachments',
            'extract-images' => '/v1/pdf/extract-images',
            'compress' => '/v1/pdf/compress-advanced',
            'image-convert' => '/v1/images/convert',
            'email' => '/v1/email'
        ];
        if (!isset($paths[$operation])) throw new \InvalidArgumentException('Unknown document operation');
        $headers = [];
        if (!empty($billing['idempotencyKey'])) {
            $headers['Idempotency-Key'] = (string) $billing['idempotencyKey'];
        }
        if (array_key_exists('maxChargeMicrodollars', $billing) && $billing['maxChargeMicrodollars'] !== null) {
            $headers['X-RelayPDF-Max-Charge-Microdollars'] = (string) $billing['maxChargeMicrodollars'];
        }
        return $this->generate($paths[$operation], $input, $headers);
    }

    public function billingUsage(): array
    {
        return json_decode($this->request('/v1/billing/usage')['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function billingLimits(?int $maxJobMicrodollars = null): array
    {
        $response = func_num_args() === 0
            ? $this->request('/v1/billing/limits')
            : $this->request('/v1/billing/limits', 'PATCH', ['maxJobMicrodollars' => $maxJobMicrodollars]);
        return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function health(): array
    {
        return json_decode($this->request('/health', 'GET', null, false)['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function account(): array
    {
        return json_decode($this->request('/v1/account')['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $extraHeaders
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public function request(string $path, string $method = 'GET', ?array $body = null, bool $auth = true, array $extraHeaders = [], ?string $raw = null): array
    {
        $headers = ['User-Agent' => self::USER_AGENT] + $extraHeaders;
        $payload = null;
        if ($auth) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        if ($raw !== null) {
            $payload = $raw;
        } elseif ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        $response = ($this->transport)($method, $this->baseUrl . $path, $headers, $payload);
        if ($response['status'] >= 400) {
            throw self::errorFrom($response);
        }
        return $response;
    }

    public function generate(string $path, array $body, array $headers = []): BinaryResult|UrlResult|AsyncResult
    {
        $response = $this->request($path, 'POST', $body, true, $headers);
        if ($response['status'] === 202) {
            $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            return new AsyncResult('async', $payload['id'], 'processing', $payload['pollUrl']);
        }
        $contentType = $response['headers']['content-type'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            if (!empty($payload['url']) && ($payload['status'] ?? '') === 'completed') {
                return new UrlResult(
                    'url',
                    $payload['id'],
                    $payload['status'] ?? 'completed',
                    $payload['url'],
                    $payload['filename'],
                    $payload['sizeBytes'],
                    $payload['expiresAt'],
                );
            }
            return new BinaryResult(
                'binary',
                header_get($response['headers'], 'x-relaypdf-id') ?: ($payload['id'] ?? ''),
                filename_from_disposition(header_get($response['headers'], 'content-disposition')),
                strlen($response['body']),
                $contentType,
                $response['body'],
            );
        }
        $size = header_get($response['headers'], 'x-relaypdf-size');
        return new BinaryResult(
            'binary',
            header_get($response['headers'], 'x-relaypdf-id') ?: '',
            filename_from_disposition(header_get($response['headers'], 'content-disposition')),
            $size !== '' ? (int) $size : strlen($response['body']),
            $contentType !== '' ? $contentType : 'application/octet-stream',
            $response['body'],
        );
    }

    /**
     * @param array{status:int, headers:array<string,string>, body:string} $response
     */
    private static function errorFrom(array $response): RelayPDFError
    {
        $code = 'internal_error';
        $message = 'Request failed.';
        $details = null;
        $retryAfter = null;
        $retryRaw = header_get($response['headers'], 'retry-after');
        if ($retryRaw !== '' && ctype_digit($retryRaw)) {
            $retryAfter = (int) $retryRaw;
        }
        try {
            $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            $err = $payload['error'] ?? [];
            $code = $err['code'] ?? $code;
            $message = $err['message'] ?? $message;
            $details = $err['details'] ?? null;
        } catch (\Throwable) {
        }
        return new RelayPDFError($response['status'], $code, $message, $retryAfter, $details);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status:int, headers:array<string,string>, body:string}
     */
    public static function curlTransport(string $method, string $url, array $headers, ?string $body): array
    {
        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RelayPDFError(503, 'internal_error', 'Unable to initialize HTTP client.');
        }
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RelayPDFError(503, 'internal_error', $err !== '' ? $err : 'HTTP request failed.');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $raw];
    }
}

/** @param array<string, string> $headers */
function header_get(array $headers, string $name): string
{
    foreach ($headers as $key => $value) {
        if (strcasecmp($key, $name) === 0) {
            return $value;
        }
    }
    return '';
}

function filename_from_disposition(?string $header): ?string
{
    if ($header === null || $header === '') {
        return null;
    }
    if (preg_match("/filename\\*=UTF-8''([^;]+)/i", $header, $match)) {
        return rawurldecode($match[1]);
    }
    if (preg_match('/filename="([^"]+)"/i', $header, $match)) {
        return $match[1];
    }
    if (preg_match('/filename=([^;]+)/i', $header, $match)) {
        return trim($match[1]);
    }
    return null;
}

function encode_file(string $value): string
{
    return Webhooks::toBase64($value);
}

/** @param array<string, mixed> $item */
function encode_ref(array $item): array
{
    if (!empty($item['url'])) {
        return ['url' => $item['url']];
    }
    if (array_key_exists('file', $item)) {
        $encoded = ['file' => encode_file((string) $item['file'])];
        if (isset($item['filename'])) {
            $encoded['filename'] = $item['filename'];
        }
        return $encoded;
    }
    throw new \InvalidArgumentException('Provide exactly one of `url` or `file`.');
}

final class PdfResource
{
    public function __construct(private readonly RelayPDF $client)
    {
    }

    public function create(array $input): BinaryResult|UrlResult|AsyncResult
    {
        return $this->client->generate('/v1/pdf', $input);
    }

    public function fromHtml(string $html, array $extra = []): BinaryResult|UrlResult|AsyncResult
    {
        return $this->create(['html' => $html] + $extra);
    }

    public function fromUrl(string $url, array $extra = []): BinaryResult|UrlResult|AsyncResult
    {
        return $this->create(['url' => $url] + $extra);
    }

    public function fromMarkdown(string $markdown, array $extra = []): BinaryResult|UrlResult|AsyncResult
    {
        return $this->create(['markdown' => $markdown] + $extra);
    }

    public function fromTemplate(string $templateId, array $templateData, array $extra = []): BinaryResult|UrlResult|AsyncResult
    {
        return $this->create(['templateId' => $templateId, 'templateData' => $templateData] + $extra);
    }

    public function merge(array $files, array $extra = []): BinaryResult|UrlResult|AsyncResult
    {
        return $this->client->generate('/v1/pdf/merge', $extra + ['files' => array_map(encode_ref(...), $files)]);
    }

    public function extract(string|array $pages, array $input = []): BinaryResult|UrlResult|AsyncResult
    {
        $body = $input + ['pages' => $pages];
        if (isset($body['file'])) {
            $body['file'] = encode_file((string) $body['file']);
        }
        return $this->client->generate('/v1/pdf/extract', $body);
    }

    public function protect(string $userPassword, array $input = []): BinaryResult|UrlResult|AsyncResult
    {
        $body = $input + ['userPassword' => $userPassword];
        if (isset($body['file'])) {
            $body['file'] = encode_file((string) $body['file']);
        }
        return $this->client->generate('/v1/pdf/protect', $body);
    }

    public function bookmarks(array $bookmarks, array $input = []): BinaryResult|UrlResult|AsyncResult
    {
        $body = $input + ['bookmarks' => $bookmarks];
        if (isset($body['file'])) {
            $body['file'] = encode_file((string) $body['file']);
        }
        return $this->client->generate('/v1/pdf/bookmarks', $body);
    }

    public function raster(array $input = []): BinaryResult|UrlResult|AsyncResult
    {
        if (isset($input['file'])) {
            $input['file'] = encode_file((string) $input['file']);
        }
        return $this->client->generate('/v1/pdf/raster', $input);
    }

    public function fromImages(array $files, array $extra = []): BinaryResult|UrlResult|AsyncResult
    {
        return $this->client->generate('/v1/pdf/from-images', $extra + ['files' => array_map(encode_ref(...), $files)]);
    }

    public function stamp(array $input = []): BinaryResult|UrlResult|AsyncResult
    {
        if (isset($input['file'])) {
            $input['file'] = encode_file((string) $input['file']);
        }
        if (isset($input['image']) && is_array($input['image'])) {
            $input['image'] = encode_ref($input['image']);
        }
        return $this->client->generate('/v1/pdf/stamp', $input);
    }

    public function rotate(int $degrees, array $input = []): BinaryResult|UrlResult|AsyncResult
    {
        $body = $input + ['degrees' => $degrees];
        if (isset($body['file'])) {
            $body['file'] = encode_file((string) $body['file']);
        }
        return $this->client->generate('/v1/pdf/rotate', $body);
    }

    public function deletePages(string|array $pages, array $input = []): BinaryResult|UrlResult|AsyncResult
    {
        $body = $input + ['pages' => $pages];
        if (isset($body['file'])) {
            $body['file'] = encode_file((string) $body['file']);
        }
        return $this->client->generate('/v1/pdf/delete-pages', $body);
    }

    public function compress(array $input = []): BinaryResult|UrlResult|AsyncResult
    {
        if (isset($input['file'])) {
            $input['file'] = encode_file((string) $input['file']);
        }
        return $this->client->generate('/v1/pdf/compress', $input);
    }

    public function info(array $input = []): BinaryResult|UrlResult|AsyncResult
    {
        if (isset($input['file'])) {
            $input['file'] = encode_file((string) $input['file']);
        }
        return $this->client->generate('/v1/pdf/info', $input);
    }

    public function unlock(string $password, array $input = []): BinaryResult|UrlResult|AsyncResult
    {
        $body = $input + ['password' => $password];
        if (isset($body['file'])) {
            $body['file'] = encode_file((string) $body['file']);
        }
        return $this->client->generate('/v1/pdf/unlock', $body);
    }

    public function text(array $input = []): BinaryResult|UrlResult|AsyncResult
    {
        if (isset($input['file'])) {
            $input['file'] = encode_file((string) $input['file']);
        }
        return $this->client->generate('/v1/pdf/text', $input);
    }

    public function data(array $input = []): BinaryResult|UrlResult|AsyncResult
    {
        if (isset($input['file'])) {
            $input['file'] = encode_file((string) $input['file']);
        }
        return $this->client->generate('/v1/pdf/data', $input);
    }

    public function formFields(array $input = []): BinaryResult|UrlResult|AsyncResult
    {
        if (isset($input['file'])) {
            $input['file'] = encode_file((string) $input['file']);
        }
        return $this->client->generate('/v1/pdf/form/fields', $input);
    }

    public function formFill(array $fields, array $input = []): BinaryResult|UrlResult|AsyncResult
    {
        $body = $input + ['fields' => $fields];
        if (isset($body['file'])) {
            $body['file'] = encode_file((string) $body['file']);
        }
        return $this->client->generate('/v1/pdf/form/fill', $body);
    }
}

final class ImagesResource
{
    public function __construct(private readonly RelayPDF $client)
    {
    }

    public function create(array $input): BinaryResult|UrlResult|AsyncResult
    {
        return $this->client->generate('/v1/images', $input);
    }

    public function fromHtml(string $html, array $extra = []): BinaryResult|UrlResult|AsyncResult
    {
        return $this->create(['html' => $html] + $extra);
    }

    public function fromUrl(string $url, array $extra = []): BinaryResult|UrlResult|AsyncResult
    {
        return $this->create(['url' => $url] + $extra);
    }
}

final class BarcodesResource
{
    public function __construct(private readonly RelayPDF $client)
    {
    }

    public function create(array $input): BinaryResult|UrlResult|AsyncResult
    {
        return $this->client->generate('/v1/barcodes', $input);
    }

    public function qr(string $text, array $extra = []): BinaryResult|UrlResult|AsyncResult
    {
        return $this->create(['type' => 'qr', 'text' => $text] + $extra);
    }
}

final class ZipResource
{
    public function __construct(private readonly RelayPDF $client)
    {
    }

    public function create(array $files, array $extra = []): BinaryResult|UrlResult|AsyncResult
    {
        $encoded = [];
        foreach ($files as $item) {
            $row = encode_ref($item);
            if (isset($item['filename'])) {
                $row['filename'] = $item['filename'];
            }
            $encoded[] = $row;
        }
        return $this->client->generate('/v1/zip', $extra + ['files' => $encoded]);
    }
}

final class ConvertResource
{
    public function __construct(private readonly RelayPDF $client)
    {
    }

    public function create(array $input): BinaryResult|UrlResult|AsyncResult
    {
        if (isset($input['file'])) {
            $input['file'] = encode_file((string) $input['file']);
        }
        return $this->client->generate('/v1/convert', $input);
    }

    public function fromHtml(string $html, array $extra = []): BinaryResult|UrlResult|AsyncResult
    {
        return $this->create(['html' => $html] + $extra);
    }

    public function fromPath(string $path, array $extra = []): BinaryResult|UrlResult|AsyncResult
    {
        $extra['sourceFilename'] ??= basename($path);
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \InvalidArgumentException("Unable to read {$path}");
        }
        return $this->create(['file' => $bytes] + $extra);
    }

    public function wkhtml(array $input = []): BinaryResult|UrlResult|AsyncResult
    {
        $toc = $input['toc'] ?? null;
        unset($input['toc']);
        $options = $input['options'] ?? [];
        unset($input['options']);
        if ($toc !== null) {
            $options['toc'] = $toc;
        }
        return $this->create(['engine' => 'wkhtmltopdf', 'to' => 'pdf', 'options' => $options ?: null] + $input);
    }
}

final class TemplatesResource
{
    public function __construct(private readonly RelayPDF $client)
    {
    }

    public function json(string $path, string $method = 'GET', ?array $body = null): array
    {
        return json_decode($this->client->request($path, $method, $body)['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function list(): array
    {
        return $this->json('/v1/templates');
    }

    public function gallery(): array
    {
        return $this->json('/v1/templates/gallery');
    }

    public function get(string $id): array
    {
        return $this->json('/v1/templates/' . rawurlencode($id));
    }

    public function create(array $input): array
    {
        return $this->json('/v1/templates', 'POST', ['engine' => 'handlebars'] + $input);
    }

    public function update(string $id, array $input): array
    {
        return $this->json('/v1/templates/' . rawurlencode($id), 'PATCH', $input);
    }

    public function publish(string $id, ?string $comment = null): array
    {
        return $this->json('/v1/templates/' . rawurlencode($id) . '/publish', 'POST', ['comment' => $comment]);
    }

    public function delete(string $id): array
    {
        return $this->json('/v1/templates/' . rawurlencode($id), 'DELETE');
    }

    public function discard(string $id): array
    {
        return $this->json('/v1/templates/' . rawurlencode($id) . '/discard', 'POST', []);
    }

    public function duplicate(string $id): array
    {
        return $this->json('/v1/templates/' . rawurlencode($id) . '/duplicate', 'POST', []);
    }

    public function versions(string $id): array
    {
        return $this->json('/v1/templates/' . rawurlencode($id) . '/versions');
    }

    public function restore(string $id, int $version): array
    {
        return $this->json('/v1/templates/' . rawurlencode($id) . '/restore', 'POST', ['version' => $version]);
    }

    public function validate(array $input): array
    {
        return $this->json('/v1/templates/validate', 'POST', ['engine' => 'handlebars'] + $input);
    }

    public function preview(array $input): BinaryResult|UrlResult|AsyncResult
    {
        return $this->client->generate('/v1/templates/preview', ['engine' => 'handlebars'] + $input);
    }

    public function generate(array $input): array
    {
        return $this->json('/v1/templates/generate', 'POST', $input);
    }
}

final class JobsResource
{
    public function __construct(private readonly RelayPDF $client)
    {
    }

    public function get(string $id): array
    {
        return json_decode($this->client->request('/v1/jobs/' . rawurlencode($id))['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function wait(string $id, int $intervalMs = 1000, int $timeoutMs = 120000): array
    {
        $deadline = microtime(true) + ($timeoutMs / 1000);
        while (microtime(true) < $deadline) {
            $job = $this->get($id);
            if (($job['status'] ?? '') === 'completed') {
                return $job;
            }
            if (($job['status'] ?? '') === 'failed') {
                $err = $job['error'] ?? [];
                throw new RelayPDFError(502, $err['code'] ?? 'processing_failed', $err['message'] ?? 'Job failed.');
            }
            usleep($intervalMs * 1000);
        }
        throw new RelayPDFError(408, 'processing_failed', "Timed out waiting for job {$id}.");
    }
}

final class FilesResource
{
    public function __construct(private readonly RelayPDF $client)
    {
    }

    public function download(string $id): BinaryResult
    {
        $response = $this->client->request('/v1/files/' . rawurlencode($id), 'GET', null, false);
        $size = header_get($response['headers'], 'x-relaypdf-size');
        return new BinaryResult(
            'binary',
            header_get($response['headers'], 'x-relaypdf-id') ?: $id,
            filename_from_disposition(header_get($response['headers'], 'content-disposition')),
            $size !== '' ? (int) $size : strlen($response['body']),
            header_get($response['headers'], 'content-type') ?: 'application/octet-stream',
            $response['body'],
        );
    }

    public function upload(string $bytes, string $filename = 'upload.bin'): array
    {
        return json_decode($this->client->request(
            '/v1/files',
            'POST',
            null,
            true,
            [
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => (string) strlen($bytes),
                'X-Filename' => $filename,
            ],
            $bytes,
        )['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function delete(string $id): array
    {
        $raw = $this->client->request('/v1/files/' . rawurlencode($id), 'DELETE')['body'];
        return $raw === '' ? ['ok' => true] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}

final class WebhooksResource
{
    public function __construct(private readonly RelayPDF $client)
    {
    }

    public function json(string $path, string $method = 'GET', ?array $body = null): array
    {
        return json_decode($this->client->request($path, $method, $body)['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function list(): array
    {
        return $this->json('/v1/webhooks');
    }

    public function create(string $url, ?array $events = null): array
    {
        $payload = ['url' => $url];
        if ($events !== null) {
            $payload['events'] = $events;
        }
        return $this->json('/v1/webhooks', 'POST', $payload);
    }

    public function delete(string $id): array
    {
        return $this->json('/v1/webhooks/' . rawurlencode($id), 'DELETE');
    }
}
