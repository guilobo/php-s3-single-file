<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

$config = [
    'endpoint' => envOrArg('SMOKE_ENDPOINT', $argv[1] ?? 'http://127.0.0.1:8080'),
    'access_key' => envOrArg('SMOKE_ACCESS_KEY', $argv[2] ?? 'change-me'),
    'secret_key' => envOrArg('SMOKE_SECRET_KEY', $argv[3] ?? 'change-me'),
    'region' => envOrArg('SMOKE_REGION', 'us-east-1'),
    'service' => envOrArg('SMOKE_SERVICE', 's3'),
    'expect_unsigned_get' => envFlag('SMOKE_EXPECT_UNSIGNED_GET', false),
];

$bucket = 'smoke-' . gmdate('Ymdhis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
$key = 'folder/hello.txt';
$copiedKey = 'folder/copied.txt';
$payload = "hello from smoke test\n";

println('Endpoint: ' . $config['endpoint']);
println('Bucket:   ' . $bucket);

assertResponse(sendSignedRequest($config, 'PUT', '/' . $bucket), 200, 'create bucket');
assertResponse(sendSignedRequest($config, 'PUT', '/' . $bucket), 409, 'duplicate bucket creation');

$listBuckets = sendSignedRequest($config, 'GET', '/');
assertResponse($listBuckets, 200, 'list buckets');
assertContains($listBuckets['body'], '<Name>' . $bucket . '</Name>', 'bucket appears in bucket list');

assertResponse(
    sendSignedRequest($config, 'PUT', '/' . $bucket . '/' . $key, [], $payload, 'text/plain'),
    200,
    'upload object'
);

$headObject = sendSignedRequest($config, 'HEAD', '/' . $bucket . '/' . $key);
assertResponse($headObject, 200, 'head object');
assertHeaderPresent($headObject, 'etag', 'head object returns ETag');

$getObjectAcl = sendSignedRequest($config, 'GET', '/' . $bucket . '/' . $key, ['acl' => '']);
assertResponse($getObjectAcl, 200, 'get object acl');
assertContains($getObjectAcl['body'], '<AccessControlPolicy xmlns="http://s3.amazonaws.com/doc/2006-03-01/">', 'get object acl returns AccessControlPolicy');
assertContains($getObjectAcl['body'], '<Permission>FULL_CONTROL</Permission>', 'get object acl returns owner permission');

$putObjectAcl = sendSignedRequest($config, 'PUT', '/' . $bucket . '/' . $key, ['acl' => '']);
assertResponse($putObjectAcl, 200, 'put object acl');

$getObject = sendSignedRequest($config, 'GET', '/' . $bucket . '/' . $key);
assertResponse($getObject, 200, 'download object');
assertSameText($payload, $getObject['body'], 'downloaded payload matches');

$copyObject = sendSignedRequest(
    $config,
    'PUT',
    '/' . $bucket . '/' . $copiedKey,
    [],
    '',
    'application/octet-stream',
    ['x-amz-copy-source' => '/' . $bucket . '/' . $key]
);
assertResponse($copyObject, 200, 'copy object');
assertContains($copyObject['body'], '<CopyObjectResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">', 'copy object returns CopyObjectResult');

$headCopiedObject = sendSignedRequest($config, 'HEAD', '/' . $bucket . '/' . $copiedKey);
assertResponse($headCopiedObject, 200, 'head copied object');
assertHeaderPresent($headCopiedObject, 'content-length', 'copied object returns Content-Length');
assertHeaderPresent($headCopiedObject, 'content-type', 'copied object returns Content-Type');
assertHeaderPresent($headCopiedObject, 'etag', 'copied object returns ETag');
assertHeaderPresent($headCopiedObject, 'last-modified', 'copied object returns Last-Modified');

$getCopiedObject = sendSignedRequest($config, 'GET', '/' . $bucket . '/' . $copiedKey);
assertResponse($getCopiedObject, 200, 'download copied object');
assertSameText($payload, $getCopiedObject['body'], 'copied payload matches');

$listObjects = sendSignedRequest($config, 'GET', '/' . $bucket, ['list-type' => '2']);
assertResponse($listObjects, 200, 'list objects');
assertContains($listObjects['body'], '<Key>folder/hello.txt</Key>', 'object appears in object list');
assertContains($listObjects['body'], '<Key>folder/copied.txt</Key>', 'copied object appears in object list');

$multipartKey = 'multipart/binary.bin';
$multipartPayload1 = "Part one data for multipart test\n";
$multipartPayload2 = "Part two data for multipart test\n";

$initiateResponse = sendSignedRequest($config, 'POST', '/' . $bucket . '/' . $multipartKey, ['uploads' => ''], '', 'application/octet-stream');
assertResponse($initiateResponse, 200, 'initiate multipart upload');
assertContains($initiateResponse['body'], '<UploadId>', 'initiate returns UploadId');
preg_match('/<UploadId>([^<]+)<\/UploadId>/', $initiateResponse['body'], $uploadIdMatches);
$uploadId = $uploadIdMatches[1] ?? '';

if ($uploadId === '') {
    fail('could not extract UploadId from initiate multipart response');
} else {
    println('[ok] extracted UploadId from initiate response');
}

$uploadPart1 = sendSignedRequest($config, 'PUT', '/' . $bucket . '/' . $multipartKey, ['partNumber' => '1', 'uploadId' => $uploadId], $multipartPayload1, 'application/octet-stream');
assertResponse($uploadPart1, 200, 'upload part 1');
assertHeaderPresent($uploadPart1, 'etag', 'upload part 1 returns ETag');
$etag1 = trim($uploadPart1['headers']['etag'] ?? '', '" ');

$uploadPart2 = sendSignedRequest($config, 'PUT', '/' . $bucket . '/' . $multipartKey, ['partNumber' => '2', 'uploadId' => $uploadId], $multipartPayload2, 'application/octet-stream');
assertResponse($uploadPart2, 200, 'upload part 2');
$etag2 = trim($uploadPart2['headers']['etag'] ?? '', '" ');

$listPartsResp = sendSignedRequest($config, 'GET', '/' . $bucket . '/' . $multipartKey, ['uploadId' => $uploadId]);
assertResponse($listPartsResp, 200, 'list parts');
assertContains($listPartsResp['body'], '<PartNumber>1</PartNumber>', 'list parts includes part 1');
assertContains($listPartsResp['body'], '<PartNumber>2</PartNumber>', 'list parts includes part 2');

$completeBody = '<CompleteMultipartUpload>'
    . '<Part><PartNumber>1</PartNumber><ETag>"' . $etag1 . '"</ETag></Part>'
    . '<Part><PartNumber>2</PartNumber><ETag>"' . $etag2 . '"</ETag></Part>'
    . '</CompleteMultipartUpload>';

$completeResp = sendSignedRequest($config, 'POST', '/' . $bucket . '/' . $multipartKey, ['uploadId' => $uploadId], $completeBody, 'application/xml');
assertResponse($completeResp, 200, 'complete multipart upload');
assertContains($completeResp['body'], '<CompleteMultipartUploadResult', 'complete returns result');

$getMultipart = sendSignedRequest($config, 'GET', '/' . $bucket . '/' . $multipartKey);
assertResponse($getMultipart, 200, 'download multipart object');
assertSameText($multipartPayload1 . $multipartPayload2, $getMultipart['body'], 'multipart object payload matches');

$abortKey = 'multipart/abort-test.bin';
$abortInit = sendSignedRequest($config, 'POST', '/' . $bucket . '/' . $abortKey, ['uploads' => ''], '', 'application/octet-stream');
assertResponse($abortInit, 200, 'initiate multipart abort test');
preg_match('/<UploadId>([^<]+)<\/UploadId>/', $abortInit['body'], $abortIdMatches);
$abortUploadId = $abortIdMatches[1] ?? '';

if ($abortUploadId === '') {
    fail('could not extract UploadId from abort initiate response');
} else {
    println('[ok] extracted UploadId for abort test');
}

$abortPartResp = sendSignedRequest($config, 'PUT', '/' . $bucket . '/' . $abortKey, ['partNumber' => '1', 'uploadId' => $abortUploadId], 'some data', 'application/octet-stream');
assertResponse($abortPartResp, 200, 'upload part for abort test');

$abortResp = sendSignedRequest($config, 'DELETE', '/' . $bucket . '/' . $abortKey, ['uploadId' => $abortUploadId], '', 'application/octet-stream');
assertResponse($abortResp, 204, 'abort multipart upload');

$abortListResp = sendSignedRequest($config, 'GET', '/' . $bucket . '/' . $abortKey, ['uploadId' => $abortUploadId]);
assertResponse($abortListResp, 404, 'aborted upload not found');

$missingBucketObject = sendSignedRequest($config, 'GET', '/missing-bucket/' . $key);
assertResponse($missingBucketObject, 404, 'missing bucket object request');
assertContains($missingBucketObject['body'], '<Code>NoSuchBucket</Code>', 'missing bucket returns NoSuchBucket');

$unsignedGet = sendUnsignedRequest($config, 'GET', '/' . $bucket . '/' . $key);
if ($config['expect_unsigned_get']) {
    assertResponse($unsignedGet, 200, 'unsigned GET when enabled');
} else {
    assertResponse($unsignedGet, 403, 'unsigned GET when disabled');
}

$deleteNonEmptyBucket = sendSignedRequest($config, 'DELETE', '/' . $bucket);
assertResponse($deleteNonEmptyBucket, 409, 'delete non-empty bucket');

assertResponse(sendSignedRequest($config, 'DELETE', '/' . $bucket . '/' . $multipartKey), 204, 'delete multipart object');
assertResponse(sendSignedRequest($config, 'DELETE', '/' . $bucket . '/' . $copiedKey), 204, 'delete copied object');
assertResponse(sendSignedRequest($config, 'DELETE', '/' . $bucket . '/' . $key), 204, 'delete object');
assertResponse(sendSignedRequest($config, 'DELETE', '/' . $bucket), 204, 'delete empty bucket');

println('All smoke tests passed.');
exit(0);

function sendSignedRequest(
    array $config,
    string $method,
    string $path,
    array $query = [],
    string $body = '',
    string $contentType = 'application/octet-stream',
    array $extraHeaders = []
): array {
    $endpoint = rtrim($config['endpoint'], '/');
    $url = $endpoint . $path;
    if ($query !== []) {
        $url .= '?' . buildQuery($query);
    }

    $parts = parse_url($url);
    if ($parts === false || !isset($parts['host'])) {
        fail('Invalid endpoint URL: ' . $url);
    }

    $host = $parts['host'];
    if (isset($parts['port'])) {
        $host .= ':' . $parts['port'];
    }

    $amzDate = gmdate('Ymd\THis\Z');
    $date = gmdate('Ymd');
    $payloadHash = 'UNSIGNED-PAYLOAD';
    $headers = [
        'host' => $host,
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ];

    if ($body !== '') {
        $headers['content-type'] = $contentType;
    }

    foreach ($extraHeaders as $headerName => $headerValue) {
        $headers[strtolower($headerName)] = $headerValue;
    }

    $signedHeaderNames = array_keys($headers);
    sort($signedHeaderNames, SORT_STRING);

    $canonicalRequest = implode("\n", [
        strtoupper($method),
        canonicalUri($path),
        canonicalQuery($query),
        canonicalHeaders($headers, $signedHeaderNames),
        implode(';', $signedHeaderNames),
        $payloadHash,
    ]);

    $scope = $date . '/' . $config['region'] . '/' . $config['service'] . '/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
    $signature = hash_hmac(
        'sha256',
        $stringToSign,
        signatureKey($config['secret_key'], $date, $config['region'], $config['service'])
    );

    $headers['authorization'] = 'AWS4-HMAC-SHA256 '
        . 'Credential=' . $config['access_key'] . '/' . $scope . ', '
        . 'SignedHeaders=' . implode(';', $signedHeaderNames) . ', '
        . 'Signature=' . $signature;

    return sendHttpRequest($method, $url, $headers, $body);
}

function sendUnsignedRequest(array $config, string $method, string $path): array
{
    return sendHttpRequest($method, rtrim($config['endpoint'], '/') . $path, [], '');
}

function sendHttpRequest(string $method, string $url, array $headers, string $body): array
{
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];

    if ($responseBody === false && $responseHeaders === []) {
        fail('Request failed without HTTP response: ' . $method . ' ' . $url);
    }

    $statusCandidates = array_values(array_filter(
        $responseHeaders,
        static fn(string $line): bool => str_starts_with($line, 'HTTP/')
    ));
    $statusLine = $statusCandidates === [] ? 'HTTP/1.1 0' : end($statusCandidates);
    preg_match('/\s(\d{3})\s?/', $statusLine, $matches);
    $status = isset($matches[1]) ? (int)$matches[1] : 0;

    $headersLower = [];
    foreach (array_slice($responseHeaders, 1) as $headerLine) {
        $parts = explode(':', $headerLine, 2);
        if (count($parts) === 2) {
            $headersLower[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }

    return [
        'status' => $status,
        'headers' => $headersLower,
        'body' => $responseBody === false ? '' : $responseBody,
    ];
}

function canonicalUri(string $path): string
{
    $segments = explode('/', $path);
    $encoded = array_map(static fn(string $segment): string => awsEncode(rawurldecode($segment)), $segments);
    $result = implode('/', $encoded);

    return str_starts_with($result, '/') ? $result : '/' . $result;
}

function canonicalQuery(array $query): string
{
    $pairs = [];
    foreach ($query as $key => $value) {
        $pairs[] = [awsEncode((string)$key), awsEncode((string)$value)];
    }

    usort($pairs, static fn(array $a, array $b): int => $a[0] === $b[0] ? strcmp($a[1], $b[1]) : strcmp($a[0], $b[0]));

    return implode('&', array_map(static fn(array $pair): string => $pair[0] . '=' . $pair[1], $pairs));
}

function canonicalHeaders(array $headers, array $signedHeaderNames): string
{
    $lines = '';
    foreach ($signedHeaderNames as $name) {
        $lines .= $name . ':' . preg_replace('/\s+/', ' ', trim((string)$headers[$name])) . "\n";
    }

    return $lines;
}

function signatureKey(string $secret, string $date, string $region, string $service): string
{
    $kDate = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);

    return hash_hmac('sha256', 'aws4_request', $kService, true);
}

function buildQuery(array $query): string
{
    return implode('&', array_map(
        static fn(string $key, string $value): string => rawurlencode($key) . '=' . rawurlencode($value),
        array_keys($query),
        array_map('strval', array_values($query))
    ));
}

function awsEncode(string $value): string
{
    return str_replace('%7E', '~', rawurlencode($value));
}

function envOrArg(string $name, string $default): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function envFlag(string $name, bool $default): bool
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function assertResponse(array $response, int $expectedStatus, string $label): void
{
    if ($response['status'] !== $expectedStatus) {
        fail($label . ' failed: expected HTTP ' . $expectedStatus . ', got HTTP ' . $response['status'] . "\n" . $response['body']);
    }

    println('[ok] ' . $label . ' -> ' . $expectedStatus);
}

function assertContains(string $haystack, string $needle, string $label): void
{
    if (!str_contains($haystack, $needle)) {
        fail($label . ' failed: did not find expected text `' . $needle . '`');
    }

    println('[ok] ' . $label);
}

function assertSameText(string $expected, string $actual, string $label): void
{
    if ($expected !== $actual) {
        fail($label . ' failed: response body did not match payload');
    }

    println('[ok] ' . $label);
}

function assertHeaderPresent(array $response, string $headerName, string $label): void
{
    if (!isset($response['headers'][strtolower($headerName)])) {
        fail($label . ' failed: missing header ' . $headerName);
    }

    println('[ok] ' . $label);
}

function println(string $message): void
{
    echo $message . PHP_EOL;
}

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
