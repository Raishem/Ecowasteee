<?php
// Canonical project stages used across all project detail pages.
// Return an array of canonical stages. Keep this as a single source of truth so
// different page variants don't drift out of sync.
return [
    [
        'number' => 1,
        'template_number' => 1,
        'name' => 'Preparation',
        'description' => 'Collect materials required for this project',
        'icon' => 'fa-lightbulb'
    ],
    [
        'number' => 2,
        'template_number' => 2,
        'name' => 'Construction',
        'description' => 'Build your project, follow safety guidelines, document progress',
        'icon' => 'fa-hard-hat'
    ],
    [
        'number' => 3,
        'template_number' => 3,
        'name' => 'Share',
        'description' => 'Share your project with the community',
        'icon' => 'fa-share-alt'
    ]
];
