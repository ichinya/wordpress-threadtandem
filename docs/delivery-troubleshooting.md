# Delivery failures

## Large photo uploads and HTTP 413

A custom Bot API reverse proxy can impose a lower request-body limit than Telegram's photo limit. For example, a proxy with a 1 MiB body limit rejects a multi-megabyte PNG before the Bot API processes it. Its response can be HTML rather than Telegram JSON.

ThreadTandem treats HTTP 413 as a confirmed upload rejection and shows a size error. Other ambiguous gateway and transport failures still require investigation before another new message is sent; automatic retries could duplicate an already delivered message.

Local JPEG/PNG originals up to 10 MB remain supported. Files larger than 900 KiB are prepared as temporary JPEG upload copies, starting at a maximum dimension of 1600 pixels and quality 82, with smaller bounded attempts if needed. The upload copy is at most 900 KiB, leaving space for the caption and multipart headers below a common 1 MiB proxy limit. Small files are sent unchanged. The Media Library original is never overwritten. If image processing is unavailable, the job fails explicitly rather than uploading an oversized file or omitting the selected photo. Temporary files are removed after preparation.

When maintaining a custom Bot API server, also configure its web-server and any front proxy to accept the intended full request size, including multipart overhead. A limit below ThreadTandem's bounded upload size can still reject a request.

## Safe diagnostics

Transport failures, unrecognized responses and upload rejections include the API method, HTTP status, response format, request byte count and elapsed time. Remote response bodies, request URLs, credentials, photo bytes and message text are excluded from these diagnostics. Historical errors recorded before this change cannot recover their missing HTTP details.

Validation includes HTML 413 classification, ambiguous gateway failures, original-file preservation, deterministic image hashes, temporary-file cleanup, unavailable image processing, successful queued photo delivery and an unchanged retry without another send. Real queue integration tests run only in the disposable local QA installation; live verification uses the dedicated test bot and channel.
