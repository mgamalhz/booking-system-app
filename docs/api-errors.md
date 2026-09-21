# API error contract

Every failure under `/api/*` is returned as JSON, including unmatched routes and unexpected exceptions:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The given data was invalid.",
    "status": 422,
    "request_id": "b5b921a9-6736-45e3-a8b5-ccfa7ed58a50",
    "details": {
      "fields": {
        "email": ["The email field is required."]
      }
    }
  }
}
```

`code` is stable for client branching. `message` is safe to display. `details` is always an object and only contains validation fields today. `request_id` is also returned in the `X-Request-ID` header and can be supplied by callers when it contains only letters, numbers, `.`, `_`, `:`, or `-` and is at most 128 characters.

| Category | Code | Status |
| --- | --- | ---: |
| Validation | `validation_failed` | 422 |
| Authentication | `unauthenticated` | 401 |
| Authorization | `forbidden` | 403 |
| Missing resource | `not_found` | 404 |
| State conflict | `conflict` | 409 |
| Throttling | `too_many_requests` | 429 |
| Unexpected failure | `internal_server_error` | 500 |

Unexpected responses never contain exception messages, stack traces, SQL, paths, or secrets. They use a generic message. Server logs include only the request ID, exception class, method, named route, path, and authenticated user ID as diagnostic context; request payloads and headers are intentionally excluded.
