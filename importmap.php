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
    'htmx.org' => [
        'version' => '2.0.7',
    ],
    'highlight.js' => [
        'version' => '11.11.1',
    ],
    'idiomorph' => [
        'version' => '0.7.4',
    ],
    'frankenphp-hot-reload' => [
        'version' => '1.0.1',
    ],
    'htmx-ext-sse' => [
        'version' => '2.2.4',
    ],
    'sortablejs' => [
        'version' => '1.15.7',
    ],
];
