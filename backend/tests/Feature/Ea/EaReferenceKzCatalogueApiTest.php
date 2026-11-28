<?php

declare(strict_types=1);

namespace Tests\Feature\Ea;

/** EUE-023/024: der Kontenplan wird aus dem gepinnten Artefakt ausgeliefert. */
class EaReferenceKzCatalogueApiTest extends EaBookkeepingTestCase
{
    public function test_reference_exposes_the_pinned_chart_of_accounts(): void
    {
        $token = $this->tokenFor();

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/management/ea/reference');
        $response->assertOk();

        $catalogue = $response->json('kz_catalogue');

        $this->assertSame(27, $catalogue['distinct_expense_count']);
        $this->assertCount(27, $catalogue['expense_codes']);
        $this->assertSame(['9040', '9050', '9060', '9090', '9460'], $catalogue['income_codes']);
        $this->assertContains('9135', $catalogue['e1b_codes']);
        $this->assertContains('9225', $catalogue['expense_codes']);
    }

    public function test_reference_requires_authentication(): void
    {
        $this->getJson('/api/management/ea/reference')->assertUnauthorized();
    }
}
