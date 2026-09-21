## Idempotent Booking Creates

`POST /bookings` now requires `Idempotency-Key`, stores the request payload hash and exact response for successful creates, replays identical retries, and rejects reused keys with changed payloads.

Idempotency key records are pruned after 24 hours. That window is enough because these keys only protect clients from short-lived retry storms, network timeouts, and duplicate submissions around a create request; after a day, clients should stop retrying the same create operation and start a fresh request with a new key.
