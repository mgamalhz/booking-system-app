<?php

namespace Tests\Feature;

use Tests\TestCase;

class NPlusOneDemoTest extends TestCase
{
    public function test_query_growth_and_identical_results(): void
    {
        foreach ([5, 20, 50] as $count) {
            $lazy = $this->getJson("/demo/n-plus-one?mode=lazy&count=$count")->assertOk();
            $eager = $this->getJson("/demo/n-plus-one?mode=eager&count=$count")->assertOk();
            $lazy->assertJsonPath('query_count', 1 + 3 * $count);
            $eager->assertJsonPath('query_count', 4);
            $this->assertSame($lazy->json('rows'), $eager->json('rows'));
            $this->getJson("/demo/n-plus-one?mode=count&count=$count")
                ->assertOk()->assertJsonPath('query_count', 1)
                ->assertJsonPath('rows.0.documents_count', 3)->assertJsonCount($count, 'rows');
        }
    }

    public function test_browser_view_and_input_validation(): void
    {
        $this->get('/demo/n-plus-one')->assertOk()->assertSee('ELOQUENT / QUERY LAB');
        $this->getJson('/demo/n-plus-one?count=10000')->assertUnprocessable();
        $this->getJson('/demo/n-plus-one?mode=invalid')->assertUnprocessable();
    }

    public function test_demo_is_unavailable_in_production(): void
    {
        $this->app->instance('env', 'production');
        $this->getJson('/demo/n-plus-one')->assertNotFound();
    }
}
