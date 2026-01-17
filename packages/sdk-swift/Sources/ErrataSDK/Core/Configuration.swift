import Foundation

/// Configuration for the Errata SDK.
public struct Configuration {
    /// The DSN (Data Source Name) containing the API key and endpoint.
    /// Format: https://<api_key>@<host>/<project_id>
    public let dsn: String

    /// The environment name (e.g., production, staging, development).
    public var environment: String

    /// Whether to capture crashes automatically.
    public var enableCrashReporting: Bool

    /// Whether to capture uncaught exceptions.
    public var enableExceptionCapture: Bool

    /// Maximum number of breadcrumbs to keep.
    public var maxBreadcrumbs: Int

    /// Maximum number of events to queue before flushing.
    public var maxQueueSize: Int

    /// Interval for flushing events (in seconds).
    public var flushInterval: TimeInterval

    /// Whether to send events immediately (bypasses queue).
    public var sendImmediately: Bool

    /// Whether to enable debug logging.
    public var debug: Bool

    /// Parsed components from DSN
    internal var apiKey: String?
    internal var host: String?
    internal var projectId: String?

    /// Initialize configuration with a DSN.
    public init(
        dsn: String,
        environment: String = "production",
        enableCrashReporting: Bool = true,
        enableExceptionCapture: Bool = true,
        maxBreadcrumbs: Int = 100,
        maxQueueSize: Int = 30,
        flushInterval: TimeInterval = 30.0,
        sendImmediately: Bool = false,
        debug: Bool = false
    ) {
        self.dsn = dsn
        self.environment = environment
        self.enableCrashReporting = enableCrashReporting
        self.enableExceptionCapture = enableExceptionCapture
        self.maxBreadcrumbs = maxBreadcrumbs
        self.maxQueueSize = maxQueueSize
        self.flushInterval = flushInterval
        self.sendImmediately = sendImmediately
        self.debug = debug

        // Parse DSN
        parseDSN()
    }

    /// Parse the DSN to extract components.
    private mutating func parseDSN() {
        // Expected format: https://<api_key>@<host>/<project_id>
        guard let url = URL(string: dsn) else {
            return
        }

        // Extract API key from user component
        if let user = url.user {
            apiKey = user
        }

        // Extract host
        host = url.host

        // Extract project ID from path
        let path = url.path.trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        if !path.isEmpty {
            projectId = path
        }
    }

    /// Get the API endpoint URL.
    public var endpointURL: URL? {
        guard let host = host else { return nil }
        let scheme = dsn.hasPrefix("https://") ? "https" : "http"
        return URL(string: "\(scheme)://\(host)/api/v1/events")
    }

    /// Validate the configuration.
    public var isValid: Bool {
        return apiKey != nil && host != nil && projectId != nil
    }
}
