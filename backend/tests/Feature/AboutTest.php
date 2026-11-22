<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthorPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** «Платформа туралы» беті және автордың суреті. Суреттер тест ішінде жасалады. */
class AboutTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        // Нақты storage қалтасына тимейміз.
        $this->dir = sys_get_temp_dir().'/qatysu-about-'.bin2hex(random_bytes(4));
        $this->app->useStoragePath($this->dir);
        mkdir($this->dir.'/framework/views', 0775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/app/about/author.jpg');
        @rmdir($this->dir.'/app/about');
        parent::tearDown();
    }

    private function image(string $type, int $width, int $height, bool $withExif = false): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 90, 200));
        $path = tempnam(sys_get_temp_dir(), 'qatysu-img');
        match ($type) {
            'png' => imagepng($image, $path),
            'webp' => imagewebp($image, $path),
            default => imagejpeg($image, $path, 90),
        };
        if ($withExif) {
            // JPEG басынан кейін жасанды EXIF бөлігін (ішінде «GPS» белгісі бар) қосамыз.
            $bytes = (string) file_get_contents($path);
            $payload = "Exif\0\0".'SYNAQ-GPS-51.1694N-71.4491E-TELEFON';
            file_put_contents($path, substr($bytes, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($bytes, 2));
        }

        return new UploadedFile($path, 'suret.'.$type, null, null, true);
    }

    public function test_about_is_public_and_shows_version_without_photo(): void
    {
        $this->getJson('/api/about')->assertOk()->assertExactJson(['version' => config('app.version'), 'photo' => null]);
        $this->get('/api/about/photo')->assertStatus(404);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+\.\d+$/', (string) config('app.version'));
    }

    public function test_admin_uploads_photo_which_is_resized_and_stripped_of_metadata(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $url = $this->post('/api/admin/about/photo', ['photo' => $this->image('jpg', 2400, 1600, withExif: true)], ['Accept' => 'application/json'])
            ->assertOk()->json('photo');
        $this->assertMatchesRegularExpression('#^/api/about/photo\?v=\d+$#', $url);

        $stored = app(AuthorPhoto::class)->path();
        $this->assertSame([720, 480, IMAGETYPE_JPEG], array_slice((array) getimagesize($stored), 0, 3));
        // Түсірілген орны сияқты қосымша дерек ашық бетке шықпауы тиіс.
        $this->assertStringNotContainsString('SYNAQ-GPS', (string) file_get_contents($stored));
        $this->assertStringNotContainsString('Exif', (string) file_get_contents($stored));

        // Сурет кірмеген адамға да көрінеді.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/about')->assertOk()->assertJsonPath('photo', $url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_png_and_webp_are_accepted_and_small_images_are_not_enlarged(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/api/admin/about/photo', ['photo' => $this->image('png', 300, 400)], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame([300, 400, IMAGETYPE_JPEG], array_slice((array) getimagesize(app(AuthorPhoto::class)->path()), 0, 3));

        $this->post('/api/admin/about/photo', ['photo' => $this->image('webp', 900, 1800)], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame([360, 720], array_slice((array) getimagesize(app(AuthorPhoto::class)->path()), 0, 2));
    }

    public function test_non_images_and_oversized_files_are_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $fake = tempnam(sys_get_temp_dir(), 'qatysu-img');
        file_put_contents($fake, '<?php echo "сурет емес";');

        $this->post('/api/admin/about/photo', ['photo' => new UploadedFile($fake, 'suret.jpg', 'image/jpeg', null, true)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'photo_invalid');
        $this->post('/api/admin/about/photo', ['photo' => $this->image('jpg', 50, 50)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'photo_invalid');
        file_put_contents($fake, str_repeat('x', AuthorPhoto::MAX_BYTES + 1));
        $this->post('/api/admin/about/photo', ['photo' => new UploadedFile($fake, 'suret.jpg', 'image/jpeg', null, true)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'photo_too_large');
        $this->postJson('/api/admin/about/photo')->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $this->assertFalse(app(AuthorPhoto::class)->exists());
    }

    public function test_only_admin_changes_the_photo_and_can_remove_it(): void
    {
        $this->post('/api/admin/about/photo', ['photo' => $this->image('jpg', 400, 400)], ['Accept' => 'application/json'])->assertStatus(401);
        $this->actingAs(User::factory()->create());
        $this->post('/api/admin/about/photo', ['photo' => $this->image('jpg', 400, 400)], ['Accept' => 'application/json'])->assertStatus(403);
        $this->deleteJson('/api/admin/about/photo')->assertStatus(403);

        $this->actingAs(User::factory()->admin()->create());
        $this->post('/api/admin/about/photo', ['photo' => $this->image('jpg', 400, 400)], ['Accept' => 'application/json'])->assertOk();
        $this->deleteJson('/api/admin/about/photo')->assertOk()->assertJsonPath('photo', null);
        $this->get('/api/about/photo')->assertStatus(404);
    }
}
