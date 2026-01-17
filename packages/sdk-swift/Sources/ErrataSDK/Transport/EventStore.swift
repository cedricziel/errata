import Foundation

/// File-based storage for offline event persistence.
final class EventStore {
    private let fileManager = FileManager.default
    private let encoder = JSONEncoder()
    private let decoder = JSONDecoder()

    private var storageDirectory: URL? {
        guard let cacheDir = fileManager.urls(for: .cachesDirectory, in: .userDomainMask).first else {
            return nil
        }
        let errataDir = cacheDir.appendingPathComponent("errata", isDirectory: true)

        if !fileManager.fileExists(atPath: errataDir.path) {
            try? fileManager.createDirectory(at: errataDir, withIntermediateDirectories: true)
        }

        return errataDir
    }

    /// Persist events to disk.
    func persist(_ events: [WideEvent]) {
        guard let dir = storageDirectory else { return }

        for event in events {
            let filename = "\(event.eventId).json"
            let fileURL = dir.appendingPathComponent(filename)

            do {
                let data = try encoder.encode(event)
                try data.write(to: fileURL, options: .atomic)
            } catch {
                print("Errata: Failed to persist event: \(error)")
            }
        }
    }

    /// Load persisted events.
    func load() -> [WideEvent] {
        guard let dir = storageDirectory else { return [] }

        var events: [WideEvent] = []

        do {
            let files = try fileManager.contentsOfDirectory(at: dir, includingPropertiesForKeys: nil)
            for file in files where file.pathExtension == "json" {
                do {
                    let data = try Data(contentsOf: file)
                    let event = try decoder.decode(WideEvent.self, from: data)
                    events.append(event)
                } catch {
                    // Remove corrupted file
                    try? fileManager.removeItem(at: file)
                }
            }
        } catch {
            print("Errata: Failed to load persisted events: \(error)")
        }

        return events
    }

    /// Remove events from storage.
    func remove(_ events: [WideEvent]) {
        guard let dir = storageDirectory else { return }

        for event in events {
            let filename = "\(event.eventId).json"
            let fileURL = dir.appendingPathComponent(filename)
            try? fileManager.removeItem(at: fileURL)
        }
    }

    /// Clear all persisted events.
    func clear() {
        guard let dir = storageDirectory else { return }

        do {
            let files = try fileManager.contentsOfDirectory(at: dir, includingPropertiesForKeys: nil)
            for file in files {
                try fileManager.removeItem(at: file)
            }
        } catch {
            print("Errata: Failed to clear persisted events: \(error)")
        }
    }

    /// Get the count of persisted events.
    var count: Int {
        guard let dir = storageDirectory else { return 0 }

        do {
            let files = try fileManager.contentsOfDirectory(at: dir, includingPropertiesForKeys: nil)
            return files.filter { $0.pathExtension == "json" }.count
        } catch {
            return 0
        }
    }
}
