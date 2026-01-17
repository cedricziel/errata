import Foundation

/// HTTP sender for transmitting events to the backend.
final class BatchSender {
    private let configuration: Configuration
    private let session: URLSession
    private let encoder = JSONEncoder()

    private let maxRetries = 3
    private let retryDelay: TimeInterval = 1.0

    init(configuration: Configuration) {
        self.configuration = configuration

        let config = URLSessionConfiguration.default
        config.timeoutIntervalForRequest = 30
        config.timeoutIntervalForResource = 60
        self.session = URLSession(configuration: config)
    }

    /// Send a batch of events.
    func send(_ events: [WideEvent], completion: @escaping (Bool) -> Void) {
        guard !events.isEmpty else {
            completion(true)
            return
        }

        // Use batch endpoint for multiple events
        if events.count > 1 {
            sendBatch(events, completion: completion)
        } else if let event = events.first {
            sendSingle(event, completion: completion)
        }
    }

    /// Send a single event immediately (for crashes).
    func sendImmediately(_ event: WideEvent) {
        sendSingle(event, retryCount: 0) { _ in }
    }

    // MARK: - Private

    private func sendSingle(_ event: WideEvent, retryCount: Int = 0, completion: @escaping (Bool) -> Void) {
        guard let url = configuration.endpointURL,
              let apiKey = configuration.apiKey else {
            completion(false)
            return
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue(apiKey, forHTTPHeaderField: "X-Errata-Key")

        do {
            request.httpBody = try encoder.encode(event)
        } catch {
            print("Errata: Failed to encode event: \(error)")
            completion(false)
            return
        }

        let task = session.dataTask(with: request) { [weak self] data, response, error in
            guard let self = self else {
                completion(false)
                return
            }

            if let error = error {
                print("Errata: Request failed: \(error)")
                self.handleRetry(event: event, retryCount: retryCount, completion: completion)
                return
            }

            guard let httpResponse = response as? HTTPURLResponse else {
                completion(false)
                return
            }

            if (200...299).contains(httpResponse.statusCode) {
                completion(true)
            } else if httpResponse.statusCode == 429 {
                // Rate limited - retry with backoff
                self.handleRetry(event: event, retryCount: retryCount, completion: completion)
            } else if httpResponse.statusCode >= 500 {
                // Server error - retry
                self.handleRetry(event: event, retryCount: retryCount, completion: completion)
            } else {
                // Client error - don't retry
                print("Errata: Request failed with status \(httpResponse.statusCode)")
                completion(false)
            }
        }

        task.resume()
    }

    private func sendBatch(_ events: [WideEvent], completion: @escaping (Bool) -> Void) {
        guard let baseURL = configuration.endpointURL,
              let apiKey = configuration.apiKey else {
            completion(false)
            return
        }

        guard let url = URL(string: baseURL.absoluteString + "/batch") else {
            completion(false)
            return
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue(apiKey, forHTTPHeaderField: "X-Errata-Key")

        do {
            let payload = ["events": events]
            request.httpBody = try encoder.encode(payload)
        } catch {
            print("Errata: Failed to encode batch: \(error)")
            completion(false)
            return
        }

        let task = session.dataTask(with: request) { data, response, error in
            if let error = error {
                print("Errata: Batch request failed: \(error)")
                completion(false)
                return
            }

            guard let httpResponse = response as? HTTPURLResponse else {
                completion(false)
                return
            }

            completion((200...299).contains(httpResponse.statusCode))
        }

        task.resume()
    }

    private func handleRetry(event: WideEvent, retryCount: Int, completion: @escaping (Bool) -> Void) {
        guard retryCount < maxRetries else {
            completion(false)
            return
        }

        let delay = retryDelay * pow(2.0, Double(retryCount))

        DispatchQueue.global().asyncAfter(deadline: .now() + delay) { [weak self] in
            self?.sendSingle(event, retryCount: retryCount + 1, completion: completion)
        }
    }
}
