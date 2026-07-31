<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

use App\Models\Submission;

class CursorPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_returns_a_cursor_for_the_next_page(): void
    {
        Submission::factory()->count(50)->create();

        $response = $this->getJson('/api/feed');

        $response->assertOk();
        $response->assertJsonStructure(['data', 'next_cursor', 'next_page_url']);
        $this->assertCount(20, $response->json('data'));
    }

    public function test_cursor_returns_the_next_distinct_page(): void
    {
        Submission::factory()->count(50)->create();

        // Page 1
        $page1 = $this->getJson('/api/feed');
        $cursor = $page1->json('next_cursor');
        $page1Ids = collect($page1->json('data'))->pluck('id');

        // Page 2 via cursor
        $page2 = $this->getJson("/api/feed?cursor={$cursor}");
        $page2Ids = collect($page2->json('data'))->pluck('id');

        // The key guarantee: no overlap between pages.
        $overlap = $page1Ids->intersect($page2Ids);
        $this->assertTrue($overlap->isEmpty(), 'Pages should not share rows');
    }
}
