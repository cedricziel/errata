import Foundation

/// Thread-safe queue for batching events.
final class EventQueue {
    private let configuration: Configuration
    private let sender: BatchSender
    private let store: EventStore

    private var queue: [WideEvent] = []
    private let lock = NSLock()
    private var flushTimer: Timer?

    init(configuration: Configuration, sender: BatchSender) {
        self.configuration = configuration
        self.sender = sender
        self.store = EventStore()

        // Load any persisted events
        loadPersistedEvents()
    }

    /// Add an event to the queue.
    func enqueue(_ event: WideEvent) {
        lock.lock()
        defer { lock.unlock() }

        queue.append(event)

        // Check if we should flush
        if queue.count >= configuration.maxQueueSize {
            flushInternal()
        }
    }

    /// Flush all queued events.
    func flush() {
        lock.lock()
        defer { lock.unlock() }
        flushInternal()
    }

    /// Start the automatic flush timer.
    func startFlushTimer() {
        flushTimer?.invalidate()

        DispatchQueue.main.async { [weak self] in
            guard let self = self else { return }

            self.flushTimer = Timer.scheduledTimer(
                withTimeInterval: self.configuration.flushInterval,
                repeats: true
            ) { [weak self] _ in
                self?.flush()
            }
        }
    }

    /// Stop the flush timer.
    func stopFlushTimer() {
        flushTimer?.invalidate()
        flushTimer = nil
    }

    /// Get the current queue size.
    var count: Int {
        lock.lock()
        defer { lock.unlock() }
        return queue.count
    }

    // MARK: - Private

    private func flushInternal() {
        guard !queue.isEmpty else { return }

        let events = queue
        queue.removeAll()

        // Persist events in case sending fails
        store.persist(events)

        // Send events
        sender.send(events) { [weak self] success in
            if success {
                self?.store.remove(events)
            }
        }
    }

    private func loadPersistedEvents() {
        let events = store.load()
        if !events.isEmpty {
            lock.lock()
            queue.append(contentsOf: events)
            lock.unlock()
        }
    }
}
