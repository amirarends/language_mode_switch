<?php

declare(strict_types=1);

namespace ITSC\LanguageModeSwitch\Tests\Functional\Middleware\Frontend;

use ITSC\LanguageModeSwitch\Middleware\Frontend\LanguageModeSwitch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

#[CoversClass(LanguageModeSwitch::class)]
final class LanguageModeSwitchTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3conf/ext/language_mode_switch',
    ];

    private function createHandler(\Closure $callback): RequestHandlerInterface
    {
        return new class ($callback) implements RequestHandlerInterface {
            public function __construct(private readonly \Closure $callback) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->callback)($request);
                return new Response();
            }
        };
    }

    private function createSubject(bool $automaticMode = false): LanguageModeSwitch
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')
            ->with('language_mode_switch')
            ->willReturn(['automaticMode' => $automaticMode ? '1' : '0']);

        return new LanguageModeSwitch(
            $this->get(CacheManager::class),
            $this->get(ConnectionPool::class),
            $extensionConfiguration,
        );
    }

    private function createLanguage(int $languageId = 1, string $fallbackType = 'fallback'): SiteLanguage
    {
        return new SiteLanguage(
            $languageId,
            'de_DE.UTF-8',
            new Uri('/de/'),
            ['fallbackType' => $fallbackType],
        );
    }

    #[Test]
    public function skipsDefaultLanguage(): void
    {
        $subject = $this->createSubject();
        $language = $this->createLanguage(0);
        $request = (new ServerRequest())
            ->withAttribute('routing', new PageArguments(1, '0', []))
            ->withAttribute('language', $language);

        $capturedFallbackType = null;
        $handler = $this->createHandler(function (ServerRequestInterface $request) use (&$capturedFallbackType): void {
            $capturedFallbackType = $request->getAttribute('language')->toArray()['fallbackType'];
        });

        $subject->process($request, $handler);

        self::assertSame('fallback', $capturedFallbackType);
    }

    #[Test]
    public function skipsWhenNoRoutingAttribute(): void
    {
        $subject = $this->createSubject();
        $language = $this->createLanguage();
        $request = (new ServerRequest())
            ->withAttribute('language', $language);

        $capturedFallbackType = null;
        $handler = $this->createHandler(function (ServerRequestInterface $request) use (&$capturedFallbackType): void {
            $capturedFallbackType = $request->getAttribute('language')->toArray()['fallbackType'];
        });

        $subject->process($request, $handler);

        self::assertSame('fallback', $capturedFallbackType);
    }

    #[Test]
    public function switchesToConfiguredMode(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Pages.csv');
        $subject = $this->createSubject();
        $language = $this->createLanguage(1);
        $request = (new ServerRequest())
            ->withAttribute('routing', new PageArguments(1, '0', []))
            ->withAttribute('language', $language);

        $capturedFallbackType = null;
        $handler = $this->createHandler(function (ServerRequestInterface $request) use (&$capturedFallbackType): void {
            $capturedFallbackType = $request->getAttribute('language')->toArray()['fallbackType'];
        });

        $subject->process($request, $handler);

        self::assertSame('strict', $capturedFallbackType);
    }

    #[Test]
    public function keepsDefaultWhenNoModeConfigured(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Pages.csv');
        $subject = $this->createSubject(automaticMode: false);
        $language = $this->createLanguage(2);
        $request = (new ServerRequest())
            ->withAttribute('routing', new PageArguments(1, '0', []))
            ->withAttribute('language', $language);

        $capturedFallbackType = null;
        $handler = $this->createHandler(function (ServerRequestInterface $request) use (&$capturedFallbackType): void {
            $capturedFallbackType = $request->getAttribute('language')->toArray()['fallbackType'];
        });

        $subject->process($request, $handler);

        self::assertSame('fallback', $capturedFallbackType);
    }

    #[Test]
    public function automaticModeReturnsFreeForStandAloneContent(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Pages.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/TtContent.csv');
        $subject = $this->createSubject(automaticMode: true);
        $language = $this->createLanguage(1);
        $request = (new ServerRequest())
            ->withAttribute('routing', new PageArguments(4, '0', []))
            ->withAttribute('language', $language);

        $capturedFallbackType = null;
        $handler = $this->createHandler(function (ServerRequestInterface $request) use (&$capturedFallbackType): void {
            $capturedFallbackType = $request->getAttribute('language')->toArray()['fallbackType'];
        });

        $subject->process($request, $handler);

        self::assertSame('free', $capturedFallbackType);
    }

    #[Test]
    public function automaticModeReturnsFallbackForConnectedContent(): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/Pages.csv');
        $subject = $this->createSubject(automaticMode: true);
        $language = $this->createLanguage(1);
        $request = (new ServerRequest())
            ->withAttribute('routing', new PageArguments(4, '0', []))
            ->withAttribute('language', $language);

        $capturedFallbackType = null;
        $handler = $this->createHandler(function (ServerRequestInterface $request) use (&$capturedFallbackType): void {
            $capturedFallbackType = $request->getAttribute('language')->toArray()['fallbackType'];
        });

        $subject->process($request, $handler);

        self::assertSame('fallback', $capturedFallbackType);
    }
}
