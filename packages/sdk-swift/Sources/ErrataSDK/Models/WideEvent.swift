import Foundation

/// Wide event model that handles all event types in a unified format.
public struct WideEvent: Codable {
    // Core fields
    public var eventId: String
    public var timestamp: Int64  // milliseconds since epoch
    public var projectId: String?
    public var eventType: EventType
    public var fingerprint: String?

    // Severity
    public var severity: Severity?

    // Message/Content
    public var message: String?
    public var exceptionType: String?
    public var stackTrace: [StackFrame]?

    // App Context
    public var appVersion: String?
    public var appBuild: String?
    public var bundleId: String?
    public var environment: String?

    // Device Context
    public var deviceModel: String?
    public var deviceId: String?
    public var osName: String?
    public var osVersion: String?
    public var locale: String?
    public var timezone: String?

    // Resource Metrics
    public var memoryUsed: Int64?
    public var memoryTotal: Int64?
    public var diskFree: Int64?
    public var batteryLevel: Float?

    // Span/Trace fields
    public var traceId: String?
    public var spanId: String?
    public var parentSpanId: String?
    public var operation: String?
    public var durationMs: Double?
    public var spanStatus: String?

    // Metric fields
    public var metricName: String?
    public var metricValue: Double?
    public var metricUnit: String?

    // User Context
    public var userId: String?
    public var sessionId: String?
    public var user: UserContext?

    // Extensible dimensions
    public var tags: [String: String]?
    public var context: [String: String]?
    public var breadcrumbs: [Breadcrumb]?

    private enum CodingKeys: String, CodingKey {
        case eventId = "event_id"
        case timestamp
        case projectId = "project_id"
        case eventType = "event_type"
        case fingerprint
        case severity
        case message
        case exceptionType = "exception_type"
        case stackTrace = "stack_trace"
        case appVersion = "app_version"
        case appBuild = "app_build"
        case bundleId = "bundle_id"
        case environment
        case deviceModel = "device_model"
        case deviceId = "device_id"
        case osName = "os_name"
        case osVersion = "os_version"
        case locale
        case timezone
        case memoryUsed = "memory_used"
        case memoryTotal = "memory_total"
        case diskFree = "disk_free"
        case batteryLevel = "battery_level"
        case traceId = "trace_id"
        case spanId = "span_id"
        case parentSpanId = "parent_span_id"
        case operation
        case durationMs = "duration_ms"
        case spanStatus = "span_status"
        case metricName = "metric_name"
        case metricValue = "metric_value"
        case metricUnit = "metric_unit"
        case userId = "user_id"
        case sessionId = "session_id"
        case user
        case tags
        case context
        case breadcrumbs
    }

    public init(eventType: EventType) {
        self.eventId = UUID().uuidString
        self.timestamp = Int64(Date().timeIntervalSince1970 * 1000)
        self.eventType = eventType
    }

    // MARK: - Factory Methods

    /// Create an error event from a Swift Error.
    public static func error(
        from error: Error,
        context: [String: Any]?,
        tags: [String: String]?,
        breadcrumbs: [Breadcrumb],
        user: UserContext?,
        deviceInfo: DeviceInfo
    ) -> WideEvent {
        var event = WideEvent(eventType: .error)
        event.severity = .error
        event.message = error.localizedDescription
        event.exceptionType = String(describing: type(of: error))

        // Try to get stack trace
        if let nsError = error as NSError? {
            event.context = nsError.userInfo.compactMapValues { "\($0)" }
        }

        event.applyDeviceInfo(deviceInfo)
        event.tags = tags
        event.breadcrumbs = breadcrumbs
        event.applyUser(user)

        if let context = context {
            event.context = (event.context ?? [:]).merging(context.compactMapValues { "\($0)" }) { _, new in new }
        }

        return event
    }

    /// Create an error event from an NSException.
    public static func exception(
        from exception: NSException,
        context: [String: Any]?,
        tags: [String: String]?,
        breadcrumbs: [Breadcrumb],
        user: UserContext?,
        deviceInfo: DeviceInfo
    ) -> WideEvent {
        var event = WideEvent(eventType: .error)
        event.severity = .error
        event.message = exception.reason ?? exception.name.rawValue
        event.exceptionType = exception.name.rawValue

        // Convert call stack to stack frames
        let symbols = exception.callStackSymbols
        event.stackTrace = symbols.enumerated().map { index, symbol in
            StackFrame.parse(from: symbol, index: index)
        }

        event.applyDeviceInfo(deviceInfo)
        event.tags = tags
        event.breadcrumbs = breadcrumbs
        event.applyUser(user)

        if let context = context {
            event.context = context.compactMapValues { "\($0)" }
        }

        return event
    }

    /// Create a message/log event.
    public static func message(
        _ message: String,
        severity: Severity,
        context: [String: Any]?,
        tags: [String: String]?,
        breadcrumbs: [Breadcrumb],
        user: UserContext?,
        deviceInfo: DeviceInfo
    ) -> WideEvent {
        var event = WideEvent(eventType: .log)
        event.severity = severity
        event.message = message
        event.applyDeviceInfo(deviceInfo)
        event.tags = tags
        event.breadcrumbs = breadcrumbs
        event.applyUser(user)

        if let context = context {
            event.context = context.compactMapValues { "\($0)" }
        }

        return event
    }

    /// Create a metric event.
    public static func metric(
        name: String,
        value: Double,
        unit: String?,
        tags: [String: String]?,
        deviceInfo: DeviceInfo
    ) -> WideEvent {
        var event = WideEvent(eventType: .metric)
        event.metricName = name
        event.metricValue = value
        event.metricUnit = unit
        event.applyDeviceInfo(deviceInfo)
        event.tags = tags
        return event
    }

    /// Create a span event.
    public static func span(
        from span: Span,
        tags: [String: String]?,
        deviceInfo: DeviceInfo
    ) -> WideEvent {
        var event = WideEvent(eventType: .span)
        event.traceId = span.traceId
        event.spanId = span.spanId
        event.parentSpanId = span.parentSpanId
        event.operation = span.operation
        event.durationMs = span.duration * 1000  // Convert to milliseconds
        event.spanStatus = span.status.rawValue
        event.applyDeviceInfo(deviceInfo)
        event.tags = tags
        return event
    }

    /// Create a crash event.
    public static func crash(
        message: String,
        stackTrace: [StackFrame],
        deviceInfo: DeviceInfo
    ) -> WideEvent {
        var event = WideEvent(eventType: .crash)
        event.severity = .fatal
        event.message = message
        event.stackTrace = stackTrace
        event.applyDeviceInfo(deviceInfo)
        return event
    }

    // MARK: - Helpers

    mutating func applyDeviceInfo(_ info: DeviceInfo) {
        deviceModel = info.model
        deviceId = info.deviceId
        osName = info.osName
        osVersion = info.osVersion
        appVersion = info.appVersion
        appBuild = info.appBuild
        bundleId = info.bundleId
        locale = info.locale
        timezone = info.timezone
        memoryUsed = info.memoryUsed
        memoryTotal = info.memoryTotal
        diskFree = info.diskFree
        batteryLevel = info.batteryLevel
    }

    mutating func applyUser(_ user: UserContext?) {
        guard let user = user else { return }
        self.userId = user.id
        self.sessionId = user.sessionId
        self.user = user
    }
}

/// Event types.
public enum EventType: String, Codable {
    case crash
    case error
    case log
    case metric
    case span
}

/// Stack frame representation.
public struct StackFrame: Codable {
    public var filename: String?
    public var function: String?
    public var module: String?
    public var lineno: Int?
    public var colno: Int?
    public var instructionAddress: String?

    private enum CodingKeys: String, CodingKey {
        case filename
        case function
        case module
        case lineno
        case colno
        case instructionAddress = "instruction_address"
    }

    /// Parse a stack frame from a symbol string.
    public static func parse(from symbol: String, index: Int) -> StackFrame {
        var frame = StackFrame()

        // Try to parse the symbol string
        // Format: "0   MyApp    0x000000010a1234  functionName + 123"
        let components = symbol.components(separatedBy: " ").filter { !$0.isEmpty }

        if components.count >= 4 {
            frame.module = components[1]
            frame.instructionAddress = components[2]

            // Extract function name (everything after address until + offset)
            let remaining = components[3...].joined(separator: " ")
            if let plusIndex = remaining.lastIndex(of: "+") {
                frame.function = String(remaining[..<plusIndex]).trimmingCharacters(in: .whitespaces)
            } else {
                frame.function = remaining
            }
        }

        return frame
    }
}
