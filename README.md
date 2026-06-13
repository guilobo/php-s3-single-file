# Single-File S3-Compatible Storage API

This project is a lightweight S3-compatible storage server implemented in a single PHP file.

It is designed for small deployments, local tools, development environments, self-hosted apps, and frameworks such as Laravel that only need a practical subset of the S3 API without running MinIO, LocalStack, or a full object storage stack.

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

Objects are stored on disk under `storage/<bucket>/<key>`.

## Requirements

- PHP 8.0 or newer
- Apache with `mod_rewrite`
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

## Quick start

1. Place the project in an Apache-served directory.
2. Make sure `mod_rewrite` is enabled.
3. Set your environment variables.
4. Ensure the web server can write to the storage directory.
5. Send S3-compatible requests to the project URL.

Example Apache environment values:

```apache
SetEnv S3_ACCESS_KEY your-access-key
SetEnv S3_SECRET_KEY your-secret-key
SetEnv S3_REGION us-east-1
SetEnv S3_REQUIRE_AUTH true
SetEnv S3_DEBUG_LOG_ENABLED false
```

## Example AWS CLI usage

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

## Security notes

- Do not publish real credentials in `index.php`.
- Keep `S3_DEBUG_LOG_ENABLED` disabled in public deployments unless you are troubleshooting.
- Protect the project with HTTPS if it is exposed outside a trusted network.
- Review filesystem permissions for the `storage` directory.

## License

Add the license of your choice before publishing publicly. MIT is a good default if you want broad reuse.
