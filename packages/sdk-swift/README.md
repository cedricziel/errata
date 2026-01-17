# ErrataSDK

Swift SDK for Errata - iOS Issue Monitoring Platform

## Installation

### Swift Package Manager

Add the package dependency to your `Package.swift`:

```swift
dependencies: [
    .package(url: "https://github.com/yourorg/errata.git", from: "1.0.0"),
]
```

Then add `ErrataSDK` to your target's dependencies:

```swift
.target(
    name: "YourApp",
    dependencies: [
        .product(name: "ErrataSDK", package: "errata"),
    ]
)
```

Or add it via Xcode:
1. File → Add Package Dependencies...
2. Enter the repository URL
3. Select version requirements

## Quick Start

### Initialize the SDK

```swift
import ErrataSDK

// In AppDelegate.swift or your App init
func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?) -> Bool {

    Errata.shared.start(with: Configuration(
        dsn: "https://<API_KEY>@<HOST>/<PROJECT_ID>",
        environment: "production"
    ))

    return true
}
```

### Capture Errors

```swift
// Capture Swift errors
do {
    try riskyOperation()
} catch {
    Errata.shared.captureError(error)
}

// Capture with additional context
Errata.shared.captureError(error, context: [
    "user_action": "checkout",
    "cart_total": 99.99
])
```

### Capture Messages

```swift
// Log messages at different severity levels
Errata.shared.captureMessage("User completed onboarding", level: .info)
Errata.shared.captureMessage("API response slow", level: .warning)
Errata.shared.captureMessage("Payment failed", level: .error)
```

### Performance Monitoring

```swift
// Track operation performance with spans
let span = Errata.shared.startSpan(operation: "api.request")
span.setTag("endpoint", value: "/api/users")

// ... perform operation ...

span.finish()

// Track child operations
let parentSpan = Errata.shared.startSpan(operation: "checkout")
let dbSpan = parentSpan.startChild(operation: "db.query")
// ... database query ...
dbSpan.finish()
parentSpan.finish()
```

### Record Metrics

```swift
// Record custom metrics
Errata.shared.recordMetric(name: "app.launch_time", value: 1250, unit: "ms")
Errata.shared.recordMetric(name: "cache.hit_rate", value: 0.95)
```

### Breadcrumbs

Breadcrumbs provide a trail of events leading up to an error:

```swift
// Add breadcrumbs for context
Errata.shared.addBreadcrumb(
    category: "ui",
    message: "User tapped checkout button",
    level: .info
)

Errata.shared.addBreadcrumb(
    category: "network",
    message: "API request started",
    data: ["url": "/api/checkout"]
)
```

### User Context

```swift
// Set user information
Errata.shared.setUser(UserContext(
    id: "user-123",
    email: "user@example.com",
    sessionId: "session-456"
))

// Clear user on logout
Errata.shared.setUser(nil)
```

### Tags and Extra Context

```swift
// Set tags that apply to all events
Errata.shared.setTag("app_version", value: "1.2.0")
Errata.shared.setTag("feature_flag", value: "new_checkout")

// Set extra context
Errata.shared.setExtra("subscription_tier", value: "premium")
```

## Configuration Options

```swift
let config = Configuration(
    dsn: "https://...",
    environment: "production",
    enableCrashReporting: true,      // Auto-capture crashes
    enableExceptionCapture: true,    // Auto-capture NSExceptions
    maxBreadcrumbs: 100,             // Max breadcrumbs to keep
    maxQueueSize: 30,                // Events before auto-flush
    flushInterval: 30.0,             // Seconds between flushes
    sendImmediately: false,          // Bypass queue (for debugging)
    debug: false                      // Enable debug logging
)
```

## Crash Reporting

The SDK automatically captures crashes using PLCrashReporter. Crash reports are sent on the next app launch.

Crash reporting is enabled by default. To disable:

```swift
Configuration(dsn: "...", enableCrashReporting: false)
```

## Offline Support

Events are persisted to disk if sending fails. They will be retried on next app launch or when connectivity is restored.

## Thread Safety

The SDK is thread-safe. You can call methods from any thread.

## Requirements

- iOS 14.0+
- macOS 11.0+
- tvOS 14.0+
- watchOS 7.0+
- Swift 5.9+

## License

MIT License
