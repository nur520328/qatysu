<?php

namespace Tests;

use App\Console\Commands\Install;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Интерфейс пен API бір доменде тұрады. Sanctum сұранысты «өз интерфейсімізден»
        // деп тану үшін Origin тақырыбына қарайды, сондықтан тесттер де оны жібереді.
        $this->withHeader('Origin', config('app.url'));

        // Орнату белгісі файлда сақталады; бір тесттен екіншісіне өтпеуі тиіс.
        @unlink(Install::markerPath());
    }

    /**
     * Нақты кіру жасап, браузер сияқты сеанс cookie-сін келесі сұраныстарға қосады.
     * `actingAs` сеанссыз жұмыс істейді, сондықтан сеансқа қатысты тесттер осыны қолданады.
     */
    protected function signIn(User $user, string $password = UserFactory::DEFAULT_PASSWORD): TestResponse
    {
        $response = $this->postJson('/api/auth/login', ['login' => $user->login, 'password' => $password]);
        $response->assertOk();
        $this->adoptSessionCookie($response);

        return $response;
    }

    /** Жауаптағы (жаңартылған) сеанс cookie-сін келесі сұраныстарға қолданады. */
    protected function adoptSessionCookie(TestResponse $response): void
    {
        $name = config('session.cookie');
        $cookie = $response->getCookie($name);
        $this->assertNotNull($cookie, 'Жауапта сеанс cookie-сі жоқ.');
        $this->withCredentials()->withCookie($name, $cookie->getValue());

        // Тестте барлық сұраныс бір қосымша данасында орындалады, сондықтан алдыңғы
        // сұраныста анықталған пайдаланушы guard жадында қалады. Нақты серверде әр
        // сұраныс таза басталады; мұнда соны қолмен қайталаймыз.
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
    }
}
