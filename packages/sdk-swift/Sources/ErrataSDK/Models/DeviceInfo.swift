import Foundation

#if canImport(UIKit)
import UIKit
#endif

#if canImport(AppKit)
import AppKit
#endif

/// Device and app information.
public struct DeviceInfo {
    public var model: String
    public var deviceId: String
    public var osName: String
    public var osVersion: String
    public var appVersion: String?
    public var appBuild: String?
    public var bundleId: String?
    public var locale: String
    public var timezone: String
    public var memoryUsed: Int64?
    public var memoryTotal: Int64?
    public var diskFree: Int64?
    public var batteryLevel: Float?

    /// Get the current device info.
    public static var current: DeviceInfo {
        var info = DeviceInfo(
            model: getDeviceModel(),
            deviceId: getDeviceId(),
            osName: getOSName(),
            osVersion: getOSVersion(),
            locale: Locale.current.identifier,
            timezone: TimeZone.current.identifier
        )

        // App info
        let bundle = Bundle.main
        info.appVersion = bundle.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String
        info.appBuild = bundle.object(forInfoDictionaryKey: "CFBundleVersion") as? String
        info.bundleId = bundle.bundleIdentifier

        // Memory info
        info.memoryUsed = getUsedMemory()
        info.memoryTotal = getTotalMemory()

        // Disk info
        info.diskFree = getFreeDiskSpace()

        // Battery
        info.batteryLevel = getBatteryLevel()

        return info
    }

    private static func getDeviceModel() -> String {
        var size = 0
        sysctlbyname("hw.machine", nil, &size, nil, 0)
        var machine = [CChar](repeating: 0, count: size)
        sysctlbyname("hw.machine", &machine, &size, nil, 0)
        return String(cString: machine)
    }

    private static func getDeviceId() -> String {
        #if canImport(UIKit) && !os(watchOS)
        return UIDevice.current.identifierForVendor?.uuidString ?? "unknown"
        #else
        // Generate a persistent device ID for macOS
        if let id = UserDefaults.standard.string(forKey: "com.errata.deviceId") {
            return id
        }
        let newId = UUID().uuidString
        UserDefaults.standard.set(newId, forKey: "com.errata.deviceId")
        return newId
        #endif
    }

    private static func getOSName() -> String {
        #if os(iOS)
        return "iOS"
        #elseif os(macOS)
        return "macOS"
        #elseif os(tvOS)
        return "tvOS"
        #elseif os(watchOS)
        return "watchOS"
        #else
        return "Unknown"
        #endif
    }

    private static func getOSVersion() -> String {
        let version = ProcessInfo.processInfo.operatingSystemVersion
        return "\(version.majorVersion).\(version.minorVersion).\(version.patchVersion)"
    }

    private static func getUsedMemory() -> Int64? {
        var info = mach_task_basic_info()
        var count = mach_msg_type_number_t(MemoryLayout<mach_task_basic_info>.size) / 4
        let result = withUnsafeMutablePointer(to: &info) {
            $0.withMemoryRebound(to: integer_t.self, capacity: 1) {
                task_info(mach_task_self_, task_flavor_t(MACH_TASK_BASIC_INFO), $0, &count)
            }
        }
        return result == KERN_SUCCESS ? Int64(info.resident_size) : nil
    }

    private static func getTotalMemory() -> Int64? {
        return Int64(ProcessInfo.processInfo.physicalMemory)
    }

    private static func getFreeDiskSpace() -> Int64? {
        let paths = NSSearchPathForDirectoriesInDomains(.documentDirectory, .userDomainMask, true)
        guard let path = paths.first else { return nil }

        do {
            let attrs = try FileManager.default.attributesOfFileSystem(forPath: path)
            return attrs[.systemFreeSize] as? Int64
        } catch {
            return nil
        }
    }

    private static func getBatteryLevel() -> Float? {
        #if canImport(UIKit) && !os(watchOS) && !os(tvOS)
        UIDevice.current.isBatteryMonitoringEnabled = true
        let level = UIDevice.current.batteryLevel
        return level >= 0 ? level : nil
        #else
        return nil
        #endif
    }
}
