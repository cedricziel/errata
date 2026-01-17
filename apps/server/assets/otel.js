/**
 * OpenTelemetry Browser Instrumentation
 *
 * Initializes tracing for the Errata frontend to dogfood our own OTLP ingestion.
 * Configuration is read from meta tags injected by the server.
 */

import { trace, context } from '@opentelemetry/api';
import { Resource } from '@opentelemetry/resources';
import { ATTR_SERVICE_NAME, ATTR_SERVICE_VERSION } from '@opentelemetry/semantic-conventions';
import { WebTracerProvider } from '@opentelemetry/sdk-trace-web';
import { BatchSpanProcessor } from '@opentelemetry/sdk-trace-base';
import { OTLPTraceExporter } from '@opentelemetry/exporter-trace-otlp-http';
import { ZoneContextManager } from '@opentelemetry/context-zone';
import { registerInstrumentations } from '@opentelemetry/instrumentation';
import { FetchInstrumentation } from '@opentelemetry/instrumentation-fetch';
import { DocumentLoadInstrumentation } from '@opentelemetry/instrumentation-document-load';

/**
 * Read configuration from meta tags
 */
function getConfig() {
    const getMeta = (name, defaultValue = '') => {
        const meta = document.querySelector(`meta[name="${name}"]`);
        return meta ? meta.getAttribute('content') : defaultValue;
    };

    return {
        enabled: getMeta('otel-enabled', 'false') === 'true',
        apiKey: getMeta('otel-api-key', ''),
        endpoint: getMeta('otel-endpoint', '/v1/traces'),
        serviceName: getMeta('otel-service-name', 'errata-frontend'),
        serviceVersion: getMeta('otel-service-version', '1.0.0'),
    };
}

/**
 * URLs to ignore to avoid recursive tracing of our own telemetry requests
 */
const IGNORED_URLS = [
    '/v1/traces',
    '/v1/logs',
    '/v1/metrics',
];

/**
 * Check if a URL should be ignored for tracing
 */
function shouldIgnoreUrl(url) {
    try {
        const urlObj = new URL(url, window.location.origin);
        return IGNORED_URLS.some(ignored => urlObj.pathname.startsWith(ignored));
    } catch {
        return false;
    }
}

let tracerProvider = null;

/**
 * Initialize OpenTelemetry tracing
 */
function initTracing() {
    const config = getConfig();

    if (!config.enabled) {
        console.debug('[OTel] Tracing disabled');
        return;
    }

    if (!config.apiKey) {
        console.warn('[OTel] API key not configured, tracing disabled');
        return;
    }

    console.debug('[OTel] Initializing tracing...');

    // Create resource with service information
    const resource = new Resource({
        [ATTR_SERVICE_NAME]: config.serviceName,
        [ATTR_SERVICE_VERSION]: config.serviceVersion,
    });

    // Create OTLP exporter with custom headers for authentication
    const exporter = new OTLPTraceExporter({
        url: config.endpoint,
        headers: {
            'X-Errata-Key': config.apiKey,
        },
    });

    // Create tracer provider
    tracerProvider = new WebTracerProvider({
        resource,
        spanProcessors: [new BatchSpanProcessor(exporter)],
    });

    // Register the provider globally
    tracerProvider.register({
        contextManager: new ZoneContextManager(),
    });

    // Register automatic instrumentations
    registerInstrumentations({
        instrumentations: [
            new DocumentLoadInstrumentation(),
            new FetchInstrumentation({
                ignoreUrls: IGNORED_URLS,
                propagateTraceHeaderCorsUrls: [/.*/], // Propagate to all same-origin requests
                clearTimingResources: true,
            }),
        ],
    });

    console.debug('[OTel] Tracing initialized successfully');
}

/**
 * Get a tracer for manual instrumentation
 */
export function getTracer(name = 'errata-frontend') {
    return trace.getTracer(name);
}

/**
 * Get the current context for span propagation
 */
export function getContext() {
    return context;
}

// Initialize tracing when the module loads
initTracing();
