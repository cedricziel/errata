import Foundation

/// A span for tracking performance of operations.
public final class Span {
    /// Unique identifier for this span.
    public let spanId: String

    /// Trace ID for distributed tracing.
    public let traceId: String

    /// Parent span ID if this is a child span.
    public var parentSpanId: String?

    /// The operation being tracked.
    public let operation: String

    /// Optional description.
    public var spanDescription: String?

    /// Start time.
    public let startTime: Date

    /// End time (set when finished).
    public private(set) var endTime: Date?

    /// Duration in seconds.
    public var duration: TimeInterval {
        guard let endTime = endTime else {
            return Date().timeIntervalSince(startTime)
        }
        return endTime.timeIntervalSince(startTime)
    }

    /// Status of the span.
    public var status: SpanStatus = .ok

    /// Tags associated with this span.
    public var tags: [String: String] = [:]

    /// Data associated with this span.
    public var data: [String: Any] = [:]

    /// Whether the span has finished.
    public var isFinished: Bool {
        return endTime != nil
    }

    /// Initialize a new span.
    public init(operation: String, description: String? = nil, parentSpan: Span? = nil) {
        self.spanId = UUID().uuidString.replacingOccurrences(of: "-", with: "").prefix(16).lowercased()
        self.traceId = parentSpan?.traceId ?? UUID().uuidString.replacingOccurrences(of: "-", with: "").lowercased()
        self.parentSpanId = parentSpan?.spanId
        self.operation = operation
        self.spanDescription = description
        self.startTime = Date()
    }

    /// Set a tag on this span.
    public func setTag(_ key: String, value: String) {
        tags[key] = value
    }

    /// Set data on this span.
    public func setData(_ key: String, value: Any) {
        data[key] = value
    }

    /// Start a child span.
    public func startChild(operation: String, description: String? = nil) -> Span {
        return Span(operation: operation, description: description, parentSpan: self)
    }

    /// Finish the span with an optional status.
    public func finish(status: SpanStatus = .ok) {
        guard !isFinished else { return }
        self.endTime = Date()
        self.status = status

        // Record to Errata
        Errata.shared.recordSpan(self)
    }
}

/// Status of a span.
public enum SpanStatus: String, Codable {
    case ok
    case cancelled
    case unknown
    case invalidArgument = "invalid_argument"
    case deadlineExceeded = "deadline_exceeded"
    case notFound = "not_found"
    case alreadyExists = "already_exists"
    case permissionDenied = "permission_denied"
    case resourceExhausted = "resource_exhausted"
    case failedPrecondition = "failed_precondition"
    case aborted
    case outOfRange = "out_of_range"
    case unimplemented
    case internalError = "internal_error"
    case unavailable
    case dataLoss = "data_loss"
    case unauthenticated
}
