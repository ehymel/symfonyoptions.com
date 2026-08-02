<?php
declare(strict_types=1);

namespace App\Tests\Turnstile;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WidgetRenderSmokeTest extends WebTestCase
{
    public function testLoginPageRendersTurnstileWidget(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        $widget = $crawler->filter('#turnstile-widget');
        self::assertCount(1, $widget, 'login page must render one widget container');
        self::assertStringContainsString('cf-turnstile', (string) $widget->attr('class'));

        $scope = $crawler->filter('[data-controller="turnstile"]');
        self::assertSame('turnstile-spin-v2', $scope->attr('data-turnstile-action-value'));
        self::assertNotEmpty($scope->attr('data-turnstile-sitekey-value'), 'sitekey must be injected');
    }

    public function testForgotPasswordPageRendersTurnstileWidget(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/user/password/password/forgot/');

        self::assertResponseIsSuccessful();

        $widget = $crawler->filter('form div.cf-turnstile');
        self::assertCount(1, $widget, 'widget must sit inside the form so the token is submitted');

        $scope = $crawler->filter('form [data-controller="turnstile"]');
        self::assertCount(1, $scope, 'widget must be driven by the turnstile controller');
        self::assertSame('turnstile-spin-v2', $scope->attr('data-turnstile-action-value'));
        self::assertNotEmpty($scope->attr('data-turnstile-sitekey-value'), 'sitekey must be injected');

        // Turbo Drive swaps the body without re-running scripts, so implicit
        // rendering never fires. A hardcoded api.js tag also defines
        // window.turnstile in implicit mode, which suppresses the controller's
        // explicit render -- the exact bug this page regressed on before.
        self::assertCount(
            0,
            $crawler->filter('script[src^="https://challenges.cloudflare.com/turnstile/v0/api.js"]'),
            'api.js must be injected by the controller, never hardcoded in the template'
        );
    }

    /**
     * The gate must reject before the handler runs. A missing token short-circuits
     * in TurnstileVerifier, so this never reaches the database or the mailer.
     */
    public function testForgotPasswordRejectsSubmissionWithoutTurnstileToken(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/user/password/password/forgot/');

        $csrfToken = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/user/password/password/forgot/', [
            '_token' => $csrfToken,
            'email' => 'someone@example.com',
            // no cf-turnstile-response
        ]);

        self::assertResponseRedirects('/user/password/password/forgot/');
        self::assertEmailCount(0, 'no reset mail may be dispatched when verification fails');

        $client->followRedirect();
        self::assertSelectorTextContains('.alert', 'Bot verification failed');
    }
}
