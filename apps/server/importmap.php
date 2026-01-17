<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    '@hotwired/turbo' => [
        'version' => '7.3.0',
    ],
    'chart.js' => [
        'version' => '3.9.1',
    ],
    '@symfony/ux-live-component' => [
        'path' => './vendor/symfony/ux-live-component/assets/dist/live_controller.js',
    ],

    // OpenTelemetry Core
    '@opentelemetry/api' => [
        'version' => '1.9.0',
    ],
    '@opentelemetry/core' => [
        'version' => '2.0.0',
    ],
    '@opentelemetry/resources' => [
        'version' => '2.0.0',
    ],
    '@opentelemetry/semantic-conventions' => [
        'version' => '1.28.0',
    ],

    // OpenTelemetry Web Tracer
    '@opentelemetry/sdk-trace-base' => [
        'version' => '2.0.0',
    ],
    '@opentelemetry/sdk-trace-web' => [
        'version' => '2.0.0',
    ],

    // OpenTelemetry OTLP Exporter
    '@opentelemetry/exporter-trace-otlp-http' => [
        'version' => '0.57.0',
    ],
    '@opentelemetry/otlp-exporter-base' => [
        'version' => '0.57.0',
    ],
    '@opentelemetry/otlp-transformer' => [
        'version' => '0.57.0',
    ],

    // OpenTelemetry Instrumentations
    '@opentelemetry/instrumentation' => [
        'version' => '0.57.0',
    ],
    '@opentelemetry/instrumentation-fetch' => [
        'version' => '0.57.0',
    ],
    '@opentelemetry/instrumentation-document-load' => [
        'version' => '0.43.0',
    ],

    // OpenTelemetry Context
    '@opentelemetry/context-zone' => [
        'version' => '2.0.0',
    ],
];
