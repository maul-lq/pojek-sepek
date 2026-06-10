<?php

namespace Tests\Feature;

use App\Models\Criteria;
use App\Support\DefaultCriteria;
use Database\Seeders\CriteriaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkinRecommendationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CriteriaSeeder::class);
    }

    public function test_welcome_page_renders_promethee_skin_form(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('SkinDecide');
        $response->assertSee('Promethee', false);
        $response->assertSee('data-criterias=', false);
        $response->assertSee('class="glitch-text" data-text="Terbaik">Terbaik</span>', false);
        $response->assertDontSee('<span>Skin</span> Terbaik</span></h1>', false);
        $response->assertDontSee('onsubmit="prosesHitung(event)"', false);
        $response->assertDontSee('onclick="tambahBarisSkin()"', false);
        $response->assertSee('id="btn-detail-calculation"', false);
        $response->assertSee('id="detail-calculation"', false);
    }

    public function test_recommendation_api_returns_ranked_skin_results(): void
    {
        $response = $this->postJson('/api/hitung-rekomendasi', [
            'alternatives' => [
                [
                    'name' => 'Skin Mahal Standar',
                    'scores' => [1 => 5000, 2 => 2, 3 => 3, 4 => 3, 5 => 3, 6 => 3, 7 => 3, 8 => 1],
                ],
                [
                    'name' => 'Skin Murah Epic',
                    'scores' => [1 => 1000, 2 => 5, 3 => 6, 4 => 6, 5 => 6, 6 => 7, 7 => 7, 8 => 2],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('rekomendasi.0.name', 'Skin Murah Epic')
            ->assertJsonPath('rekomendasi.0.rank', 1)
            ->assertJsonStructure([
                'status',
                'rekomendasi' => [
                    '*' => ['name', 'code', 'leaving_flow', 'entering_flow', 'net_flow', 'rank'],
                ],
                'perhitungan' => [
                    'rankings',
                    'alternatives',
                    'criteria',
                    'deviations',
                    'criterion_preferences',
                    'pairwise_comparisons',
                    'preference_indices',
                    'flows',
                ],
            ]);
    }

    public function test_recommendation_api_accepts_positional_score_keys_when_criteria_ids_have_drifted(): void
    {
        $criteria = collect(DefaultCriteria::recordsWithTimestamps())
            ->values()
            ->map(fn (array $record, int $index): array => [
                'id' => 57 + $index,
                ...$record,
            ])
            ->all();

        Criteria::query()->delete();
        Criteria::insert($criteria);

        $response = $this->postJson('/api/hitung-rekomendasi', [
            'alternatives' => [
                [
                    'name' => 'Skin Mahal Standar',
                    'scores' => [1 => 5000, 2 => 2, 3 => 3, 4 => 3, 5 => 3, 6 => 3, 7 => 3, 8 => 1],
                ],
                [
                    'name' => 'Skin Murah Epic',
                    'scores' => [1 => 1000, 2 => 5, 3 => 6, 4 => 6, 5 => 6, 6 => 7, 7 => 7, 8 => 2],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('rekomendasi.0.name', 'Skin Murah Epic');
    }

    public function test_browser_get_requests_to_the_recommendation_api_redirect_to_the_homepage(): void
    {
        $response = $this->get('/api/hitung-rekomendasi');

        $response->assertRedirect('/');
    }

    public function test_json_get_requests_to_the_recommendation_api_return_a_clear_405_response(): void
    {
        $response = $this->getJson('/api/hitung-rekomendasi');

        $response->assertStatus(405)
            ->assertHeader('Allow', 'POST')
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Gunakan metode POST untuk endpoint ini.');
    }

    public function test_recommendation_api_requires_two_skins(): void
    {
        $response = $this->postJson('/api/hitung-rekomendasi', [
            'alternatives' => [
                [
                    'name' => 'Skin Tunggal',
                    'scores' => [1 => 1000, 2 => 5, 3 => 6, 4 => 6, 5 => 6, 6 => 7, 7 => 7, 8 => 2],
                ],
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('status', 'error');
    }

    public function test_recommendation_api_returns_json_when_promethee_configuration_is_invalid(): void
    {
        Criteria::query()
            ->where('name', 'Harga (Diamond)')
            ->update(['preference_function' => 'tidak_didukung']);

        $response = $this->postJson('/api/hitung-rekomendasi', [
            'alternatives' => [
                [
                    'name' => 'Skin Mahal Standar',
                    'scores' => [1 => 5000, 2 => 2, 3 => 3, 4 => 3, 5 => 3, 6 => 3, 7 => 3, 8 => 1],
                ],
                [
                    'name' => 'Skin Murah Epic',
                    'scores' => [1 => 1000, 2 => 5, 3 => 6, 4 => 6, 5 => 6, 6 => 7, 7 => 7, 8 => 2],
                ],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Unsupported PROMETHEE preference type [tidak_didukung].');
    }

    public function test_recommendation_api_allows_flutter_web_cors_preflight(): void
    {
        $response = $this
            ->withHeaders([
                'Origin' => 'http://localhost:12345',
                'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'content-type, accept',
            ])
            ->options('/api/hitung-rekomendasi');

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $response->assertHeader('Access-Control-Allow-Methods');
        $response->assertHeader('Access-Control-Allow-Headers');
    }
}
