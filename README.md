# Single-File S3-Compatible Storage API

This project is a lightweight S3-compatible storage server implemented in a single PHP file.

It is designed for people who want a very simple S3-compatible endpoint on shared hosting or self-hosted PHP/Apache setups without running MinIO, LocalStack, Docker, or a larger object storage stack.

The goal is not full Amazon S3 parity. The goal is a small, understandable, practical subset that works well for simple apps, uploads, downloads, and framework integrations.

## Who this is for

- Shared hosting users with PHP and Apache
- Self-hosters who want the smallest possible S3-like endpoint
- Developers who need a lightweight path-style S3 target for apps and internal tools
- PHP and Laravel projects that only need basic object storage operations

## Who this is not for

- Teams needing high-scale production object storage
- Workloads that require multipart upload
- Systems that depend on full AWS S3 feature parity
- Multi-node or replicated storage deployments

## What it does

- AWS Signature Version 4 authentication
- Path-style S3 endpoints (`/bucket/key`)
- Bucket listing
- Bucket creation
- Empty bucket deletion
- Object listing with `ListObjectsV2`
- Object upload
- Object download
- Object metadata with `HEAD`
- Object deletion
- CORS preflight with `OPTIONS`
- Basic presigned URL support
- File-backed storage on the local filesystem

## What it does not do

- Multipart uploads
- ACLs
- Bucket policies
- Versioning
- CopyObject
- Range requests
- Virtual-hosted-style bucket routing
- Advanced IAM or AWS account semantics

## Project structure

- `index.php` - the entire API implementation
- `.htaccess` - Apache routing, request limits, and basic hardening
- `apache-env.example` - example Apache environment configuration
- `smoke-test.php` - zero-dependency signed smoke test

Objects are stored on disk under `storage/<bucket>/<key>`.

## Requirements

- PHP 8.0 or newer
- Apache with `mod_rewrite`
- `.htaccess` support
- A writable project directory

## Configuration

The server reads configuration from environment variables.

| Variable | Default | Description |
| --- | --- | --- |
| `S3_ACCESS_KEY` | `change-me` | Access key accepted by the server |
| `S3_SECRET_KEY` | `change-me` | Secret key used for SigV4 validation |
| `S3_REGION` | `us-east-1` | Signature region |
| `S3_SERVICE` | `s3` | Signature service name |
| `S3_STORAGE_ROOT` | `./storage` | Local storage directory |
| `S3_MAX_UPLOAD_BYTES` | `536870912` | Max upload size in bytes |
| `S3_REQUIRE_AUTH` | `true` | Require signed requests |
| `S3_ALLOW_UNSIGNED_GET` | `false` | Allow unsigned `GET` and `HEAD` requests |
| `S3_SERVER_NAME` | `PHP Single File S3` | Value sent in the `Server` header |
| `S3_DEBUG_LOG_ENABLED` | `false` | Enable request logging to `s3-debug.log` |

You can use [`apache-env.example`](apache-env.example) as a starting point.

## Quick start

1. Place the project in an Apache-served directory.
2. Make sure `mod_rewrite` is enabled.
3. Make sure `.htaccess` files are allowed by your Apache or hosting setup.
4. Configure the environment variables.
5. Ensure the web server can write to the storage directory.
6. Prefer HTTPS if the endpoint will be reachable outside a trusted network.
7. Send S3-compatible requests to the project URL.

Example Apache environment values:

```apache
SetEnv S3_ACCESS_KEY your-access-key
SetEnv S3_SECRET_KEY your-secret-key
SetEnv S3_REGION us-east-1
SetEnv S3_SERVICE s3
SetEnv S3_STORAGE_ROOT /absolute/path/to/storage
SetEnv S3_MAX_UPLOAD_BYTES 536870912
SetEnv S3_REQUIRE_AUTH true
SetEnv S3_ALLOW_UNSIGNED_GET false
SetEnv S3_SERVER_NAME "PHP Single File S3"
SetEnv S3_DEBUG_LOG_ENABLED false
```

## Apache notes

This project expects Apache to provide:

- `mod_rewrite` so requests reach `index.php`
- `Authorization` forwarding so SigV4 headers reach PHP
- `.htaccess` support
- a writable storage directory

The included `.htaccess` also:

- blocks direct access to `storage/`
- blocks common sensitive files
- limits allowed HTTP methods
- sets a request body limit
- adds basic security headers
- exposes basic CORS headers

If `mod_rewrite` or `.htaccess` support is unavailable, path-style routing will not work correctly.

## Storage and permissions

Objects are stored directly on the local filesystem.

- Default storage path: `./storage`
- Each bucket becomes a directory
- Each object becomes a file inside its bucket path

Make sure:

- Apache/PHP can create and write inside `storage/`
- the storage path is not publicly browsable
- you keep enough free disk space for uploads
- debug logging stays disabled unless you are troubleshooting

## Example AWS CLI usage

Set your AWS CLI credentials to match the values configured in Apache.

Create a bucket:

```bash
aws s3api create-bucket \
  --endpoint-url http://localhost/s3SingleFile \
  --bucket demo-bucket
```

Upload a file:

```bash
aws s3 cp ./photo.jpg s3://demo-bucket/photo.jpg \
  --endpoint-url http://localhost/s3SingleFile
```

List objects:

```bash
aws s3api list-objects-v2 \
  --endpoint-url http://localhost/s3SingleFile \
  --bucket demo-bucket
```

Download a file:

```bash
aws s3 cp s3://demo-bucket/photo.jpg ./photo.jpg \
  --endpoint-url http://localhost/s3SingleFile
```

Head an object:

```bash
aws s3api head-object \
  --endpoint-url http://localhost/s3SingleFile \
  --bucket demo-bucket \
  --key photo.jpg
```

Delete an object:

```bash
aws s3api delete-object \
  --endpoint-url http://localhost/s3SingleFile \
  --bucket demo-bucket \
  --key photo.jpg
```

## PHP example usage

Using the AWS SDK for PHP:

```php
use Aws\S3\S3Client;

$client = new S3Client([
    'version' => 'latest',
    'region' => 'us-east-1',
    'endpoint' => 'http://localhost/s3SingleFile',
    'use_path_style_endpoint' => true,
    'credentials' => [
        'key' => 'your-access-key',
        'secret' => 'your-secret-key',
    ],
]);

$client->putObject([
    'Bucket' => 'demo-bucket',
    'Key' => 'hello.txt',
    'Body' => 'Hello from PHP',
    'ContentType' => 'text/plain',
]);
```

## Laravel configuration example

```php
'my_s3' => [
    'driver' => 's3',
    'key' => env('MY_S3_KEY'),
    'secret' => env('MY_S3_SECRET'),
    'region' => env('MY_S3_REGION', 'us-east-1'),
    'bucket' => env('MY_S3_BUCKET'),
    'endpoint' => env('MY_S3_ENDPOINT'),
    'use_path_style_endpoint' => true,
    'throw' => false,
],
```

## Notes about compatibility

This project intentionally implements a small but useful subset of the S3 API. It is best suited for applications that:

- use path-style endpoints
- rely on simple upload, download, delete, and list operations
- do not require multipart uploads or advanced AWS features

If you need full S3 behavior, production-grade replication, multipart uploads, or broader SDK compatibility, use a dedicated object storage server instead.

## Smoke test

This repository includes a simple signed smoke test with no extra dependencies beyond PHP itself.

Start a local test server:

```bash
set S3_ACCESS_KEY=change-me
set S3_SECRET_KEY=change-me
php -S 127.0.0.1:8080 index.php
```

Run the smoke test in another terminal:

```bash
php smoke-test.php http://127.0.0.1:8080 change-me change-me
```

Environment variables are also supported:

```bash
set SMOKE_ENDPOINT=http://127.0.0.1:8080
set SMOKE_ACCESS_KEY=change-me
set SMOKE_SECRET_KEY=change-me
php smoke-test.php
```

If your server allows unsigned reads, also set:

```bash
set SMOKE_EXPECT_UNSIGNED_GET=true
```

## Security notes

- Do not publish real credentials in source control.
- Keep `S3_DEBUG_LOG_ENABLED` disabled in public deployments unless you are troubleshooting.
- Protect the project with HTTPS if it is exposed outside a trusted network.
- Review filesystem permissions for the `storage` directory.
- Use strong random access keys and secrets.
- Treat this as simple file-backed storage, not as a hardened multi-tenant object storage platform.

## Author

Built and maintained by [Guilherme Lobo Oliveira](https://www.linkedin.com/in/guilhermelobooliveira).

## License

MIT
