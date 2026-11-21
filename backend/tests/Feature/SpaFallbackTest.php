<?php

namespace Tests\Feature;

use Tests\TestCase;

class SpaFallbackTest extends TestCase
{
    private string $index;

    private bool $created = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->index = public_path('index.html');
        if (! is_file($this->index)) {
            file_put_contents($this->index, '<!doctype html><title>synaq</title><div id="root"></div>');
            $this->created = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->created) {
            @unlink($this->index);
        }
        parent::tearDown();
    }

    public function test_any_non_api_path_returns_the_interface_without_caching(): void
    {
        foreach (['/', '/kiru', '/synyp/7a'] as $path) {
            $response = $this->get($path);
            $response->assertOk();
            $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
            $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        }
    }

    public function test_unknown_api_path_is_not_swallowed_by_the_interface(): void
    {
        $this->getJson('/api/zhoq-zhol')->assertStatus(404)->assertExactJson(['code' => 'not_found']);
    }
}
