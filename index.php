<?php
/**
 * Single-file S3-compatible storage API
 *
 * Supported:
 * - AWS Signature Version 4 authentication
 * - Path-style endpoint: /bucket/key
 * - List all buckets: GET /
 * - Create bucket: PUT /bucket
 * - Delete empty bucket: DELETE /bucket
 * - List objects V2: GET /bucket?list-type=2
 * - Put object: PUT /bucket/key
 * - Copy object: PUT /bucket/key with x-amz-copy-source
 * - Get object: GET /bucket/key
 * - Head object: HEAD /bucket/key
 * - Delete object: DELETE /bucket/key
 * - CORS preflight: OPTIONS
 * - Basic presigned URL support
 * - Multipart upload: POST /bucket/key?uploads (initiate)
 * - Upload part: PUT /bucket/key?partNumber=N&uploadId=X
 * - Complete multipart: POST /bucket/key?uploadId=X
 * - Abort multipart: DELETE /bucket/key?uploadId=X
 * - List parts: GET /bucket/key?uploadId=X
 *
 * Not supported:
 * - Object ACLs (stub only)
 * - Bucket policies
 * - Versioning
 * - Range requests
 * - Real S3 regions beyond signature compatibility
 * - Multipart copy
 *
 * Recommended Laravel config:
 *
 * 'my_s3' => [
 *     'driver' => 's3',
 *     'key' => env('MY_S3_KEY'),
 *     'secret' => env('MY_S3_SECRET'),
 *     'region' => 'us-east-1',
 *     'bucket' => env('MY_S3_BUCKET'),
 *     'endpoint' => env('MY_S3_ENDPOINT'),
 *     'use_path_style_endpoint' => true,
 *     'throw' => false,
 * ],
 */

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/

define('S3_ACCESS_KEY', envValue('S3_ACCESS_KEY', 'change-me'));
define('S3_SECRET_KEY', envValue('S3_SECRET_KEY', 'change-me'));
define('S3_REGION', envValue('S3_REGION', 'us-east-1'));
define('S3_SERVICE', envValue('S3_SERVICE', 's3'));

define('STORAGE_ROOT', envValue('S3_STORAGE_ROOT', __DIR__ . '/storage'));
define('MAX_UPLOAD_BYTES', (int)envValue('S3_MAX_UPLOAD_BYTES', '536870912')); // 512 MB

define('REQUIRE_AUTH', envFlag('S3_REQUIRE_AUTH', true));
define('ALLOW_UNSIGNED_GET', envFlag('S3_ALLOW_UNSIGNED_GET', false));

define('SERVER_NAME', envValue('S3_SERVER_NAME', 'PHP Single File S3'));
define('DEBUG_LOG_ENABLED', envFlag('S3_DEBUG_LOG_ENABLED', false));

/*
|--------------------------------------------------------------------------
| Bootstrap
|--------------------------------------------------------------------------
*/

date_default_timezone_set('UTC');

function debugLog(string $message): void
{
    if (!defined('DEBUG_LOG_ENABLED') || !DEBUG_LOG_ENABLED) {
        return;
    }

    @file_put_contents(
        __DIR__ . '/s3-debug.log',
        '[' . gmdate('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND
    );
}

debugLog('METHOD=' . ($_SERVER['REQUEST_METHOD'] ?? ''));
debugLog('URI=' . ($_SERVER['REQUEST_URI'] ?? ''));
debugLog('QUERY=' . ($_SERVER['QUERY_STRING'] ?? ''));
debugLog('CONTENT_LENGTH=' . ($_SERVER['CONTENT_LENGTH'] ?? ''));
debugLog('HTTP_AUTHORIZATION=' . (isset($_SERVER['HTTP_AUTHORIZATION']) ? 'yes' : 'no'));
debugLog('REDIRECT_HTTP_AUTHORIZATION=' . (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) ? 'yes' : 'no'));

if (!is_dir(STORAGE_ROOT)) {
    mkdir(STORAGE_ROOT, 0775, true);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$query = [];
parse_str($queryString, $query);

sendCommonHeaders();

try {
    if ($method === 'OPTIONS') {
        respondToOptions();
    }

    if (REQUIRE_AUTH && !(ALLOW_UNSIGNED_GET && in_array($method, ['GET', 'HEAD'], true))) {
        authenticateRequest($method, $uriPath, $query);
    }

    routeRequest($method, $uriPath, $query);
} catch (S3Error $e) {
    s3Error($e->codeName, $e->message, $e->httpStatus);
} catch (Throwable $e) {
    s3Error('InternalError', 'Internal server error', 500);
}

/*
|--------------------------------------------------------------------------
| Router
|--------------------------------------------------------------------------
*/

function routeRequest(string $method, string $uriPath, array $query): void
{
    [$bucket, $key] = parseBucketAndKey($uriPath);

    debugLog('ROUTE method=' . $method . ' bucket=' . ($bucket ?? 'NULL') . ' key=' . $key);

    if ($bucket === null) {
        if ($method === 'GET') {
            listBuckets();
            exit;
        }

        s3Error('MethodNotAllowed', 'Method not allowed', 405);
    }

    if (!isValidBucketName($bucket)) {
        s3Error('InvalidBucketName', 'The specified bucket is not valid', 400);
    }

    if ($key === '') {
        if ($method === 'PUT') {
            createBucket($bucket);
            exit;
        }

        if ($method === 'DELETE') {
            deleteBucket($bucket);
            exit;
        }

        if ($method === 'GET') {
            listObjectsV2($bucket, $query);
            exit;
        }

        if ($method === 'HEAD') {
            headBucket($bucket);
            exit;
        }

        s3Error('MethodNotAllowed', 'Method not allowed for bucket root', 405);
    }

    if (!isValidObjectKey($key)) {
        s3Error('InvalidObjectName', 'The specified key is not valid', 400);
    }

    if (isAclQuery($query)) {
        if ($method === 'GET') {
            getObjectAcl($bucket, $key);
            exit;
        }

        if ($method === 'PUT') {
            putObjectAcl($bucket, $key);
            exit;
        }

        s3Error('MethodNotAllowed', 'Method not allowed for object ACL', 405);
    }

    if (isMultipartInitiateQuery($query)) {
        if ($method === 'POST') {
            createMultipartUpload($bucket, $key);
            exit;
        }

        s3Error('MethodNotAllowed', 'Method not allowed for multipart initiate', 405);
    }

    if (isMultipartUploadPartQuery($query)) {
        if ($method === 'PUT') {
            uploadPart($bucket, $key, $query['uploadId'], (int)$query['partNumber']);
            exit;
        }

        s3Error('MethodNotAllowed', 'Method not allowed for multipart upload part', 405);
    }

    if (isMultipartManagementQuery($query)) {
        if ($method === 'POST') {
            completeMultipartUpload($bucket, $key, $query['uploadId']);
            exit;
        }

        if ($method === 'DELETE') {
            abortMultipartUpload($bucket, $key, $query['uploadId']);
            exit;
        }

        if ($method === 'GET') {
            listParts($bucket, $key, $query['uploadId'], $query);
            exit;
        }

        s3Error('MethodNotAllowed', 'Method not allowed for multipart management', 405);
    }

    if ($method === 'PUT') {
        putObject($bucket, $key);
        exit;
    }

    if ($method === 'GET') {
        getObject($bucket, $key, false);
        exit;
    }

    if ($method === 'HEAD') {
        getObject($bucket, $key, true);
        exit;
    }

    if ($method === 'DELETE') {
        deleteObject($bucket, $key);
        exit;
    }

    s3Error('MethodNotAllowed', 'Method not allowed for object', 405);
}

/*
|--------------------------------------------------------------------------
| Bucket operations
|--------------------------------------------------------------------------
*/

function listBuckets(): void
{
    $buckets = [];

    foreach (glob(STORAGE_ROOT . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $name = basename($dir);

        if (!isValidBucketName($name)) {
            continue;
        }

        $buckets[] = [
            'name' => $name,
            'date' => gmdate('Y-m-d\TH:i:s.000\Z', filemtime($dir) ?: time()),
        ];
    }

    header('Content-Type: application/xml');

    echo xmlHeader();
    echo '<ListAllMyBucketsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
    echo '<Owner><ID>local</ID><DisplayName>local</DisplayName></Owner>';
    echo '<Buckets>';

    foreach ($buckets as $bucket) {
        echo '<Bucket>';
        echo '<Name>' . xmlEscape($bucket['name']) . '</Name>';
        echo '<CreationDate>' . xmlEscape($bucket['date']) . '</CreationDate>';
        echo '</Bucket>';
    }

    echo '</Buckets>';
    echo '</ListAllMyBucketsResult>';
}

function createBucket(string $bucket): void
{
    $path = bucketPath($bucket);

    if (is_dir($path)) {
        s3Error('BucketAlreadyOwnedByYou', 'The bucket you tried to create already exists', 409);
    }

    if (!is_dir($path) && !mkdir($path, 0775, true)) {
        s3Error('InternalError', 'Could not create bucket', 500);
    }

    http_response_code(200);
    header('Content-Length: 0');
    exit;
}

function deleteBucket(string $bucket): void
{
    $path = bucketPath($bucket);

    if (!is_dir($path)) {
        s3Error('NoSuchBucket', 'The specified bucket does not exist', 404);
    }

    $items = array_diff(scandir($path) ?: [], ['.', '..']);

    if (count($items) > 0) {
        s3Error('BucketNotEmpty', 'The bucket you tried to delete is not empty', 409);
    }

    if (!rmdir($path)) {
        s3Error('InternalError', 'Could not delete bucket', 500);
    }

    http_response_code(204);
}

function headBucket(string $bucket): void
{
    if (!is_dir(bucketPath($bucket))) {
        s3Error('NoSuchBucket', 'The specified bucket does not exist', 404);
    }

    http_response_code(200);
    header('Content-Length: 0');
    exit;
}

function listObjectsV2(string $bucket, array $query): void
{
    $bucketPath = bucketPath($bucket);

    if (!is_dir($bucketPath)) {
        s3Error('NoSuchBucket', 'The specified bucket does not exist', 404);
    }

    $prefix = rawurldecode((string)($query['prefix'] ?? ''));
    $delimiter = rawurldecode((string)($query['delimiter'] ?? ''));
    $maxKeys = max(1, min(1000, (int)($query['max-keys'] ?? 1000)));
    $continuationToken = (string)($query['continuation-token'] ?? '');

    if (!isValidObjectKey($prefix, true)) {
        s3Error('InvalidArgument', 'Invalid prefix', 400);
    }

    $all = [];
    $commonPrefixes = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($bucketPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($bucketPath) + 1));

        if ($prefix !== '' && !str_starts_with($relative, $prefix)) {
            continue;
        }

        if ($delimiter !== '') {
            $rest = substr($relative, strlen($prefix));
            $pos = strpos($rest, $delimiter);

            if ($pos !== false) {
                $commonPrefixes[$prefix . substr($rest, 0, $pos + strlen($delimiter))] = true;
                continue;
            }
        }

        $all[] = $relative;
    }

    sort($all, SORT_STRING);
    $commonPrefixKeys = array_keys($commonPrefixes);
    sort($commonPrefixKeys, SORT_STRING);

    $start = 0;

    if ($continuationToken !== '') {
        $decoded = base64_decode($continuationToken, true);
        if ($decoded !== false && ctype_digit($decoded)) {
            $start = (int)$decoded;
        }
    }

    $slice = array_slice($all, $start, $maxKeys);
    $nextIndex = $start + count($slice);
    $isTruncated = $nextIndex < count($all);
    $nextToken = $isTruncated ? base64_encode((string)$nextIndex) : '';

    header('Content-Type: application/xml');

    echo xmlHeader();
    echo '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
    echo '<Name>' . xmlEscape($bucket) . '</Name>';
    echo '<Prefix>' . xmlEscape($prefix) . '</Prefix>';
    echo '<KeyCount>' . count($slice) . '</KeyCount>';
    echo '<MaxKeys>' . $maxKeys . '</MaxKeys>';
    echo '<IsTruncated>' . ($isTruncated ? 'true' : 'false') . '</IsTruncated>';

    if ($continuationToken !== '') {
        echo '<ContinuationToken>' . xmlEscape($continuationToken) . '</ContinuationToken>';
    }

    if ($nextToken !== '') {
        echo '<NextContinuationToken>' . xmlEscape($nextToken) . '</NextContinuationToken>';
    }

    foreach ($slice as $key) {
        $file = objectPath($bucket, $key);
        echo '<Contents>';
        if (($query['encoding-type'] ?? '') === 'url') {
    echo '<Key>' . xmlEscape(rawurlencode($key)) . '</Key>';
} else {
    echo '<Key>' . xmlEscape($key) . '</Key>';
}
        echo '<LastModified>' . gmdate('Y-m-d\TH:i:s.000\Z', filemtime($file) ?: time()) . '</LastModified>';
        echo '<ETag>"' . md5_file($file) . '"</ETag>';
        echo '<Size>' . filesize($file) . '</Size>';
        echo '<StorageClass>STANDARD</StorageClass>';
        echo '</Contents>';
    }

    foreach ($commonPrefixKeys as $cp) {
        echo '<CommonPrefixes><Prefix>' . xmlEscape($cp) . '</Prefix></CommonPrefixes>';
    }

    echo '</ListBucketResult>';
}

/*
|--------------------------------------------------------------------------
| Object operations
|--------------------------------------------------------------------------
*/

function putObject(string $bucket, string $key): void
{
    $headers = getHeadersLower();
    $copySource = $headers['x-amz-copy-source'] ?? '';

    if ($copySource !== '') {
        copyObject($bucket, $key, $copySource);
        return;
    }

    $bucketPath = bucketPath($bucket);

    if (!is_dir($bucketPath)) {
        s3Error('NoSuchBucket', 'The specified bucket does not exist', 404);
    }

    $contentEncoding = $headers['content-encoding'] ?? '';
    $isAwsChunked = str_contains($contentEncoding, 'aws-chunked');

    if ($isAwsChunked) {
        $contentLength = (int)($headers['x-amz-decoded-content-length'] ?? 0);
    } else {
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    }

    if ($contentLength > MAX_UPLOAD_BYTES) {
        s3Error('EntityTooLarge', 'Your proposed upload exceeds the maximum allowed size', 400);
    }

    $filePath = objectPath($bucket, $key);
    $dir = dirname($filePath);

    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        s3Error('InternalError', 'Could not create object directory', 500);
    }

    $input = fopen('php://input', 'rb');
    $output = fopen($filePath, 'wb');

    if (!$input || !$output) {
        s3Error('InternalError', 'Could not open file stream', 500);
    }

    if ($isAwsChunked) {
        $bytes = readAwsChunkedBody($input, $output, MAX_UPLOAD_BYTES);

        if ($contentLength > 0 && $bytes !== $contentLength) {
            fclose($input);
            fclose($output);
            @unlink($filePath);
            s3Error('IncompleteBody', 'The actual uploaded size does not match the specified decoded content length', 400);
        }
    } else {
        $bytes = 0;

        while (!feof($input)) {
            $chunk = fread($input, 1024 * 1024);

            if ($chunk === false) {
                fclose($input);
                fclose($output);
                @unlink($filePath);
                s3Error('InternalError', 'Could not read input stream', 500);
            }

            $bytes += strlen($chunk);

            if ($bytes > MAX_UPLOAD_BYTES) {
                fclose($input);
                fclose($output);
                @unlink($filePath);
                s3Error('EntityTooLarge', 'Your proposed upload exceeds the maximum allowed size', 400);
            }

            fwrite($output, $chunk);
        }
    }

    fclose($input);
    fclose($output);

    $etag = md5_file($filePath);

    debugLog('PUT_OK bucket=' . $bucket . ' key=' . $key . ' bytes=' . $bytes . ' etag=' . $etag);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(200);
    header('ETag: "' . $etag . '"');
    header('Content-Length: 0');

    exit;
}

function copyObject(string $destinationBucket, string $destinationKey, string $copySource): void
{
    ensureBucketExists($destinationBucket);

    [$sourceBucket, $sourceKey] = parseCopySource($copySource);

    if (!isValidBucketName($sourceBucket)) {
        s3Error('InvalidBucketName', 'The specified bucket is not valid', 400);
    }

    if (!isValidObjectKey($sourceKey)) {
        s3Error('InvalidArgument', 'Invalid copy source key', 400);
    }

    ensureBucketExists($sourceBucket);

    $sourcePath = objectPath($sourceBucket, $sourceKey);

    if (!is_file($sourcePath)) {
        s3Error('NoSuchKey', 'The specified key does not exist', 404);
    }

    $destinationPath = objectPath($destinationBucket, $destinationKey);
    $destinationDir = dirname($destinationPath);

    if (!is_dir($destinationDir) && !mkdir($destinationDir, 0775, true)) {
        s3Error('InternalError', 'Could not create object directory', 500);
    }

    if (!@copy($sourcePath, $destinationPath)) {
        s3Error('InternalError', 'Could not copy object', 500);
    }

    $etag = md5_file($destinationPath);
    $lastModified = gmdate('Y-m-d\TH:i:s.000\Z', filemtime($destinationPath) ?: time());

    debugLog(
        'COPY_OK src_bucket=' . $sourceBucket
        . ' src_key=' . $sourceKey
        . ' dst_bucket=' . $destinationBucket
        . ' dst_key=' . $destinationKey
        . ' etag=' . $etag
    );

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(200);
    header('Content-Type: application/xml');
    header('ETag: "' . $etag . '"');

    $body = xmlHeader()
        . '<CopyObjectResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<LastModified>' . xmlEscape($lastModified) . '</LastModified>'
        . '<ETag>"' . xmlEscape($etag) . '"</ETag>'
        . '</CopyObjectResult>';

    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

function getObjectAcl(string $bucket, string $key): void
{
    ensureBucketExists($bucket);

    if (!is_file(objectPath($bucket, $key))) {
        s3Error('NoSuchKey', 'The specified key does not exist', 404);
    }

    $body = xmlHeader()
        . '<AccessControlPolicy xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Owner>'
        . '<ID>local</ID>'
        . '<DisplayName>local</DisplayName>'
        . '</Owner>'
        . '<AccessControlList>'
        . '<Grant>'
        . '<Grantee xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:type="CanonicalUser">'
        . '<ID>local</ID>'
        . '<DisplayName>local</DisplayName>'
        . '</Grantee>'
        . '<Permission>FULL_CONTROL</Permission>'
        . '</Grant>'
        . '</AccessControlList>'
        . '</AccessControlPolicy>';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(200);
    header('Content-Type: application/xml');
    header('Content-Length: ' . strlen($body));

    echo $body;
    exit;
}

function putObjectAcl(string $bucket, string $key): void
{
    ensureBucketExists($bucket);

    if (!is_file(objectPath($bucket, $key))) {
        s3Error('NoSuchKey', 'The specified key does not exist', 404);
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(200);
    header('Content-Length: 0');
    exit;
}

function getObject(string $bucket, string $key, bool $headOnly): void
{
    ensureBucketExists($bucket);

    $filePath = objectPath($bucket, $key);

    if (!is_file($filePath)) {
        s3Error('NoSuchKey', 'The specified key does not exist', 404);
    }

    $mime = mime_content_type($filePath) ?: 'application/octet-stream';
    $etag = md5_file($filePath);
    $size = filesize($filePath);

    http_response_code(200);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('ETag: "' . $etag . '"');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($filePath) ?: time()) . ' GMT');
    header('Accept-Ranges: none');

    if (!$headOnly) {
        readfile($filePath);
    }
}

function deleteObject(string $bucket, string $key): void
{
    ensureBucketExists($bucket);

    $filePath = objectPath($bucket, $key);

    if (is_file($filePath)) {
        unlink($filePath);
        cleanupEmptyDirs(dirname($filePath), bucketPath($bucket));
    }

    http_response_code(204);
}

/*
|--------------------------------------------------------------------------
| Multipart upload operations
|--------------------------------------------------------------------------
*/

function createMultipartUpload(string $bucket, string $key): void
{
    ensureBucketExists($bucket);

    $uploadId = generateUploadId();
    $partsPath = multipartPartsPath($uploadId);

    if (!is_dir($partsPath) && !mkdir($partsPath, 0775, true)) {
        s3Error('InternalError', 'Could not create multipart upload directory', 500);
    }

    $info = [
        'bucket' => $bucket,
        'key' => $key,
        'contentType' => $_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream',
        'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z'),
        'parts' => (object)[],
    ];

    multipartSaveInfo($uploadId, $info);

    debugLog('MULTIPART_INIT uploadId=' . $uploadId . ' bucket=' . $bucket . ' key=' . $key);

    $body = xmlHeader()
        . '<InitiateMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Bucket>' . xmlEscape($bucket) . '</Bucket>'
        . '<Key>' . xmlEscape($key) . '</Key>'
        . '<UploadId>' . xmlEscape($uploadId) . '</UploadId>'
        . '</InitiateMultipartUploadResult>';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(200);
    header('Content-Type: application/xml');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

function uploadPart(string $bucket, string $key, string $uploadId, int $partNumber): void
{
    validateMultipartUploadId($uploadId);

    $info = multipartInfo($uploadId);

    if ($info['bucket'] !== $bucket || $info['key'] !== $key) {
        s3Error('NoSuchUpload', 'The specified upload does not match the bucket and key', 404);
    }

    if ($partNumber < 1 || $partNumber > 10000) {
        s3Error('InvalidArgument', 'Part number must be between 1 and 10000', 400);
    }

    $partsPath = multipartPartsPath($uploadId);
    $partFile = $partsPath . '/' . sprintf('%05d', $partNumber);

    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);

    if ($contentLength > MAX_UPLOAD_BYTES) {
        s3Error('EntityTooLarge', 'Your proposed upload exceeds the maximum allowed size', 400);
    }

    $input = fopen('php://input', 'rb');
    $output = fopen($partFile, 'wb');

    if (!$input || !$output) {
        s3Error('InternalError', 'Could not open file stream', 500);
    }

    $bytes = 0;

    while (!feof($input)) {
        $chunk = fread($input, 1024 * 1024);

        if ($chunk === false) {
            fclose($input);
            fclose($output);
            @unlink($partFile);
            s3Error('InternalError', 'Could not read input stream', 500);
        }

        $bytes += strlen($chunk);

        if ($bytes > MAX_UPLOAD_BYTES) {
            fclose($input);
            fclose($output);
            @unlink($partFile);
            s3Error('EntityTooLarge', 'Your proposed upload exceeds the maximum allowed size', 400);
        }

        if (fwrite($output, $chunk) === false) {
            fclose($input);
            fclose($output);
            @unlink($partFile);
            s3Error('InternalError', 'Could not write part data', 500);
        }
    }

    fclose($input);
    fclose($output);

    $etag = md5_file($partFile);
    $partKey = (string)$partNumber;

    multipartUpdatePart($uploadId, $partKey, $bytes, $etag);

    debugLog('MULTIPART_PART uploadId=' . $uploadId . ' part=' . $partNumber . ' bytes=' . $bytes . ' etag=' . $etag);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(200);
    header('ETag: "' . $etag . '"');
    header('Content-Length: 0');
    exit;
}

function completeMultipartUpload(string $bucket, string $key, string $uploadId): void
{
    validateMultipartUploadId($uploadId);

    $info = multipartInfo($uploadId);

    if ($info['bucket'] !== $bucket || $info['key'] !== $key) {
        s3Error('NoSuchUpload', 'The specified upload does not match the bucket and key', 404);
    }

    ensureBucketExists($bucket);

    $body = file_get_contents('php://input');

    if ($body === false) {
        s3Error('InternalError', 'Could not read complete request body', 500);
    }

    $submittedParts = parseCompleteMultipartXml($body);
    $storedParts = (array)($info['parts'] ?? []);
    $targetParts = [];
    $prevPartNumber = 0;

    foreach ($submittedParts as $submitted) {
        $pn = $submitted['PartNumber'];
        $submittedEtag = strtolower(trim($submitted['ETag'], '" '));

        if ($pn <= $prevPartNumber) {
            s3Error('InvalidPartOrder', 'The list of parts was not in ascending order', 400);
        }

        $prevPartNumber = $pn;
        $pnStr = (string)$pn;

        if (!isset($storedParts[$pnStr])) {
            s3Error('InvalidPart', 'One or more of the specified parts could not be found', 400);
        }

        $storedEtag = strtolower($storedParts[$pnStr]['etag']);

        if ($submittedEtag !== $storedEtag) {
            s3Error('InvalidPart', 'One or more of the specified parts have an incorrect ETag', 400);
        }

        $targetParts[$pn] = $pnStr;
    }

    if (count($targetParts) === 0) {
        abortMultipartUpload($bucket, $key, $uploadId);
        s3Error('InvalidRequest', 'No parts specified in complete request', 400);
    }

    $filePath = objectPath($bucket, $key);
    $dir = dirname($filePath);

    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        s3Error('InternalError', 'Could not create object directory', 500);
    }

    $output = fopen($filePath, 'wb');

    if (!$output) {
        s3Error('InternalError', 'Could not create object file', 500);
    }

    $totalBytes = 0;
    $partsPath = multipartPartsPath($uploadId);

    foreach ($targetParts as $partKey) {
        $partFile = $partsPath . '/' . sprintf('%05d', (int)$partKey);

        if (!is_file($partFile)) {
            fclose($output);
            @unlink($filePath);
            s3Error('InvalidPart', 'Part data not found on disk', 400);
        }

        $input = fopen($partFile, 'rb');

        if (!$input) {
            fclose($output);
            @unlink($filePath);
            s3Error('InternalError', 'Could not read part file', 500);
        }

        while (!feof($input)) {
            $chunk = fread($input, 1024 * 1024);

            if ($chunk === false) {
                fclose($input);
                fclose($output);
                @unlink($filePath);
                s3Error('InternalError', 'Could not read part data', 500);
            }

            $totalBytes += strlen($chunk);

            if ($totalBytes > MAX_UPLOAD_BYTES) {
                fclose($input);
                fclose($output);
                @unlink($filePath);
                s3Error('EntityTooLarge', 'Your proposed upload exceeds the maximum allowed size', 400);
            }

            if (fwrite($output, $chunk) === false) {
                fclose($input);
                fclose($output);
                @unlink($filePath);
                s3Error('InternalError', 'Could not write object data', 500);
            }
        }

        fclose($input);
    }

    fclose($output);

    $etag = md5_file($filePath);
    $lastModified = gmdate('Y-m-d\TH:i:s.000\Z', filemtime($filePath) ?: time());

    cleanupMultipartDir($uploadId);

    debugLog('MULTIPART_COMPLETE uploadId=' . $uploadId . ' bucket=' . $bucket . ' key=' . $key . ' bytes=' . $totalBytes . ' etag=' . $etag);

    $body = xmlHeader()
        . '<CompleteMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Location>' . xmlEscape('/' . $bucket . '/' . $key) . '</Location>'
        . '<Bucket>' . xmlEscape($bucket) . '</Bucket>'
        . '<Key>' . xmlEscape($key) . '</Key>'
        . '<ETag>"' . xmlEscape($etag) . '"</ETag>'
        . '</CompleteMultipartUploadResult>';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(200);
    header('Content-Type: application/xml');
    header('Content-Length: ' . strlen($body));
    header('ETag: "' . $etag . '"');
    echo $body;
    exit;
}

function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
{
    validateMultipartUploadId($uploadId);

    $info = multipartInfo($uploadId);

    if ($info['bucket'] !== $bucket || $info['key'] !== $key) {
        s3Error('NoSuchUpload', 'The specified upload does not match the bucket and key', 404);
    }

    cleanupMultipartDir($uploadId);

    debugLog('MULTIPART_ABORT uploadId=' . $uploadId . ' bucket=' . $bucket . ' key=' . $key);

    http_response_code(204);
    exit;
}

function listParts(string $bucket, string $key, string $uploadId, array $query): void
{
    validateMultipartUploadId($uploadId);

    $info = multipartInfo($uploadId);

    if ($info['bucket'] !== $bucket || $info['key'] !== $key) {
        s3Error('NoSuchUpload', 'The specified upload does not match the bucket and key', 404);
    }

    $maxParts = max(1, min(1000, (int)($query['max-parts'] ?? 1000)));
    $partNumberMarker = max(0, (int)($query['part-number-marker'] ?? 0));

    $storedParts = (array)($info['parts'] ?? []);
    ksort($storedParts, SORT_NUMERIC);

    $filteredParts = [];

    foreach ($storedParts as $partNum => $partData) {
        if ((int)$partNum > $partNumberMarker) {
            $filteredParts[$partNum] = $partData;
        }
    }

    $partsSlice = array_slice($filteredParts, 0, $maxParts, true);
    $isTruncated = count($partsSlice) < count($filteredParts);
    $nextPartNumberMarker = '';

    if ($isTruncated && count($partsSlice) > 0) {
        $keys = array_keys($partsSlice);
        $nextPartNumberMarker = (string)end($keys);
    }

    $result = xmlHeader()
        . '<ListPartsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
        . '<Bucket>' . xmlEscape($bucket) . '</Bucket>'
        . '<Key>' . xmlEscape($key) . '</Key>'
        . '<UploadId>' . xmlEscape($uploadId) . '</UploadId>'
        . '<StorageClass>STANDARD</StorageClass>'
        . '<PartNumberMarker>' . $partNumberMarker . '</PartNumberMarker>'
        . '<NextPartNumberMarker>' . xmlEscape($nextPartNumberMarker) . '</NextPartNumberMarker>'
        . '<MaxParts>' . $maxParts . '</MaxParts>'
        . '<IsTruncated>' . ($isTruncated ? 'true' : 'false') . '</IsTruncated>';

    foreach ($partsSlice as $partNum => $partData) {
        $result .= '<Part>'
            . '<PartNumber>' . xmlEscape((string)$partNum) . '</PartNumber>'
            . '<LastModified>' . xmlEscape($info['createdAt'] ?? gmdate('Y-m-d\TH:i:s.000\Z')) . '</LastModified>'
            . '<ETag>"' . xmlEscape($partData['etag'] ?? '') . '"</ETag>'
            . '<Size>' . (int)($partData['size'] ?? 0) . '</Size>'
            . '</Part>';
    }

    $result .= '</ListPartsResult>';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(200);
    header('Content-Type: application/xml');
    header('Content-Length: ' . strlen($result));
    echo $result;
    exit;
}

/*
|--------------------------------------------------------------------------
| AWS Signature Version 4
|--------------------------------------------------------------------------
*/

function authenticateRequest(string $method, string $uriPath, array $query): void
{
    if (isset($query['X-Amz-Signature'])) {
        authenticatePresignedUrl($method, $uriPath, $query);
        return;
    }

    authenticateAuthorizationHeader($method, $uriPath, $query);
}

function authenticateAuthorizationHeader(string $method, string $uriPath, array $query): void
{
    $headers = getHeadersLower();
    $authorization = $headers['authorization'] ?? '';

    if ($authorization === '') {
        s3Error('AccessDenied', 'Missing Authorization header', 403);
    }

    if (!str_starts_with($authorization, 'AWS4-HMAC-SHA256 ')) {
        s3Error('InvalidRequest', 'Only AWS4-HMAC-SHA256 is supported', 400);
    }

    $authParts = parseAuthorizationHeader($authorization);

    $credential = $authParts['Credential'] ?? '';
    $signedHeaders = $authParts['SignedHeaders'] ?? '';
    $providedSignature = $authParts['Signature'] ?? '';

    if ($credential === '' || $signedHeaders === '' || $providedSignature === '') {
        s3Error('AuthorizationHeaderMalformed', 'Invalid Authorization header', 400);
    }

    $credentialParts = explode('/', $credential);

    if (count($credentialParts) !== 5) {
        s3Error('AuthorizationHeaderMalformed', 'Invalid credential scope', 400);
    }

    [$accessKey, $date, $region, $service, $terminal] = $credentialParts;

    if ($accessKey !== S3_ACCESS_KEY) {
        s3Error('InvalidAccessKeyId', 'The AWS Access Key Id you provided does not exist in our records', 403);
    }

    if ($region !== S3_REGION || $service !== S3_SERVICE || $terminal !== 'aws4_request') {
        s3Error('AuthorizationHeaderMalformed', 'Invalid credential scope', 400);
    }

    $amzDate = $headers['x-amz-date'] ?? '';

    if ($amzDate === '') {
        s3Error('AccessDenied', 'Missing x-amz-date header', 403);
    }

    validateAmzDate($amzDate);

    $payloadHash = $headers['x-amz-content-sha256'] ?? hashPayloadFromInput();

    $canonicalRequest = buildCanonicalRequest(
        $method,
        $uriPath,
        $query,
        $headers,
        explode(';', $signedHeaders),
        $payloadHash
    );

    $scope = $date . '/' . $region . '/' . $service . '/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
    $signingKey = getSignatureKey(S3_SECRET_KEY, $date, $region, $service);
    $calculatedSignature = hash_hmac('sha256', $stringToSign, $signingKey);

    if (!hash_equals($calculatedSignature, $providedSignature)) {
        s3Error('SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided', 403);
    }
}

function authenticatePresignedUrl(string $method, string $uriPath, array $query): void
{
    $headers = getHeadersLower();

    $algorithm = (string)($query['X-Amz-Algorithm'] ?? '');
    $credential = (string)($query['X-Amz-Credential'] ?? '');
    $amzDate = (string)($query['X-Amz-Date'] ?? '');
    $expires = (int)($query['X-Amz-Expires'] ?? 0);
    $signedHeaders = (string)($query['X-Amz-SignedHeaders'] ?? '');
    $providedSignature = (string)($query['X-Amz-Signature'] ?? '');

    if ($algorithm !== 'AWS4-HMAC-SHA256') {
        s3Error('InvalidRequest', 'Only AWS4-HMAC-SHA256 is supported', 400);
    }

    if ($credential === '' || $amzDate === '' || $expires <= 0 || $signedHeaders === '' || $providedSignature === '') {
        s3Error('AccessDenied', 'Invalid presigned URL', 403);
    }

    $requestTime = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $amzDate, new DateTimeZone('UTC'));

    if (!$requestTime) {
        s3Error('AccessDenied', 'Invalid X-Amz-Date', 403);
    }

    if (time() > ($requestTime->getTimestamp() + $expires)) {
        s3Error('AccessDenied', 'Request has expired', 403);
    }

    $credentialParts = explode('/', rawurldecode($credential));

    if (count($credentialParts) !== 5) {
        s3Error('AuthorizationHeaderMalformed', 'Invalid credential scope', 400);
    }

    [$accessKey, $date, $region, $service, $terminal] = $credentialParts;

    if ($accessKey !== S3_ACCESS_KEY) {
        s3Error('InvalidAccessKeyId', 'Invalid access key', 403);
    }

    if ($region !== S3_REGION || $service !== S3_SERVICE || $terminal !== 'aws4_request') {
        s3Error('AuthorizationHeaderMalformed', 'Invalid credential scope', 400);
    }

    $queryForCanonical = $query;
    unset($queryForCanonical['X-Amz-Signature']);

    $payloadHash = 'UNSIGNED-PAYLOAD';

    if (!isset($headers['host'])) {
        $headers['host'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    }

    $canonicalRequest = buildCanonicalRequest(
        $method,
        $uriPath,
        $queryForCanonical,
        $headers,
        explode(';', $signedHeaders),
        $payloadHash
    );

    $scope = $date . '/' . $region . '/' . $service . '/aws4_request';
    $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
    $signingKey = getSignatureKey(S3_SECRET_KEY, $date, $region, $service);
    $calculatedSignature = hash_hmac('sha256', $stringToSign, $signingKey);

    if (!hash_equals($calculatedSignature, $providedSignature)) {
        s3Error('SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided', 403);
    }
}

function parseAuthorizationHeader(string $authorization): array
{
    $authorization = substr($authorization, strlen('AWS4-HMAC-SHA256 '));
    $parts = array_map('trim', explode(',', $authorization));
    $result = [];

    foreach ($parts as $part) {
        [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
        $result[$key] = $value;
    }

    return $result;
}

function buildCanonicalRequest(
    string $method,
    string $uriPath,
    array $query,
    array $headers,
    array $signedHeaders,
    string $payloadHash
): string {
    $signedHeaders = array_values(array_filter(array_map('strtolower', array_map('trim', $signedHeaders))));
    sort($signedHeaders, SORT_STRING);

    $canonicalHeaders = '';

    foreach ($signedHeaders as $headerName) {
        if (!array_key_exists($headerName, $headers)) {
            s3Error('AccessDenied', 'Signed header missing: ' . $headerName, 403);
        }

        $canonicalHeaders .= $headerName . ':' . normalizeHeaderValue($headers[$headerName]) . "\n";
    }

    $canonicalSignedHeaders = implode(';', $signedHeaders);

    return implode("\n", [
        strtoupper($method),
        canonicalUri($uriPath),
        canonicalQueryString($query),
        $canonicalHeaders,
        $canonicalSignedHeaders,
        $payloadHash,
    ]);
}

function canonicalUri(string $path): string
{
    $path = parse_url($path, PHP_URL_PATH) ?: '/';

    if ($path === '') {
        return '/';
    }

    $segments = explode('/', $path);
    $encoded = array_map(static fn($segment) => awsPercentEncode(rawurldecode($segment)), $segments);

    $result = implode('/', $encoded);

    return str_starts_with($result, '/') ? $result : '/' . $result;
}

function canonicalQueryString(array $query): string
{
    $pairs = [];

    foreach ($query as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $v) {
                $pairs[] = [awsPercentEncode((string)$key), awsPercentEncode((string)$v)];
            }
        } else {
            $pairs[] = [awsPercentEncode((string)$key), awsPercentEncode((string)$value)];
        }
    }

    usort($pairs, static function ($a, $b) {
        return $a[0] === $b[0] ? strcmp($a[1], $b[1]) : strcmp($a[0], $b[0]);
    });

    return implode('&', array_map(static fn($p) => $p[0] . '=' . $p[1], $pairs));
}

function awsPercentEncode(string $value): string
{
    return str_replace('%7E', '~', rawurlencode($value));
}

function normalizeHeaderValue(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

function getSignatureKey(string $key, string $dateStamp, string $regionName, string $serviceName): string
{
    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $key, true);
    $kRegion = hash_hmac('sha256', $regionName, $kDate, true);
    $kService = hash_hmac('sha256', $serviceName, $kRegion, true);

    return hash_hmac('sha256', 'aws4_request', $kService, true);
}

function hashPayloadFromInput(): string
{
    return 'UNSIGNED-PAYLOAD';
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function parseBucketAndKey(string $uriPath): array
{
    $path = trim($uriPath, '/');

    if ($path === '') {
        return [null, ''];
    }

    $parts = explode('/', $path, 2);
    $bucket = rawurldecode($parts[0]);
    $key = isset($parts[1]) ? rawurldecode($parts[1]) : '';

    return [$bucket, $key];
}

function parseCopySource(string $copySource): array
{
    $copySource = trim($copySource);

    if ($copySource === '') {
        s3Error('InvalidArgument', 'Missing x-amz-copy-source header', 400);
    }

    $sourcePath = rawurldecode(parse_url($copySource, PHP_URL_PATH) ?: $copySource);
    $sourcePath = trim($sourcePath, '/');

    if ($sourcePath === '') {
        s3Error('InvalidArgument', 'Invalid x-amz-copy-source header', 400);
    }

    $parts = explode('/', $sourcePath, 2);
    $sourceBucket = $parts[0] ?? '';
    $sourceKey = $parts[1] ?? '';

    if ($sourceBucket === '' || $sourceKey === '') {
        s3Error('InvalidArgument', 'Invalid x-amz-copy-source header', 400);
    }

    return [$sourceBucket, $sourceKey];
}

function envValue(string $name, string $default): string
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

    $normalized = strtolower(trim($value));

    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function ensureBucketExists(string $bucket): void
{
    if (!is_dir(bucketPath($bucket))) {
        s3Error('NoSuchBucket', 'The specified bucket does not exist', 404);
    }
}

function validateAmzDate(string $amzDate): void
{
    $date = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $amzDate, new DateTimeZone('UTC'));

    if (!$date || $date->format('Ymd\THis\Z') !== $amzDate) {
        s3Error('AccessDenied', 'Invalid x-amz-date header', 403);
    }
}

function bucketPath(string $bucket): string
{
    return STORAGE_ROOT . '/' . $bucket;
}

function objectPath(string $bucket, string $key): string
{
    $base = realpath(STORAGE_ROOT) ?: STORAGE_ROOT;
    $path = bucketPath($bucket) . '/' . $key;

    $normalized = normalizePath($path);

    if (!str_starts_with($normalized, normalizePath($base . '/'))) {
        s3Error('AccessDenied', 'Invalid object path', 403);
    }

    return $normalized;
}

function normalizePath(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $parts = [];

    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }

        if ($part === '..') {
            array_pop($parts);
            continue;
        }

        $parts[] = $part;
    }

    $prefix = str_starts_with($path, '/') ? '/' : '';

    return $prefix . implode('/', $parts);
}

function isAclQuery(array $query): bool
{
    return array_key_exists('acl', $query);
}

function isMultipartInitiateQuery(array $query): bool
{
    return array_key_exists('uploads', $query);
}

function isMultipartUploadPartQuery(array $query): bool
{
    return isset($query['partNumber'], $query['uploadId']);
}

function isMultipartManagementQuery(array $query): bool
{
    return isset($query['uploadId']) && !isset($query['partNumber']);
}

function isValidBucketName(string $bucket): bool
{
    return (bool)preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket)
        && !str_contains($bucket, '..')
        && !preg_match('/^\d+\.\d+\.\d+\.\d+$/', $bucket);
}

function isValidObjectKey(string $key, bool $allowEmpty = false): bool
{
    if ($allowEmpty && $key === '') {
        return true;
    }

    if ($key === '' || strlen($key) > 1024) {
        return false;
    }

    if (str_contains($key, "\0") || str_contains($key, '../') || str_starts_with($key, '../')) {
        return false;
    }

    return true;
}

function cleanupEmptyDirs(string $dir, string $stopAt): void
{
    $dir = normalizePath($dir);
    $stopAt = normalizePath($stopAt);

    while ($dir !== $stopAt && str_starts_with($dir, $stopAt)) {
        $items = array_diff(scandir($dir) ?: [], ['.', '..']);

        if (count($items) > 0) {
            break;
        }

        @rmdir($dir);
        $dir = dirname($dir);
    }
}

function generateUploadId(): string
{
    return bin2hex(random_bytes(16));
}

function multipartPath(string $uploadId): string
{
    return STORAGE_ROOT . '/.multipart/' . $uploadId;
}

function multipartPartsPath(string $uploadId): string
{
    return multipartPath($uploadId) . '/parts';
}

function multipartInfoPath(string $uploadId): string
{
    return multipartPath($uploadId) . '/info.json';
}

function multipartInfo(string $uploadId): array
{
    $infoPath = multipartInfoPath($uploadId);

    if (!is_file($infoPath)) {
        s3Error('NoSuchUpload', 'The specified upload does not exist', 404);
    }

    $json = @file_get_contents($infoPath);

    if ($json === false || $json === '') {
        s3Error('InternalError', 'Could not read multipart upload info', 500);
    }

    $data = json_decode($json, true);

    if (!is_array($data) || !isset($data['bucket'], $data['key'])) {
        s3Error('InternalError', 'Invalid multipart upload info', 500);
    }

    return $data;
}

function multipartSaveInfo(string $uploadId, array $data): void
{
    $infoPath = multipartInfoPath($uploadId);
    $handle = @fopen($infoPath, 'wb');

    if (!$handle) {
        s3Error('InternalError', 'Could not create multipart upload info', 500);
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        s3Error('InternalError', 'Could not lock multipart upload info', 500);
    }

    fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function multipartUpdatePart(string $uploadId, string $partKey, int $bytes, string $etag): void
{
    $infoPath = multipartInfoPath($uploadId);
    $handle = @fopen($infoPath, 'rb+');

    if (!$handle) {
        s3Error('InternalError', 'Could not open multipart upload info for update', 500);
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        s3Error('InternalError', 'Could not lock multipart upload info', 500);
    }

    $content = stream_get_contents($handle);

    if ($content === false) {
        flock($handle, LOCK_UN);
        fclose($handle);
        s3Error('InternalError', 'Could not read multipart upload info', 500);
    }

    $data = json_decode($content, true);

    if (!is_array($data)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        s3Error('InternalError', 'Invalid multipart upload info', 500);
    }

    if (!isset($data['parts']) || !is_array($data['parts'])) {
        $data['parts'] = [];
    }

    $data['parts'][$partKey] = [
        'size' => $bytes,
        'etag' => $etag,
    ];

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function validateMultipartUploadId(string $uploadId): void
{
    if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
        s3Error('InvalidArgument', 'Invalid upload id', 400);
    }
}

function parseCompleteMultipartXml(string $xml): array
{
    if ($xml === '' || $xml === '0') {
        s3Error('MalformedXML', 'The XML you provided was not well-formed', 400);
    }

    libxml_use_internal_errors(true);
    $doc = @simplexml_load_string($xml);

    if ($doc === false) {
        $errors = libxml_get_errors();
        libxml_clear_errors();
        s3Error('MalformedXML', 'The XML you provided was not well-formed or does not match the schema', 400);
    }

    $parts = [];

    foreach ($doc->Part as $part) {
        $partNumber = (int)(string)$part->PartNumber;
        $etag = (string)$part->ETag;

        if ($partNumber < 1 || $partNumber > 10000 || $etag === '') {
            s3Error('MalformedXML', 'The XML you provided was not well-formed or does not match the schema', 400);
        }

        $parts[] = [
            'PartNumber' => $partNumber,
            'ETag' => $etag,
        ];
    }

    return $parts;
}

function cleanupMultipartDir(string $uploadId): void
{
    $path = multipartPath($uploadId);
    recursiveDelete($path);
}

function recursiveDelete(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = array_diff(scandir($dir) ?: [], ['.', '..']);

    foreach ($items as $item) {
        $itemPath = $dir . '/' . $item;

        if (is_dir($itemPath)) {
            recursiveDelete($itemPath);
        } else {
            @unlink($itemPath);
        }
    }

    @rmdir($dir);
}

function readAwsChunkedBody($input, $output, int $maxUploadBytes): int
{
    $totalBytes = 0;

    while (true) {
        $header = stream_get_line($input, 1024, "\n");
        if ($header === false) {
            break;
        }
        $header = rtrim($header, "\r");

        $semicolonPos = strpos($header, ';');
        $hexSize = $semicolonPos === false ? $header : substr($header, 0, $semicolonPos);
        $chunkSize = hexdec($hexSize);

        if ($chunkSize <= 0) {
            break;
        }

        $remaining = $chunkSize;
        while ($remaining > 0) {
            $toRead = min(65536, $remaining);
            $data = fread($input, $toRead);
            if ($data === false || $data === '') {
                s3Error('InternalError', 'Unexpected end of chunked stream', 500);
            }
            $len = strlen($data);
            fwrite($output, $data);
            $totalBytes += $len;
            $remaining -= $len;

            if ($totalBytes > $maxUploadBytes) {
                s3Error('EntityTooLarge', 'Your proposed upload exceeds the maximum allowed size', 400);
            }
        }

        $crlf = fread($input, 2);
    }

    while (true) {
        $line = stream_get_line($input, 4096, "\n");
        if ($line === false) {
            break;
        }
        $line = rtrim($line, "\r");
        if ($line === '') {
            break;
        }
    }

    return $totalBytes;
}

function getHeadersLower(): array
{
    $headers = [];

    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = (string)$value;
        }
    }

    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && !isset($headers['authorization'])) {
        $headers['authorization'] = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (isset($_SERVER['HTTP_AUTHORIZATION']) && !isset($headers['authorization'])) {
        $headers['authorization'] = (string)$_SERVER['HTTP_AUTHORIZATION'];
    }

    if (isset($_SERVER['Authorization']) && !isset($headers['authorization'])) {
        $headers['authorization'] = (string)$_SERVER['Authorization'];
    }

    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            $headers[strtolower($name)] = (string)$value;
        }
    }

    if (isset($_SERVER['CONTENT_TYPE'])) {
        $headers['content-type'] = (string)$_SERVER['CONTENT_TYPE'];
    }

    if (isset($_SERVER['CONTENT_LENGTH'])) {
        $headers['content-length'] = (string)$_SERVER['CONTENT_LENGTH'];
    }

    if (!isset($headers['host'])) {
        $headers['host'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    }

    return $headers;
}

function sendCommonHeaders(): void
{
    header('Server: ' . SERVER_NAME);
    header('Allow: GET, HEAD, PUT, DELETE, POST, OPTIONS');
    header('x-amz-request-id: ' . bin2hex(random_bytes(8)));
    header('x-amz-id-2: ' . bin2hex(random_bytes(16)));
}

function respondToOptions(): void
{
    http_response_code(204);
    header('Content-Length: 0');
    exit;
}

function xmlHeader(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>';
}

function xmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function s3Error(string $code, string $message, int $status): void
{
    debugLog('ERROR status=' . $status . ' code=' . $code . ' message=' . $message);

    $requestId = bin2hex(random_bytes(8));
    $resource = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    $body = xmlHeader()
        . '<Error>'
        . '<Code>' . xmlEscape($code) . '</Code>'
        . '<Message>' . xmlEscape($message) . '</Message>'
        . '<Resource>' . xmlEscape($resource) . '</Resource>'
        . '<RequestId>' . $requestId . '</RequestId>'
        . '<HostId>' . bin2hex(random_bytes(16)) . '</HostId>'
        . '</Error>';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header_remove('Content-Length');
    header_remove('Content-Type');

    http_response_code($status);
    header('Content-Type: application/xml');
    header('Content-Length: ' . strlen($body));

    echo $body;

    exit;
}

class S3Error extends Exception
{
    public string $codeName;
    public int $httpStatus;

    public function __construct(string $codeName, string $message, int $httpStatus)
    {
        parent::__construct($message);
        $this->codeName = $codeName;
        $this->httpStatus = $httpStatus;
    }
}
