<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_root_redirects_to_the_admin_panel_without_a_version(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/admin');
        $this->assertStringNotContainsString(app()->version(), (string) $response->getContent());
    }
}
