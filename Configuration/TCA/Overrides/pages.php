<?php

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

$ll = 'LLL:EXT:language_mode_switch/Resources/Private/Language/locallang_db.xlf:';

$extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)
    ->get('language_mode_switch');

$defaultLabel = $ll . 'pages.l10n_mode.default';
if ($extensionConfiguration['automaticMode']) {
    $defaultLabel = $ll . 'pages.l10n_mode.automatic';
}

/**
 * Add extra fields to the pages record
 */
$additionalPagesColumns = [
    'l10n_mode' => [
        'exclude' => true,
        'label' => $ll . 'pages.l10n_mode',
        'description' => $ll . 'pages.l10n_mode.description',
        'displayCond' => 'FIELD:l10n_parent:!=:0',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [
                ['label' => $defaultLabel, 'value' => ''],
                ['label' => $ll . 'pages.l10n_mode.strict', 'value' => 'strict'],
                ['label' => $ll . 'pages.l10n_mode.fallback', 'value' => 'fallback'],
                ['label' => $ll . 'pages.l10n_mode.free', 'value' => 'free'],
            ],
        ],
    ],
];

ExtensionManagementUtility::addTCAcolumns(
    'pages',
    $additionalPagesColumns
);

ExtensionManagementUtility::addToAllTCAtypes(
    'pages',
    'l10n_mode',
    '',
    'after:l18n_cfg'
);
