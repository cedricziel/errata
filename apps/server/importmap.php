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
 *
 * @return array<string, array{    // Import name as key, description of the imported file as value
 *     path: string,               // Logical, relative or absolute path to the file
 *     type?: 'js'|'css'|'json',   // Type of the file, defaults to 'js'
 *     entrypoint?: bool,          // Whether the file is an entrypoint, for 'js' only
 * }|array{
 *     version: string,            // Version of the remote package
 *     package_specifier?: string, // Remote "package-name/path" specifier, defaults to the import name
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 * }>
 */
return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
    '@symfony/ux-live-component' => ['path' => './vendor/symfony/ux-live-component/assets/dist/live_controller.js'],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@hotwired/turbo' => ['version' => '8.0.23'],
    'chart.js' => ['version' => '4.5.1'],
    '@opentelemetry/api' => ['version' => '1.9.1'],
    '@opentelemetry/core' => ['version' => '2.8.0'],
    '@opentelemetry/resources' => ['version' => '2.8.0'],
    '@opentelemetry/semantic-conventions' => ['version' => '1.41.1'],
    '@opentelemetry/sdk-trace-base' => ['version' => '2.8.0'],
    '@opentelemetry/sdk-trace-web' => ['version' => '2.8.0'],
    '@opentelemetry/exporter-trace-otlp-http' => ['version' => '0.219.0'],
    '@opentelemetry/otlp-exporter-base' => ['version' => '0.219.0'],
    '@opentelemetry/otlp-exporter-base/browser-http' => ['version' => '0.219.0'],
    '@opentelemetry/otlp-transformer' => ['version' => '0.219.0'],
    'protobufjs/minimal' => ['version' => '8.6.5'],
    '@opentelemetry/instrumentation' => ['version' => '0.219.0'],
    '@opentelemetry/instrumentation-fetch' => ['version' => '0.219.0'],
    '@opentelemetry/instrumentation-document-load' => ['version' => '0.64.0'],
    '@opentelemetry/context-zone' => ['version' => '2.8.0'],
    '@opentelemetry/context-zone-peer-dep' => ['version' => '2.8.0'],
    '@opentelemetry/api-logs' => ['version' => '0.219.0'],
    '@opentelemetry/sdk-metrics' => ['version' => '2.8.0'],
    'zone.js' => ['version' => '0.16.2'],
    '@protobufjs/aspromise' => ['version' => '1.1.2'],
    '@protobufjs/base64' => ['version' => '1.1.2'],
    '@protobufjs/eventemitter' => ['version' => '1.1.1'],
    '@protobufjs/float' => ['version' => '1.0.2'],
    '@protobufjs/inquire' => ['version' => '1.1.2'],
    '@protobufjs/utf8' => ['version' => '1.1.1'],
    '@protobufjs/pool' => ['version' => '1.1.0'],
    '@kurkle/color' => ['version' => '0.4.0'],
];
