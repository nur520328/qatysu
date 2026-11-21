<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Хостинг панелі дерекқорды `utf8mb3` әдепкі кодталуымен ашады (Hoster.kz-те дәл солай болды).
 * Мұндағы тесттер дерекқор құрылымын өзгертеді, сондықтан транзакциямен емес,
 * кестелерді қайта құрумен оқшауланады.
 */
class InstallCharsetTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.telegram.org*' => Http::response('', 302)]);
    }

    protected function tearDown(): void
    {
        DB::statement('drop table if exists eski_kodtalu');
        $name = DB::connection()->getDatabaseName();
        DB::statement("alter database `{$name}` character set utf8mb4 collate utf8mb4_unicode_ci");
        parent::tearDown();
    }

    /** @return array{int, string} */
    private function install(): array
    {
        $buffer = new BufferedOutput;
        $exit = Artisan::call('qatysu:install', ['login' => 'zavuch'], $buffer);

        return [$exit, $buffer->fetch()];
    }

    public function test_utf8mb3_database_default_is_fixed_and_kazakh_text_survives(): void
    {
        $name = DB::connection()->getDatabaseName();
        DB::statement("alter database `{$name}` character set utf8mb3 collate utf8mb3_general_ci");

        [$exit, $output] = $this->install();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('utf8mb3 → utf8mb4', $output);
        $this->assertSame('utf8mb4', DB::selectOne('select @@character_set_database as c')->c);

        // utf8mb3 сақтай алмайтын таңбалар да (4 байттық) бүтін сақталады.
        $user = User::factory()->create(['full_name' => 'Әлібек Ұлықбекұлы 𠀀']);
        $this->assertSame('Әлібек Ұлықбекұлы 𠀀', $user->fresh()->full_name);
    }

    public function test_table_with_wrong_charset_stops_the_install(): void
    {
        DB::statement('create table eski_kodtalu (id int primary key, aty varchar(50)) character set latin1');

        [$exit, $output] = $this->install();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('eski_kodtalu', $output);
    }
}
