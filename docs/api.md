# Errata API Documentation

## Overview

The Errata API provides endpoints for ingesting events from iOS applications. All API endpoints require authentication using an API key.

## Base URL

```
https://your-errata-instance.com/api/v1
```

## Authentication

All API requests (except health check) must include the `X-Errata-Key` header with a valid API key.

```bash
curl -X POST https://your-instance.com/api/v1/events \
  -H "X-Errata-Key: err_abc12345_..." \
  -H "Content-Type: application/json" \
  -d '{"event_type": "error", ...}'
```

### API Key Format

API keys are generated in the format: `err_<prefix>_<secret>`

- `err_` - Fixed prefix identifying Errata keys
- `<prefix>` - 8-character public identifier
- `<secret>` - 48-character secret

## Endpoints

### Health Check

Check if the API is operational.

```http
GET /api/v1/health
```

**Response:**
```json
{
  "status": "ok",
  "timestamp": "2024-01-15T10:30:00+00:00"
}
```

### Submit Single Event

Submit a single event for processing.

```http
POST /api/v1/events
```

**Headers:**
- `X-Errata-Key`: Your API key (required)
- `Content-Type`: application/json

**Request Body:**
```json
{
  "event_type": "error",
  "severity": "error",
  "message": "NSInvalidArgumentException: reason here",
  "exception_type": "NSInvalidArgumentException",
  "stack_trace": [
    {
      "module": "MyApp",
      "function": "-[ViewController buttonTapped:]",
      "lineno": 42
    }
  ],
  "app_version": "1.2.0",
  "app_build": "45",
  "bundle_id": "com.example.myapp",
  "environment": "production",
  "device_model": "iPhone14,2",
  "os_name": "iOS",
  "os_version": "17.0",
  "tags": {
    "feature": "checkout"
  },
  "context": {
    "cart_items": 3
  },
  "breadcrumbs": [
    {
      "timestamp": "2024-01-15T10:29:55Z",
      "category": "ui",
      "message": "User tapped checkout"
    }
  ]
}
```

**Response (202 Accepted):**
```json
{
  "status": "accepted",
  "message": "Event queued for processing"
}
```

### Submit Batch of Events

Submit multiple events in a single request.

```http
POST /api/v1/events/batch
```

**Headers:**
- `X-Errata-Key`: Your API key (required)
- `Content-Type`: application/json

**Request Body:**
```json
{
  "events": [
    {
      "event_type": "error",
      "message": "First error"
    },
    {
      "event_type": "log",
      "severity": "info",
      "message": "Log message"
    }
  ]
}
```

**Response (202 Accepted):**
```json
{
  "status": "accepted",
  "accepted": 2,
  "total": 2
}
```

If some events fail validation:
```json
{
  "status": "accepted",
  "accepted": 1,
  "total": 2,
  "errors": {
    "1": {
      "event_type": "Invalid event_type"
    }
  }
}
```

## Event Schema

### Required Fields

| Field | Type | Description |
|-------|------|-------------|
| `event_type` | string | One of: `crash`, `error`, `log`, `metric`, `span` |

### Optional Fields

#### Core Fields

| Field | Type | Description |
|-------|------|-------------|
| `severity` | string | `trace`, `debug`, `info`, `warning`, `error`, `fatal` |
| `message` | string | Event message or error description |
| `exception_type` | string | Exception/error class name |
| `stack_trace` | array | Array of stack frame objects |

#### App Context

| Field | Type | Description |
|-------|------|-------------|
| `app_version` | string | App version (e.g., "1.2.0") |
| `app_build` | string | Build number (e.g., "45") |
| `bundle_id` | string | Bundle identifier |
| `environment` | string | `production`, `staging`, `development` |

#### Device Context

| Field | Type | Description |
|-------|------|-------------|
| `device_model` | string | Device model identifier |
| `device_id` | string | Anonymized device ID |
| `os_name` | string | Operating system name |
| `os_version` | string | OS version |
| `locale` | string | User locale |
| `timezone` | string | Timezone identifier |

#### Resource Metrics

| Field | Type | Description |
|-------|------|-------------|
| `memory_used` | int64 | Memory used in bytes |
| `memory_total` | int64 | Total memory in bytes |
| `disk_free` | int64 | Free disk space in bytes |
| `battery_level` | float | Battery level (0.0-1.0) |

#### Span/Trace Fields

| Field | Type | Description |
|-------|------|-------------|
| `trace_id` | string | Distributed trace ID |
| `span_id` | string | Span identifier |
| `parent_span_id` | string | Parent span ID |
| `operation` | string | Operation name |
| `duration_ms` | float | Duration in milliseconds |
| `span_status` | string | Status (ok, error, etc.) |

#### Metric Fields

| Field | Type | Description |
|-------|------|-------------|
| `metric_name` | string | Metric name |
| `metric_value` | float | Metric value |
| `metric_unit` | string | Unit of measurement |

#### User Context

| Field | Type | Description |
|-------|------|-------------|
| `user_id` | string | Anonymized user ID |
| `session_id` | string | Session identifier |

#### Extensible Fields

| Field | Type | Description |
|-------|------|-------------|
| `tags` | object | Key-value tags for filtering |
| `context` | object | Additional context data |
| `breadcrumbs` | array | Trail of events |

### Stack Frame Object

```json
{
  "filename": "ViewController.swift",
  "function": "buttonTapped(_:)",
  "module": "MyApp",
  "lineno": 42,
  "colno": 10,
  "instruction_address": "0x104abc123"
}
```

### Breadcrumb Object

```json
{
  "timestamp": "2024-01-15T10:29:55Z",
  "category": "ui",
  "message": "Button tapped",
  "level": "info",
  "data": {
    "button_id": "checkout"
  }
}
```

## Error Responses

### 400 Bad Request

Invalid request format or validation error.

```json
{
  "error": "bad_request",
  "message": "Invalid JSON payload"
}
```

### 401 Unauthorized

Invalid or missing API key.

```json
{
  "error": "authentication_failed",
  "message": "Invalid or expired API key"
}
```

### 429 Too Many Requests

Rate limit exceeded.

```json
{
  "error": "rate_limited",
  "message": "Too many requests"
}
```

## Rate Limits

| Endpoint | Limit |
|----------|-------|
| Single event | 100 requests/minute |
| Batch event | 20 requests/minute |

## Best Practices

1. **Use batch endpoint** for multiple events to reduce request overhead
2. **Include device context** for better debugging capabilities
3. **Add breadcrumbs** to provide context for errors
4. **Use appropriate severity levels** to categorize events
5. **Scrub PII** before sending events (SDK handles basic scrubbing)

## SDK Integration

For the best experience, use the official iOS SDK which handles:
- Automatic batching and retry
- Offline persistence
- Device info collection
- Crash reporting
- Performance monitoring

See [SDK Integration Guide](sdk-integration.md) for details.
