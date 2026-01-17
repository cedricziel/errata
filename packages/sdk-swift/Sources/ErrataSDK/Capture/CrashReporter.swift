import Foundation
import CrashReporter

/// Crash reporter wrapper for PLCrashReporter.
final class CrashReporter {
    private var crashReporter: PLCrashReporter?
    private var crashHandler: ((WideEvent) -> Void)?

    init() {
        let config = PLCrashReporterConfig(
            signalHandlerType: .BSD,
            symbolicationStrategy: .all
        )
        crashReporter = PLCrashReporter(configuration: config)
    }

    /// Start crash reporting.
    func start(handler: @escaping (WideEvent) -> Void) {
        self.crashHandler = handler

        guard let reporter = crashReporter else { return }

        do {
            try reporter.enableAndReturnError()
        } catch {
            print("Errata: Failed to enable crash reporter: \(error)")
        }
    }

    /// Stop crash reporting.
    func stop() {
        // PLCrashReporter doesn't have a direct stop method
        crashReporter = nil
    }

    /// Process any pending crash reports from previous sessions.
    func processPendingReports() {
        guard let reporter = crashReporter else { return }

        if reporter.hasPendingCrashReport() {
            do {
                let data = try reporter.loadPendingCrashReportDataAndReturnError()
                processCrashReport(data: data)
                try reporter.purgePendingCrashReportAndReturnError()
            } catch {
                print("Errata: Failed to process pending crash report: \(error)")
            }
        }
    }

    /// Process crash report data.
    private func processCrashReport(data: Data) {
        guard let handler = crashHandler else { return }

        do {
            let report = try PLCrashReport(data: data)
            let event = convertToWideEvent(report: report)
            handler(event)
        } catch {
            print("Errata: Failed to parse crash report: \(error)")
        }
    }

    /// Convert PLCrashReport to WideEvent.
    private func convertToWideEvent(report: PLCrashReport) -> WideEvent {
        var stackFrames: [StackFrame] = []

        // Extract stack frames from the crashed thread
        if let crashedThread = report.threads?.first(where: { ($0 as? PLCrashReportThreadInfo)?.crashed == true }) as? PLCrashReportThreadInfo {
            if let frames = crashedThread.stackFrames as? [PLCrashReportStackFrameInfo] {
                for frame in frames {
                    var stackFrame = StackFrame()

                    if let symbolInfo = frame.symbolInfo {
                        stackFrame.function = symbolInfo.symbolName
                    }

                    // Get module from binary image info
                    if let imageInfo = report.images?.first(where: {
                        guard let img = $0 as? PLCrashReportBinaryImageInfo else { return false }
                        let baseAddr = img.imageBaseAddress
                        let size = img.imageSize
                        return frame.instructionPointer >= baseAddr && frame.instructionPointer < baseAddr + size
                    }) as? PLCrashReportBinaryImageInfo {
                        stackFrame.module = imageInfo.imageName
                    }

                    stackFrame.instructionAddress = String(format: "0x%llx", frame.instructionPointer)
                    stackFrames.append(stackFrame)
                }
            }
        }

        // Build crash message
        var message = "Application crashed"
        if let exceptionInfo = report.exceptionInfo {
            message = "\(exceptionInfo.exceptionName ?? "Unknown"): \(exceptionInfo.exceptionReason ?? "")"
        } else if let signalInfo = report.signalInfo {
            message = "Signal \(signalInfo.name ?? "UNKNOWN") (\(signalInfo.code ?? ""))"
        }

        var event = WideEvent.crash(
            message: message,
            stackTrace: stackFrames,
            deviceInfo: DeviceInfo.current
        )

        // Add exception type
        if let exceptionInfo = report.exceptionInfo {
            event.exceptionType = exceptionInfo.exceptionName
        }

        return event
    }
}
