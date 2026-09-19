<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Act;
use App\Models\ActMember;
use App\Models\Customer;
use App\Models\ModelProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\ModelFileStore;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ModelManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        Storage::fake('local');
    }

    private function userWithRole(UserRole $role, ?string $brand = 'rp'): User
    {
        $user = User::factory()->create(['brand' => $brand]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));

        return $user;
    }

    /**
     * @param  array<int, string>  $categories
     * @return array<int, array<string, mixed>>
     */
    private function experienceAnswers(array $categories): array
    {
        return array_map(static fn (string $category) => [
            'scope' => 'person',
            'key' => 'experience_'.$category,
            'label' => 'Erfahrung: '.$category,
            'type' => 'select',
            'value' => '+',
        ], $categories);
    }

    /**
     * @param  array<string, string>  $willingness
     * @return array<int, array<string, mixed>>
     */
    private function willingnessAnswers(array $willingness): array
    {
        return array_map(static fn (string $category, string $level) => [
            'scope' => 'person',
            'key' => 'willingness_'.$category,
            'label' => 'Bereitschaft: '.$category,
            'type' => 'select',
            'value' => $level,
        ], array_keys($willingness), array_values($willingness));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function experienceAnswer(string $category, string $level): array
    {
        return [[
            'scope' => 'person',
            'key' => 'experience_'.$category,
            'label' => 'Erfahrung: '.$category,
            'type' => 'select',
            'value' => $level,
        ]];
    }

    private function profile(?string $brand = 'rp', array $customerAttributes = [], array $profileAttributes = []): ModelProfile
    {
        $customer = Customer::factory()->create(array_merge(['brand' => $brand, 'is_model' => true], $customerAttributes));

        return ModelProfile::create(array_merge([
            'customer_id' => $customer->id,
            'catalog_version' => 'v1',
            'answers' => [],
            'gender' => null,
            'age_proof_required' => false,
            'submitted_at' => now(),
        ], $profileAttributes));
    }

    public function test_admin_can_list_models_brand_scoped(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $this->profile('rp');
        $this->profile('rp');
        $this->profile('srp');

        $response = $this->actingAs($admin, 'api')->getJson('/api/management/models');

        $response->assertOk()->assertJsonCount(2);
    }

    public function test_model_list_filters_by_gender_and_city(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $this->profile('rp', ['city' => 'Linz'], ['gender' => 'female']);
        $this->profile('rp', ['city' => 'Wien'], ['gender' => 'male']);

        $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?gender=female')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.gender', 'female');

        $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?city=Wien')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.city', 'Wien');
    }

    public function test_model_list_filters_by_category(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $this->profile('rp', [], ['answers' => $this->experienceAnswers(['bikini'])]);
        $this->profile('rp', [], ['answers' => $this->experienceAnswers(['portrait'])]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?category=bikini');

        $response->assertOk()->assertJsonCount(1);
        $this->assertSame(['bikini'], $response->json('0.categories'));
    }

    public function test_model_list_filters_by_multiple_categories_with_or(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $this->profile('rp', [], ['answers' => $this->experienceAnswers(['bikini'])]);
        $this->profile('rp', [], ['answers' => $this->experienceAnswers(['portrait'])]);
        $this->profile('rp', [], ['answers' => $this->experienceAnswers(['sport'])]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?category[]=bikini&category[]=sport');

        $response->assertOk()->assertJsonCount(2);
        $categories = collect($response->json())->pluck('categories')->flatten()->unique()->sort()->values()->all();
        $this->assertSame(['bikini', 'sport'], $categories);
    }

    public function test_willingness_filter_is_a_minimum_threshold(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $this->profile('rp', ['name' => 'Nein'], ['answers' => $this->willingnessAnswers(['portrait' => 'nein'])]);
        $this->profile('rp', ['name' => 'EherNicht'], ['answers' => $this->willingnessAnswers(['portrait' => 'eher_nicht'])]);
        $this->profile('rp', ['name' => 'Muss'], ['answers' => $this->willingnessAnswers(['portrait' => 'wenn_es_sein_muss'])]);
        $this->profile('rp', ['name' => 'Gerne'], ['answers' => $this->willingnessAnswers(['portrait' => 'gerne'])]);
        $this->profile('rp', ['name' => 'Sehr'], ['answers' => $this->willingnessAnswers(['portrait' => 'sehr_gerne'])]);

        // Threshold `gerne` includes `gerne` + `sehr_gerne` (and excludes below).
        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?willingness_portrait=gerne');

        $response->assertOk()->assertJsonCount(2);
        $names = array_column($response->json(), 'display_name');
        sort($names);
        $this->assertSame(['Gerne', 'Sehr'], $names);
    }

    public function test_willingness_filter_threshold_is_or_across_categories(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');

        // Reaches the sport threshold only.
        $this->profile('rp', ['name' => 'Sport'], ['answers' => array_merge(
            $this->willingnessAnswers(['portrait' => 'nein']),
            $this->willingnessAnswers(['sport' => 'gerne']),
        )]);
        // Reaches neither threshold.
        $this->profile('rp', ['name' => 'Keins'], ['answers' => array_merge(
            $this->willingnessAnswers(['portrait' => 'nein']),
            $this->willingnessAnswers(['sport' => 'eher_nicht']),
        )]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?willingness_portrait=sehr_gerne&willingness_sport=gerne');

        $response->assertOk()->assertJsonCount(1);
        $this->assertSame('Sport', $response->json('0.display_name'));
    }

    public function test_willingness_filter_requires_a_known_value(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $this->profile('rp', ['name' => 'Ohne'], ['answers' => []]);
        $this->profile('rp', ['name' => 'Nein'], ['answers' => $this->willingnessAnswers(['portrait' => 'nein'])]);

        // Threshold `nein` (ordinal 0) matches `nein`, but not a missing value.
        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?willingness_portrait=nein');

        $response->assertOk()->assertJsonCount(1);
        $this->assertSame('Nein', $response->json('0.display_name'));
    }

    public function test_model_list_supports_willingness_category_alias_filter(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $this->profile('rp', [], ['answers' => $this->willingnessAnswers(['portrait' => 'sehr_gerne'])]);
        $this->profile('rp', [], ['answers' => $this->willingnessAnswers(['portrait' => 'nein'])]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?willingness_category=portrait&willingness_level=sehr_gerne')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_model_list_filters_by_willingness_stock(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $this->profile('rp', [], ['answers' => $this->willingnessAnswers(['stock' => 'sehr_gerne'])]);
        $this->profile('rp', [], ['answers' => $this->willingnessAnswers(['stock' => 'nein'])]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?willingness_stock=sehr_gerne')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_model_list_sorts_by_willingness_descending(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $this->profile('rp', ['name' => 'Nein'], ['answers' => $this->willingnessAnswers(['portrait' => 'nein'])]);
        $this->profile('rp', ['name' => 'Sehr'], ['answers' => $this->willingnessAnswers(['portrait' => 'sehr_gerne'])]);
        $this->profile('rp', ['name' => 'Gerne'], ['answers' => $this->willingnessAnswers(['portrait' => 'gerne'])]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?sort=willingness&willingness_category=portrait');

        $response->assertOk()->assertJsonCount(3);
        $this->assertSame(['Sehr', 'Gerne', 'Nein'], array_column($response->json(), 'display_name'));
    }

    public function test_model_list_sorts_by_experience_with_willingness_tiebreak(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');

        $this->profile('rp', ['name' => 'DoppelPlus'], ['answers' => array_merge(
            $this->experienceAnswer('portrait', '++'),
            $this->willingnessAnswers(['portrait' => 'nein']),
        )]);
        // Tie on `+`: the higher willingness wins the tiebreak.
        $this->profile('rp', ['name' => 'PlusGerne'], ['answers' => array_merge(
            $this->experienceAnswer('portrait', '+'),
            $this->willingnessAnswers(['portrait' => 'sehr_gerne']),
        )]);
        $this->profile('rp', ['name' => 'PlusNein'], ['answers' => array_merge(
            $this->experienceAnswer('portrait', '+'),
            $this->willingnessAnswers(['portrait' => 'nein']),
        )]);
        // `category=portrait` also filters to profiles with portrait experience,
        // so a profile without it is excluded (and never reaches the sort).
        $this->profile('rp', ['name' => 'Keine'], ['answers' => $this->willingnessAnswers(['portrait' => 'sehr_gerne'])]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?sort=experience&category=portrait');

        $response->assertOk()->assertJsonCount(3);
        $this->assertSame(
            ['DoppelPlus', 'PlusGerne', 'PlusNein'],
            array_column($response->json(), 'display_name')
        );
    }

    public function test_model_list_sorts_by_experience_across_categories_without_category(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');

        $this->profile('rp', ['name' => 'VielErfahren'], ['answers' => array_merge(
            $this->experienceAnswer('portrait', '++'),
            $this->willingnessAnswers(['portrait' => 'nein']),
        )]);
        // Tie at `+` (max experience): higher max willingness wins the tiebreak.
        $this->profile('rp', ['name' => 'TieGerne'], ['answers' => array_merge(
            $this->experienceAnswer('sport', '+'),
            $this->willingnessAnswers(['sport' => 'sehr_gerne']),
        )]);
        $this->profile('rp', ['name' => 'TieNein'], ['answers' => array_merge(
            $this->experienceAnswer('portrait', '+'),
            $this->willingnessAnswers(['portrait' => 'nein']),
        )]);
        $this->profile('rp', ['name' => 'Keine'], ['answers' => array_merge(
            $this->experienceAnswer('portrait', '--'),
            $this->willingnessAnswers(['portrait' => 'sehr_gerne']),
        )]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?sort=experience');

        $response->assertOk()->assertJsonCount(4);
        $this->assertSame(
            ['VielErfahren', 'TieGerne', 'TieNein', 'Keine'],
            array_column($response->json(), 'display_name')
        );
    }

    public function test_model_list_default_sort_prioritizes_match_score(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');

        // Willing but inexperienced (score 30).
        $this->profile('rp', ['name' => 'BereitUnerfahren'], ['answers' => array_merge(
            $this->experienceAnswer('portrait', '--'),
            $this->willingnessAnswers(['portrait' => 'gerne']),
        )]);
        // Experienced but unwilling (score 4).
        $this->profile('rp', ['name' => 'UnwilligErfahren'], ['answers' => array_merge(
            $this->experienceAnswer('portrait', '++'),
            $this->willingnessAnswers(['portrait' => 'nein']),
        )]);

        $response = $this->actingAs($admin, 'api')->getJson('/api/management/models');

        $response->assertOk()->assertJsonCount(2);
        $this->assertSame(
            ['BereitUnerfahren', 'UnwilligErfahren'],
            array_column($response->json(), 'display_name')
        );
    }

    public function test_model_list_default_sort_tiebreak_is_newest(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $answers = array_merge(
            $this->experienceAnswer('portrait', '--'),
            $this->willingnessAnswers(['portrait' => 'gerne']),
        );

        $this->profile('rp', ['name' => 'Alt'], ['answers' => $answers, 'submitted_at' => now()->subHour()]);
        $this->profile('rp', ['name' => 'Neu'], ['answers' => $answers, 'submitted_at' => now()]);

        $response = $this->actingAs($admin, 'api')->getJson('/api/management/models');

        $response->assertOk();
        $this->assertSame(['Neu', 'Alt'], array_column($response->json(), 'display_name'));
    }

    public function test_model_list_sort_newest_uses_submitted_order(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');

        // Older but higher match score.
        $this->profile('rp', ['name' => 'AltScore'], [
            'answers' => array_merge(
                $this->experienceAnswer('portrait', '--'),
                $this->willingnessAnswers(['portrait' => 'gerne']),
            ),
            'submitted_at' => now()->subHour(),
        ]);
        // Newer but lower match score.
        $this->profile('rp', ['name' => 'NeuLow'], [
            'answers' => array_merge(
                $this->experienceAnswer('portrait', '--'),
                $this->willingnessAnswers(['portrait' => 'nein']),
            ),
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?sort=newest');

        $response->assertOk();
        $this->assertSame(['NeuLow', 'AltScore'], array_column($response->json(), 'display_name'));
    }

    public function test_model_serialization_exposes_willingness_map(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $this->profile('rp', [], [
            'answers' => $this->willingnessAnswers(['portrait' => 'sehr_gerne', 'stock' => 'gerne']),
        ]);

        $response = $this->actingAs($admin, 'api')->getJson('/api/management/models');

        $response->assertOk();
        $this->assertSame('sehr_gerne', $response->json('0.willingness.willingness_portrait'));
        $this->assertSame('gerne', $response->json('0.willingness.willingness_stock'));
    }

    public function test_model_list_filters_by_age_range(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $this->profile('rp', ['birthdate' => now()->subYears(25)->subDay()->format('Y-m-d')]);
        $this->profile('rp', ['birthdate' => now()->subYears(45)->subDay()->format('Y-m-d')]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?age_min=40')
            ->assertOk()
            ->assertJsonCount(1);

        $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?age_max=30')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_model_list_filters_by_combined_age_range(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $this->profile('rp', ['birthdate' => now()->subYears(20)->subDay()->format('Y-m-d')]);
        $this->profile('rp', ['birthdate' => now()->subYears(30)->subDay()->format('Y-m-d')]);
        $this->profile('rp', ['birthdate' => now()->subYears(50)->subDay()->format('Y-m-d')]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?age_min=25&age_max=35');

        $response->assertOk()->assertJsonCount(1);
        $this->assertSame(30, $response->json('0.age'));
    }

    public function test_model_list_filters_by_search_term(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $this->profile('rp', ['name' => 'Anna Beispiel', 'email' => 'anna@example.com', 'city' => 'Linz']);
        $this->profile('rp', ['name' => 'Bea Muster', 'email' => 'bea@example.com', 'city' => 'Wien']);

        // name
        $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?q=Anna')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.display_name', 'Anna Beispiel');

        // e-mail (case-insensitive)
        $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?q=bea@example')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.display_name', 'Bea Muster');
    }

    public function test_model_list_filters_by_country(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $this->profile('rp', ['country' => 'Österreich']);
        $this->profile('rp', ['country' => 'Deutschland']);

        $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?country=Deutschland')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.country', 'Deutschland');
    }

    public function test_model_list_filters_by_act_type(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $couple = $this->profile('rp');
        $single = $this->profile('rp');

        $act = Act::create([
            'brand' => 'rp',
            'manager_customer_id' => $couple->customer_id,
            'act_type' => 'couple',
            'catalog_version' => 'v1',
            'answers' => [],
            'person_count' => 2,
            'submitted_at' => now(),
        ]);
        ActMember::create(['act_id' => $act->id, 'customer_id' => $couple->customer_id, 'role' => 'manager', 'position' => 0]);
        ActMember::create(['act_id' => $act->id, 'customer_id' => $single->customer_id, 'role' => 'member', 'position' => 1]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/management/models?act_type=couple');

        $response->assertOk()->assertJsonCount(2);
    }

    public function test_photographer_cannot_list_models(): void
    {
        $photographer = $this->userWithRole(UserRole::PHOTOGRAPHER);

        $this->actingAs($photographer, 'api')
            ->getJson('/api/management/models')
            ->assertStatus(403);
    }

    public function test_client_cannot_list_models(): void
    {
        $client = $this->userWithRole(UserRole::CLIENT);

        $this->actingAs($client, 'api')
            ->getJson('/api/management/models')
            ->assertStatus(403);
    }

    public function test_admin_can_download_age_proof(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $profile = $this->profile('rp', [], [
            'age_proof_required' => true,
            'age_proof_path' => 'model-age-proofs/abc/ausweis.jpg',
            'age_proof_uploaded_at' => now(),
        ]);
        app(ModelFileStore::class)->putEncrypted($profile->age_proof_path, 'secret-id');

        $response = $this->actingAs($admin, 'api')
            ->get("/api/management/models/{$profile->id}/age-proof");

        $response->assertOk()
            ->assertDownload('altersnachweis.jpg')
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_age_proof_download_returns_404_when_missing(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $profile = $this->profile('rp');

        $this->actingAs($admin, 'api')
            ->getJson("/api/management/models/{$profile->id}/age-proof")
            ->assertNotFound();
    }

    public function test_age_proof_download_is_brand_scoped(): void
    {
        $admin = $this->userWithRole(UserRole::ADMIN, 'rp');
        $profile = $this->profile('srp', [], [
            'age_proof_required' => true,
            'age_proof_path' => 'model-age-proofs/foreign/ausweis.jpg',
        ]);
        Storage::disk('local')->put($profile->age_proof_path, 'secret-id');

        $this->actingAs($admin, 'api')
            ->getJson("/api/management/models/{$profile->id}/age-proof")
            ->assertNotFound();
    }
}
