# Delivery failures

## Large photo uploads and HTTP 413

A custom Bot API reverse proxy can impose a lower request-body limit than Telegram's photo limit. For example, a proxy with a 1 MiB body limit rejects a multi-megabyte PNG before the Bot API processes it. Its response can be HTML rather than Telegram JSON.

ThreadTandem treats HTTP 413 as a confirmed upload rejection and shows a size error. Other ambiguous gateway and transport failures still require investigation before another new message is sent; automatic retries could duplicate an already delivered message.

Local JPEG/PNG originals up to 10 MB remain supported. Files larger than 900 KiB are prepared as temporary JPEG upload copies, starting at a maximum dimension of 1600 pixels and quality 82, with smaller bounded attempts if needed. The upload copy is at most 900 KiB, leaving space for the caption and multipart headers below a common 1 MiB proxy limit. Small files are sent unchanged. The Media Library original is never overwritten. If image processing is unavailable, the job fails explicitly rather than uploading an oversized file or omitting the selected photo. Temporary files are removed after preparation.

When maintaining a custom Bot API server, also configure its web-server and any front proxy to accept the intended full request size, including multipart overhead. A limit below ThreadTandem's bounded upload size can still reject a request.

## Safe diagnostics

Transport failures, unrecognized responses and upload rejections include the API method, HTTP status, response format, request byte count and elapsed time. Remote response bodies, request URLs, credentials, photo bytes and message text are excluded from these diagnostics. Historical errors recorded before this change cannot recover their missing HTTP details.

## Incoming webhooks and Connection refused

Webhook requests originate from the Bot API server, not from the browser or from the reverse proxy that receives outgoing API calls. Test the site's normal HTTPS hostname from both the Bot API host and its container. An HTTP 403 from ThreadTandem without the secret header confirms that the network path reaches WordPress; a TCP connection refusal occurs before WordPress can authenticate or queue an update.

If the host and container both cannot connect to the site's public IP, inspect routing and firewall restrictions between the hosts. Changing a reverse proxy's incoming API host or a WordPress permalink does not repair this outgoing network path. Shared Bot API installations need an appropriate outbound network route for all supported webhook destinations, rather than an accidental per-site workaround.

When moving a bot from Telegram's cloud Bot API to a real self-hosted Bot API server, first call `logOut` on the cloud server, as required by Telegram's migration instructions. A custom HTTPS reverse proxy may still front the cloud service; do not assume that every custom API URL requires a migration. Never drop pending updates during recovery. Register the destination with its existing secret header and all required channel/message update types.

Delayed automatic discussion forwards can arrive after a publication was explicitly removed and replaced. ThreadTandem acknowledges events for inactive mappings without reviving the old discussion root or changing the replacement publication. An active mapping that disagrees with the current publication still requires investigation.

Validation includes HTML 413 classification, ambiguous gateway failures, original-file preservation, deterministic image hashes, temporary-file cleanup, unavailable image processing, successful queued photo delivery and an unchanged retry without another send. Real queue integration tests run only in the disposable local QA installation; live verification uses the dedicated test bot and channel.
