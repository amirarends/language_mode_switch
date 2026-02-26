<?php

namespace ITSC\LanguageModeSwitch\Middleware\Frontend;

/*
 * Copyright (C) 2021 Daniel Siepmann <coding@daniel-siepmann.de>
 * Copyright (C) 2021 Patrick Crausaz <info@its-crausaz.ch>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301, USA.
 */

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Teaches TYPO3 to change the language mode based on configured value from actual page.
 *
 * TYPO3 does no longer allow to change language mode via TypoScript.
 * This is set once via site configuration.
 *
 * We extend translated pages with a new field to switch the mode for that specific page.
 * This middleware will fetch that info and modify current language configuration accordingly.
 */
class LanguageModeSwitch implements MiddlewareInterface
{
    private CacheManager $cacheManager;
    private ConnectionPool $connectionPool;

    /**
     * @var bool
     */
    private $enableAutomaticMode;

    /**
     * LanguageModeSwitch constructor.
     */
    public function __construct(
        CacheManager $cacheManager,
        ConnectionPool $connectionPool,
        ExtensionConfiguration $extensionConfiguration,
    ) {
        $this->cacheManager = $cacheManager;
        $this->connectionPool = $connectionPool;
        $this->enableAutomaticMode = (bool)($extensionConfiguration->get('language_mode_switch')['automaticMode'] ?? false);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $pageArguments = $request->getAttribute('routing', null);
        $language = $request->getAttribute('language', null);
        $mode = $this->getPageDefinedCustomMode($pageArguments, $language);
        if ($mode !== '') {
            $newLanguage = $this->getNewLanguageWithMode($language, $mode);
            $request = $request->withAttribute('language', $newLanguage);
        }
        return $handler->handle($request);
    }

    private function getPageDefinedCustomMode(
        ?PageArguments $pageArguments,
        ?SiteLanguage $siteLanguage,
    ): string {
        if ($this->missesRequirements($pageArguments, $siteLanguage)) {
            return '';
        }

        $cacheKey = 'customTranslationMode_' . $pageArguments->getPageId() . '_' . $siteLanguage->getLanguageId();
        $cache = $this->getCache();
        if ($cache->has($cacheKey)) {
            return $cache->get($cacheKey);
        }

        $mode = $this->loadModeFromPageProperties($pageArguments->getPageId(), $siteLanguage->getLanguageId());
        if ($mode === '' && $this->enableAutomaticMode) {
            if ($this->pageHasStandAloneContent($pageArguments->getPageId(), $siteLanguage->getLanguageId())) {
                return 'free';
            }
            return 'fallback';

        }

        $cache->set($cacheKey, $mode, ['pageId_' . $pageArguments->getPageId()]);
        return $mode;
    }

    private function loadModeFromPageProperties(int $pageId, int $languageId): string
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder->select('l10n_mode');
        $queryBuilder->from('pages');
        $queryBuilder->where(
            $queryBuilder->expr()->eq(
                'l10n_parent',
                $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)
            ),
            $queryBuilder->expr()->eq(
                'sys_language_uid',
                $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
            )
        );
        return $queryBuilder->executeQuery()->fetchOne();
    }

    private function missesRequirements(
        ?PageArguments $pageArguments,
        ?SiteLanguage $siteLanguage,
    ): bool {
        return !$pageArguments instanceof PageArguments
            || !$siteLanguage instanceof SiteLanguage
            || $siteLanguage->getLanguageId() <= 0
        ;
    }

    private function getNewLanguageWithMode(
        SiteLanguage $language,
        string $mode,
    ): SiteLanguage {
        return new SiteLanguage(
            $language->getLanguageId(),
            (string)$language->getLocale(),
            $language->getBase(),
            array_merge($language->toArray(), [
                'fallbackType' => $mode,
            ])
        );
    }

    private function pageHasStandAloneContent(int $pageId, int $languageId): bool
    {
        $queryBuilder = $this->getQueryBuilder();
        $queryBuilder->select('uid');
        $queryBuilder->from('tt_content');
        $queryBuilder->where(
            $queryBuilder->expr()->eq(
                'pid',
                $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)
            ),
            $queryBuilder->expr()->eq(
                'l18n_parent',
                $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
            ),
            $queryBuilder->expr()->eq(
                'sys_language_uid',
                $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
            )
        );
        $queryBuilder->setMaxResults(1);
        return (bool)$queryBuilder->executeQuery()->fetchOne();
    }

    private function getCache(): FrontendInterface
    {
        return $this->cacheManager->getCache('pages');
    }

    private function getQueryBuilder(): QueryBuilder
    {
        return $this->connectionPool->getQueryBuilderForTable('pages');
    }
}
