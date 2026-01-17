# iOS SDK Integration Guide

## Overview

The ErrataSDK provides comprehensive error monitoring for iOS applications, including:

- Automatic crash reporting
- Error and exception capture
- Performance monitoring with spans
- Custom metrics
- Breadcrumb trails for debugging

## Installation

### Swift Package Manager

Add to your `Package.swift`:

```swift
dependencies: [
    .package(url: "https://github.com/yourorg/errata", from: "0.1.0")
]
```

Or via Xcode:
1. File → Add Package Dependencies...
2. Enter repository URL
3. Select version requirements

## Quick Start

### 1. Initialize the SDK

Initialize as early as possible in your app lifecycle:

```swift
import ErrataSDK

@main
struct MyApp: App {
    init() {
        Errata.shared.start(with: Configuration(
            dsn: "https://<API_KEY>@<HOST>/<PROJECT_ID>",
            environment: "production"
        ))
    }

    var body: some Scene {
        WindowGroup {
            ContentView()
        }
    }
}
```

Or in UIKit's AppDelegate:

```swift
import ErrataSDK

@main
class AppDelegate: UIResponder, UIApplicationDelegate {
    func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
    ) -> Bool {
        Errata.shared.start(with: Configuration(
            dsn: "https://<API_KEY>@<HOST>/<PROJECT_ID>"
        ))
        return true
    }
}
```

### 2. Get Your DSN

1. Log in to your Errata dashboard
2. Navigate to Projects → Your Project
3. Copy the DSN from the SDK Integration section

The DSN format is: `https://<API_KEY>@<HOST>/<PROJECT_ID>`

## Features

### Crash Reporting

Crashes are captured automatically when the SDK is initialized. Crash reports are sent on the next app launch.

```swift
// Crash reporting is enabled by default
// To disable:
Configuration(dsn: "...", enableCrashReporting: false)
```

### Error Capture

Capture Swift errors:

```swift
do {
    try performRiskyOperation()
} catch {
    Errata.shared.captureError(error)
}
```

Capture with context:

```swift
Errata.shared.captureError(error, context: [
    "user_action": "checkout",
    "cart_total": 99.99
], tags: [
    "feature": "payment"
])
```

Capture NSExceptions:

```swift
Errata.shared.captureException(exception)
```

### Message/Log Capture

```swift
// Different severity levels
Errata.shared.captureMessage("Operation completed", level: .info)
Errata.shared.captureMessage("API latency high", level: .warning)
Errata.shared.captureMessage("Payment declined", level: .error)
```

### Performance Monitoring

Track operation performance:

```swift
let span = Errata.shared.startSpan(operation: "api.request")
span.setTag("endpoint", value: "/api/users")
span.setTag("method", value: "GET")

// Perform the operation
let result = await api.getUsers()

// Finish with status
if result.isSuccess {
    span.finish(status: .ok)
} else {
    span.finish(status: .internalError)
}
```

Track child operations:

```swift
let parentSpan = Errata.shared.startSpan(operation: "checkout")

let validationSpan = parentSpan.startChild(operation: "validate.cart")
// ... validate cart ...
validationSpan.finish()

let paymentSpan = parentSpan.startChild(operation: "process.payment")
// ... process payment ...
paymentSpan.finish()

parentSpan.finish()
```

### Custom Metrics

```swift
// Record metrics
Errata.shared.recordMetric(name: "app.launch_time", value: 1250, unit: "ms")
Errata.shared.recordMetric(name: "cache.hit_rate", value: 0.95)
Errata.shared.recordMetric(name: "api.response_size", value: 2048, unit: "bytes")
```

### Breadcrumbs

Breadcrumbs provide a trail of events leading up to an error:

```swift
// UI events
Errata.shared.addBreadcrumb(
    category: "ui",
    message: "User tapped 'Checkout'",
    level: .info
)

// Network events
Errata.shared.addBreadcrumb(
    category: "network",
    message: "API request started",
    data: ["url": "/api/checkout", "method": "POST"]
)

// Navigation
Errata.shared.addBreadcrumb(
    category: "navigation",
    message: "Navigated to CheckoutView"
)
```

### User Context

Associate events with users:

```swift
// After login
Errata.shared.setUser(UserContext(
    id: "user-12345",
    email: "user@example.com", // Optional
    sessionId: UUID().uuidString
))

// After logout
Errata.shared.setUser(nil)
```

### Tags and Extra Context

Set global tags and context:

```swift
// Tags for filtering
Errata.shared.setTag("app_version", value: "1.2.0")
Errata.shared.setTag("build_type", value: "release")
Errata.shared.setTag("feature_flags", value: "new_checkout,dark_mode")

// Extra context data
Errata.shared.setExtra("subscription_tier", value: "premium")
Errata.shared.setExtra("ab_test_group", value: "variant_b")

// Remove when no longer needed
Errata.shared.removeTag("feature_flags")
Errata.shared.removeExtra("ab_test_group")
```

## Configuration Options

```swift
let config = Configuration(
    dsn: "https://...",

    // Environment name
    environment: "production",  // or "staging", "development"

    // Crash reporting
    enableCrashReporting: true,
    enableExceptionCapture: true,

    // Event queue settings
    maxBreadcrumbs: 100,    // Maximum breadcrumbs to keep
    maxQueueSize: 30,       // Events before auto-flush
    flushInterval: 30.0,    // Seconds between flushes

    // Debug options
    sendImmediately: false, // Bypass queue (useful for debugging)
    debug: false            // Enable SDK debug logging
)
```

## Advanced Usage

### Manual Flush

Force-send queued events:

```swift
Errata.shared.flush()
```

### Stop the SDK

```swift
Errata.shared.stop()
```

### Check SDK Status

```swift
if Errata.shared.isStarted {
    // SDK is active
}
```

## Best Practices

### 1. Initialize Early

Initialize the SDK as early as possible to catch crashes during app startup.

### 2. Add Context

Include relevant context with errors to aid debugging:

```swift
Errata.shared.captureError(error, context: [
    "screen": "CheckoutView",
    "user_action": "submit_order",
    "order_total": order.total
])
```

### 3. Use Meaningful Breadcrumbs

Add breadcrumbs at key points in your app:

```swift
// Before critical operations
Errata.shared.addBreadcrumb(category: "payment", message: "Starting payment processing")

// On state changes
Errata.shared.addBreadcrumb(category: "auth", message: "User session refreshed")

// On errors (non-fatal)
Errata.shared.addBreadcrumb(category: "network", message: "API retry attempt 2", level: .warning)
```

### 4. Use Appropriate Severity

- `fatal`: App crashed or unrecoverable error
- `error`: Error that affects functionality
- `warning`: Potential issue or degraded experience
- `info`: Informational messages
- `debug`: Debugging information

### 5. Protect User Privacy

- Don't include PII in tags or context
- Use anonymized user IDs
- The SDK automatically scrubs some sensitive data

## Troubleshooting

### Events Not Appearing

1. Verify DSN is correct
2. Check API key is active
3. Enable debug mode: `Configuration(dsn: "...", debug: true)`
4. Check network connectivity

### Crashes Not Reported

1. Ensure SDK is initialized before the crash occurs
2. Crash reports are sent on next launch - relaunch the app
3. Check `enableCrashReporting: true`

### High Memory Usage

1. Reduce `maxBreadcrumbs`
2. Reduce `maxQueueSize`
3. Call `clearBreadcrumbs()` when appropriate

## Support

- Documentation: https://errata.example.com/docs
- GitHub Issues: https://github.com/yourorg/errata/issues
- Email: support@errata.example.com
