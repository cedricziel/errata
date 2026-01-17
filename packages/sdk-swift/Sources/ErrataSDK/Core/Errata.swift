import Foundation
import Logging

/// The main entry point for the Errata SDK.
public final class Errata {
    /// The shared singleton instance.
    public static let shared = Errata()

    /// The current configuration.
    private var configuration: Configuration?

    /// The event queue for batching events.
    private var eventQueue: EventQueue?

    /// The batch sender for transmitting events.
    private var batchSender: BatchSender?

    /// The crash reporter for capturing crashes.
    private var crashReporter: CrashReporter?

    /// Breadcrumbs for context.
    private var breadcrumbs: [Breadcrumb] = []
    private let breadcrumbsLock = NSLock()

    /// User context.
    private var user: UserContext?

    /// Tags applied to all events.
    private var tags: [String: String] = [:]

    /// Extra context applied to all events.
    private var extra: [String: Any] = [:]

    /// Logger for internal debugging.
    private var logger = Logger(label: "com.errata.sdk")

    /// Whether the SDK has been started.
    public private(set) var isStarted = false

    private init() {}

    // MARK: - Initialization

    /// Start the SDK with the given configuration.
    public func start(with configuration: Configuration) {
        guard !isStarted else {
            logger.warning("Errata SDK already started")
            return
        }

        guard configuration.isValid else {
            logger.error("Invalid Errata configuration - DSN parsing failed")
            return
        }

        self.configuration = configuration

        if configuration.debug {
            logger.logLevel = .debug
        }

        // Initialize components
        batchSender = BatchSender(configuration: configuration)
        eventQueue = EventQueue(
            configuration: configuration,
            sender: batchSender!
        )

        // Start crash reporting if enabled
        if configuration.enableCrashReporting {
            crashReporter = CrashReporter()
            crashReporter?.start { [weak self] crashEvent in
                self?.handleCrash(crashEvent)
            }
        }

        // Start the flush timer
        eventQueue?.startFlushTimer()

        isStarted = true
        logger.info("Errata SDK started")

        // Check for pending crash reports
        crashReporter?.processPendingReports()
    }

    /// Stop the SDK and flush pending events.
    public func stop() {
        guard isStarted else { return }

        eventQueue?.flush()
        crashReporter?.stop()

        isStarted = false
        logger.info("Errata SDK stopped")
    }

    // MARK: - Event Capture

    /// Capture an error.
    public func captureError(
        _ error: Error,
        context: [String: Any]? = nil,
        tags: [String: String]? = nil
    ) {
        guard isStarted else { return }

        let event = WideEvent.error(
            from: error,
            context: mergeContext(context),
            tags: mergeTags(tags),
            breadcrumbs: currentBreadcrumbs(),
            user: user,
            deviceInfo: DeviceInfo.current
        )

        enqueueEvent(event)
    }

    /// Capture an exception (NSException).
    public func captureException(
        _ exception: NSException,
        context: [String: Any]? = nil,
        tags: [String: String]? = nil
    ) {
        guard isStarted else { return }

        let event = WideEvent.exception(
            from: exception,
            context: mergeContext(context),
            tags: mergeTags(tags),
            breadcrumbs: currentBreadcrumbs(),
            user: user,
            deviceInfo: DeviceInfo.current
        )

        enqueueEvent(event)
    }

    /// Capture a message/log.
    public func captureMessage(
        _ message: String,
        level: Severity = .info,
        context: [String: Any]? = nil,
        tags: [String: String]? = nil
    ) {
        guard isStarted else { return }

        let event = WideEvent.message(
            message,
            severity: level,
            context: mergeContext(context),
            tags: mergeTags(tags),
            breadcrumbs: currentBreadcrumbs(),
            user: user,
            deviceInfo: DeviceInfo.current
        )

        enqueueEvent(event)
    }

    /// Capture a metric value.
    public func recordMetric(
        name: String,
        value: Double,
        unit: String? = nil,
        tags: [String: String]? = nil
    ) {
        guard isStarted else { return }

        let event = WideEvent.metric(
            name: name,
            value: value,
            unit: unit,
            tags: mergeTags(tags),
            deviceInfo: DeviceInfo.current
        )

        enqueueEvent(event)
    }

    // MARK: - Spans/Tracing

    /// Start a new span for performance tracking.
    public func startSpan(operation: String, description: String? = nil) -> Span {
        let span = Span(operation: operation, description: description)
        return span
    }

    /// Record a completed span.
    internal func recordSpan(_ span: Span) {
        guard isStarted else { return }

        let event = WideEvent.span(
            from: span,
            tags: mergeTags(span.tags),
            deviceInfo: DeviceInfo.current
        )

        enqueueEvent(event)
    }

    // MARK: - Breadcrumbs

    /// Add a breadcrumb for context.
    public func addBreadcrumb(_ breadcrumb: Breadcrumb) {
        breadcrumbsLock.lock()
        defer { breadcrumbsLock.unlock() }

        breadcrumbs.append(breadcrumb)

        // Trim to max size
        let maxCount = configuration?.maxBreadcrumbs ?? 100
        if breadcrumbs.count > maxCount {
            breadcrumbs.removeFirst(breadcrumbs.count - maxCount)
        }
    }

    /// Add a simple breadcrumb.
    public func addBreadcrumb(
        category: String,
        message: String,
        level: Severity = .info,
        data: [String: Any]? = nil
    ) {
        let breadcrumb = Breadcrumb(
            category: category,
            message: message,
            level: level,
            data: data
        )
        addBreadcrumb(breadcrumb)
    }

    /// Clear all breadcrumbs.
    public func clearBreadcrumbs() {
        breadcrumbsLock.lock()
        defer { breadcrumbsLock.unlock() }
        breadcrumbs.removeAll()
    }

    private func currentBreadcrumbs() -> [Breadcrumb] {
        breadcrumbsLock.lock()
        defer { breadcrumbsLock.unlock() }
        return breadcrumbs
    }

    // MARK: - Context

    /// Set the user context.
    public func setUser(_ user: UserContext?) {
        self.user = user
    }

    /// Set a tag that will be applied to all events.
    public func setTag(_ key: String, value: String) {
        tags[key] = value
    }

    /// Remove a tag.
    public func removeTag(_ key: String) {
        tags.removeValue(forKey: key)
    }

    /// Set extra context that will be applied to all events.
    public func setExtra(_ key: String, value: Any) {
        extra[key] = value
    }

    /// Remove extra context.
    public func removeExtra(_ key: String) {
        extra.removeValue(forKey: key)
    }

    private func mergeTags(_ eventTags: [String: String]?) -> [String: String] {
        var merged = tags
        if let eventTags = eventTags {
            merged.merge(eventTags) { _, new in new }
        }
        return merged
    }

    private func mergeContext(_ eventContext: [String: Any]?) -> [String: Any] {
        var merged = extra
        if let eventContext = eventContext {
            merged.merge(eventContext) { _, new in new }
        }
        return merged
    }

    // MARK: - Flush

    /// Manually flush queued events.
    public func flush() {
        eventQueue?.flush()
    }

    // MARK: - Internal

    private func enqueueEvent(_ event: WideEvent) {
        var eventWithProject = event
        eventWithProject.projectId = configuration?.projectId

        if configuration?.sendImmediately == true {
            batchSender?.sendImmediately(eventWithProject)
        } else {
            eventQueue?.enqueue(eventWithProject)
        }

        logger.debug("Event enqueued: \(event.eventType)")
    }

    private func handleCrash(_ crashEvent: WideEvent) {
        var event = crashEvent
        event.projectId = configuration?.projectId
        event.breadcrumbs = currentBreadcrumbs()
        event.user = user
        event.tags = mergeTags(nil)

        // Send crash immediately
        batchSender?.sendImmediately(event)
    }
}

// MARK: - Supporting Types

/// User context for events.
public struct UserContext: Codable {
    public var id: String?
    public var email: String?
    public var username: String?
    public var sessionId: String?

    public init(id: String? = nil, email: String? = nil, username: String? = nil, sessionId: String? = nil) {
        self.id = id
        self.email = email
        self.username = username
        self.sessionId = sessionId
    }
}

/// Breadcrumb for event context.
public struct Breadcrumb: Codable {
    public var timestamp: Date
    public var category: String
    public var message: String?
    public var level: Severity
    public var data: [String: String]?

    public init(
        timestamp: Date = Date(),
        category: String,
        message: String? = nil,
        level: Severity = .info,
        data: [String: Any]? = nil
    ) {
        self.timestamp = timestamp
        self.category = category
        self.message = message
        self.level = level
        // Convert data to string values for JSON encoding
        self.data = data?.compactMapValues { "\($0)" }
    }
}

/// Severity level for events.
public enum Severity: String, Codable {
    case trace
    case debug
    case info
    case warning
    case error
    case fatal
}
