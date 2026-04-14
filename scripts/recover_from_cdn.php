<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI.\n");
    exit(1);
}

$defaults = [
    'db-host' => '127.0.0.1',
    'db-port' => '3306',
    'db-name' => 'typecho',
    'db-user' => 'root',
    'db-pass' => 'root',
    'cdn-base' => '',
    'limit' => '0',
    'dry-run' => false,
];

$options = getopt('', [
    'db-host::',
    'db-port::',
    'db-name::',
    'db-user::',
    'db-pass::',
    'cdn-base::',
    'limit::',
    'dry-run',
]);

$config = array_merge($defaults, $options);
$dryRun = isset($options['dry-run']);
$limit = max(0, (int) $config['limit']);

require_once dirname(__DIR__) . '/oss/alibabacloud-oss-php-sdk-v2-0.4.0.phar';

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['db-host'], $config['db-port'], $config['db-name']),
        $config['db-user'],
        $config['db-pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $pluginConfig = loadPluginConfig($pdo);
    $cdnBase = buildCdnBase($config['cdn-base'], $pluginConfig);
    $pathPrefix = normalizePrefix($pluginConfig['pathPrefix'] ?? '');

    $client = createOssClient($pluginConfig);
    $existingKeys = listExistingKeys($client, (string) $pluginConfig['bucket'], $pathPrefix);
    $attachments = loadPluginAttachments($pdo);
    $references = collectRecoveryCandidates($pdo, $attachments, $cdnBase, $pathPrefix);
    $missing = [];
    foreach ($references as $reference) {
        if (!isset($existingKeys[$reference['key']])) {
            $missing[] = $reference;
        }
    }

    if ($limit > 0) {
        $missing = array_slice($missing, 0, $limit);
    }

    fwrite(STDOUT, sprintf("Found %d missing OSS objects from %d attachment/content references.\n", count($missing), count($references)));
    fwrite(STDOUT, "CDN base: {$cdnBase}\n");

    if ($dryRun) {
        foreach (array_slice($missing, 0, 20) as $item) {
            fwrite(STDOUT, sprintf("[dry-run] cid=%d key=%s url=%s\n", $item['cid'], $item['key'], $item['url']));
        }
        exit(0);
    }

    $restored = 0;
    $failed = 0;

    foreach ($missing as $item) {
        $body = downloadFromCdn($item['url']);
        if ($body === null) {
            $failed++;
            fwrite(STDOUT, sprintf("[miss] %s\n", $item['url']));
            continue;
        }

        $mime = $item['mime'] !== '' ? $item['mime'] : detectMime($body, $item['name']);
        uploadObject($client, (string) $pluginConfig['bucket'], $item['key'], $body, $mime);
        $restored++;
        fwrite(STDOUT, sprintf("[restored] %s\n", $item['key']));
    }

    fwrite(STDOUT, sprintf("Done. restored=%d failed=%d\n", $restored, $failed));
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

function loadPluginConfig(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT value FROM typecho_options WHERE name = ? LIMIT 1');
    $stmt->execute(['plugin:AliOssForTypecho']);
    $row = $stmt->fetch();
    if (!$row || empty($row['value'])) {
        throw new RuntimeException('Plugin config not found.');
    }

    $config = json_decode((string) $row['value'], true);
    if (!is_array($config)) {
        throw new RuntimeException('Plugin config is not valid JSON.');
    }

    foreach (['accessKeyId', 'accessKeySecret', 'bucket', 'region'] as $required) {
        if (empty($config[$required])) {
            throw new RuntimeException('Missing required plugin config: ' . $required);
        }
    }

    return $config;
}

function buildCdnBase(string $override, array $pluginConfig): string
{
    if ($override !== '') {
        return rtrim($override, '/');
    }

    $domain = isset($pluginConfig['domain']) ? (string) $pluginConfig['domain'] : '';
    $prefix = normalizePrefix($pluginConfig['pathPrefix'] ?? '');
    if ($domain === '') {
        throw new RuntimeException('CDN base is empty. Pass --cdn-base.');
    }

    return rtrim($domain, '/') . ($prefix === '' ? '' : '/' . $prefix);
}

function normalizePrefix(string $prefix): string
{
    return trim($prefix, '/');
}

function createOssClient(array $pluginConfig): AlibabaCloud\Oss\V2\Client
{
    $cfg = AlibabaCloud\Oss\V2\Config::loadDefault();
    $cfg->setCredentialsProvider(new AlibabaCloud\Oss\V2\Credentials\StaticCredentialsProvider(
        (string) $pluginConfig['accessKeyId'],
        (string) $pluginConfig['accessKeySecret']
    ));
    $cfg->setRegion((string) $pluginConfig['region']);

    return new AlibabaCloud\Oss\V2\Client($cfg);
}

function listExistingKeys(AlibabaCloud\Oss\V2\Client $client, string $bucket, string $prefix): array
{
    $keys = [];
    $marker = '';

    do {
        $request = new AlibabaCloud\Oss\V2\Models\ListObjectsRequest(
            bucket: $bucket,
            prefix: $prefix === '' ? '' : $prefix . '/',
            delimiter: '/',
            maxKeys: 1000,
            marker: $marker
        );
        $result = $client->listObjects($request);

        if (!empty($result->contents)) {
            foreach ($result->contents as $object) {
                if (substr((string) $object->key, -1) === '/') {
                    continue;
                }
                $keys[(string) $object->key] = true;
            }
        }

        $marker = $result->nextMarker ?? '';
        $isTruncated = $result->isTruncated ?? false;
    } while ($isTruncated);

    return $keys;
}

function loadPluginAttachments(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT cid, title, text FROM typecho_contents WHERE type = 'attachment'");
    $attachments = [];

    while ($row = $stmt->fetch()) {
        if (empty($row['text'])) {
            continue;
        }

        $data = json_decode((string) $row['text'], true);
        if (!is_array($data)) {
            $data = @unserialize((string) $row['text']);
        }

        if (!is_array($data) || empty($data['path']) || strpos((string) $data['path'], '/usr/uploads/oss/') !== 0) {
            continue;
        }

        $attachments[] = [
            'cid' => (int) $row['cid'],
            'title' => (string) $row['title'],
            'path' => (string) $data['path'],
            'mime' => isset($data['mime']) ? (string) $data['mime'] : '',
        ];
    }

    return $attachments;
}

function collectRecoveryCandidates(PDO $pdo, array $attachments, string $cdnBase, string $pathPrefix): array
{
    $candidates = [];

    foreach ($attachments as $attachment) {
        $fileName = basename((string) $attachment['path']);
        if ($fileName === '') {
            continue;
        }

        $key = $pathPrefix === '' ? $fileName : $pathPrefix . '/' . $fileName;
        $candidates[$key] = [
            'cid' => (int) $attachment['cid'],
            'title' => (string) $attachment['title'],
            'name' => $fileName,
            'key' => $key,
            'mime' => (string) ($attachment['mime'] ?? 'application/octet-stream'),
            'url' => rtrim($cdnBase, '/') . '/' . rawurlencode($fileName),
            'source' => 'attachment',
        ];
    }

    foreach (loadContentReferences($pdo, $cdnBase, $pathPrefix) as $reference) {
        $candidates[$reference['key']] = $reference;
    }

    return array_values($candidates);
}

function loadContentReferences(PDO $pdo, string $cdnBase, string $pathPrefix): array
{
    $stmt = $pdo->query("SELECT cid, title, text, type FROM typecho_contents WHERE type IN ('post','page')");
    $references = [];
    $pattern = '#'. preg_quote(rtrim($cdnBase, '/'), '#') . '/([^"\'\\s<>()]+)#u';

    while ($row = $stmt->fetch()) {
        if (empty($row['text']) || !is_string($row['text'])) {
            continue;
        }

        if (!preg_match_all($pattern, $row['text'], $matches)) {
            continue;
        }

        foreach ($matches[1] as $rawName) {
            $fileName = rawurldecode(basename((string) $rawName));
            if ($fileName === '') {
                continue;
            }

            $key = $pathPrefix === '' ? $fileName : $pathPrefix . '/' . $fileName;
            $references[$key] = [
                'cid' => (int) $row['cid'],
                'title' => (string) $row['title'],
                'name' => $fileName,
                'key' => $key,
                'mime' => '',
                'url' => rtrim($cdnBase, '/') . '/' . rawurlencode($fileName),
                'source' => (string) $row['type'],
            ];
        }
    }

    return array_values($references);
}

function downloadFromCdn(string $url): ?string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'follow_location' => 1,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false || $body === '') {
        return null;
    }

    global $http_response_header;
    if (!isset($http_response_header[0]) || strpos($http_response_header[0], '200') === false) {
        return null;
    }

    return $body;
}

function detectMime(string $body, string $fileName): string
{
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($body);
    if (is_string($mime) && $mime !== '') {
        return $mime;
    }

    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $map = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml',
        'avif' => 'image/avif',
    ];

    return $map[$ext] ?? 'application/octet-stream';
}

function uploadObject(AlibabaCloud\Oss\V2\Client $client, string $bucket, string $key, string $body, string $mime): void
{
    $request = new AlibabaCloud\Oss\V2\Models\PutObjectRequest(
        bucket: $bucket,
        key: $key,
        body: AlibabaCloud\Oss\V2\Utils::streamFor($body),
        contentType: $mime
    );
    $client->putObject($request);
}
