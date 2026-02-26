<?php

use ITSC\LanguageModeSwitch\Middleware\Frontend\LanguageModeSwitch;

return [
    'frontend' => [
        'itsc/language-mode-switch' => [
            'target' => LanguageModeSwitch::class,
            'after' => [
                'typo3/cms-frontend/page-argument-validator',
            ],
            'before' => [
                'typo3/cms-frontend/tsfe',
            ],
        ],
    ],
];
