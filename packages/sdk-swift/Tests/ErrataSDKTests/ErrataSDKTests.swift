import XCTest
@testable import ErrataSDK

final class ErrataSDKTests: XCTestCase {

    override func setUp() {
        super.setUp()
    }

    override func tearDown() {
        super.tearDown()
    }

    // MARK: - Configuration Tests

    func testConfigurationParsesValidDSN() {
        let config = Configuration(dsn: "https://test_key@example.com/project-123")

        XCTAssertEqual(config.apiKey, "test_key")
        XCTAssertEqual(config.host, "example.com")
        XCTAssertEqual(config.projectId, "project-123")
        XCTAssertTrue(config.isValid)
    }

    func testConfigurationHandlesInvalidDSN() {
        let config = Configuration(dsn: "not-a-valid-dsn")

        XCTAssertNil(config.apiKey)
        XCTAssertFalse(config.isValid)
    }

    func testConfigurationDefaultValues() {
        let config = Configuration(dsn: "https://key@host/project")

        XCTAssertEqual(config.environment, "production")
        XCTAssertTrue(config.enableCrashReporting)
        XCTAssertTrue(config.enableExceptionCapture)
        XCTAssertEqual(config.maxBreadcrumbs, 100)
        XCTAssertEqual(config.maxQueueSize, 30)
        XCTAssertEqual(config.flushInterval, 30.0)
        XCTAssertFalse(config.sendImmediately)
        XCTAssertFalse(config.debug)
    }

    func testConfigurationEndpointURL() {
        let config = Configuration(dsn: "https://key@example.com/project")

        XCTAssertEqual(config.endpointURL?.absoluteString, "https://example.com/api/v1/events")
    }

    // MARK: - WideEvent Tests

    func testWideEventCreation() {
        let event = WideEvent(eventType: .error)

        XCTAssertFalse(event.eventId.isEmpty)
        XCTAssertGreaterThan(event.timestamp, 0)
        XCTAssertEqual(event.eventType, .error)
    }

    func testWideEventMessageFactory() {
        let event = WideEvent.message(
            "Test message",
            severity: .warning,
            context: ["key": "value"],
            tags: ["tag": "value"],
            breadcrumbs: [],
            user: nil,
            deviceInfo: DeviceInfo.current
        )

        XCTAssertEqual(event.eventType, .log)
        XCTAssertEqual(event.severity, .warning)
        XCTAssertEqual(event.message, "Test message")
        XCTAssertEqual(event.tags?["tag"], "value")
    }

    func testWideEventMetricFactory() {
        let event = WideEvent.metric(
            name: "test.metric",
            value: 42.5,
            unit: "ms",
            tags: nil,
            deviceInfo: DeviceInfo.current
        )

        XCTAssertEqual(event.eventType, .metric)
        XCTAssertEqual(event.metricName, "test.metric")
        XCTAssertEqual(event.metricValue, 42.5)
        XCTAssertEqual(event.metricUnit, "ms")
    }

    // MARK: - Span Tests

    func testSpanCreation() {
        let span = Span(operation: "test.operation", description: "Test")

        XCTAssertFalse(span.spanId.isEmpty)
        XCTAssertFalse(span.traceId.isEmpty)
        XCTAssertEqual(span.operation, "test.operation")
        XCTAssertFalse(span.isFinished)
    }

    func testSpanChildInheritsTraceId() {
        let parent = Span(operation: "parent")
        let child = parent.startChild(operation: "child")

        XCTAssertEqual(child.traceId, parent.traceId)
        XCTAssertEqual(child.parentSpanId, parent.spanId)
    }

    func testSpanDuration() {
        let span = Span(operation: "test")

        // Wait a bit
        Thread.sleep(forTimeInterval: 0.1)

        XCTAssertGreaterThan(span.duration, 0.09)
    }

    // MARK: - Breadcrumb Tests

    func testBreadcrumbCreation() {
        let breadcrumb = Breadcrumb(
            category: "test",
            message: "Test message",
            level: .info,
            data: ["key": "value"]
        )

        XCTAssertEqual(breadcrumb.category, "test")
        XCTAssertEqual(breadcrumb.message, "Test message")
        XCTAssertEqual(breadcrumb.level, .info)
        XCTAssertEqual(breadcrumb.data?["key"], "value")
    }

    // MARK: - DeviceInfo Tests

    func testDeviceInfoCurrent() {
        let info = DeviceInfo.current

        XCTAssertFalse(info.model.isEmpty)
        XCTAssertFalse(info.deviceId.isEmpty)
        XCTAssertFalse(info.osName.isEmpty)
        XCTAssertFalse(info.osVersion.isEmpty)
        XCTAssertFalse(info.locale.isEmpty)
        XCTAssertFalse(info.timezone.isEmpty)
    }

    // MARK: - EventStore Tests

    func testEventStorePersistence() {
        let store = EventStore()

        // Clear any existing events
        store.clear()
        XCTAssertEqual(store.count, 0)

        // Create and persist an event
        let event = WideEvent(eventType: .error)
        store.persist([event])

        XCTAssertEqual(store.count, 1)

        // Load the event
        let loaded = store.load()
        XCTAssertEqual(loaded.count, 1)
        XCTAssertEqual(loaded.first?.eventId, event.eventId)

        // Remove the event
        store.remove([event])
        XCTAssertEqual(store.count, 0)
    }

    // MARK: - Severity Tests

    func testSeverityRawValues() {
        XCTAssertEqual(Severity.trace.rawValue, "trace")
        XCTAssertEqual(Severity.debug.rawValue, "debug")
        XCTAssertEqual(Severity.info.rawValue, "info")
        XCTAssertEqual(Severity.warning.rawValue, "warning")
        XCTAssertEqual(Severity.error.rawValue, "error")
        XCTAssertEqual(Severity.fatal.rawValue, "fatal")
    }
}
