<?php

namespace Tests\Unit;

use App\Exceptions\AIImageProcessingException;
use App\Models\Photo;
use App\Services\AIService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\UsesIsolatedTempDirectory;
use Tests\TestCase;

/**
 * Regression: AIService created its temporary image in the shared system temp
 * directory while `app:cleanup-temp` only sweeps `storage/app/private/temp`,
 * so a hard kill/OOM orphaned `ai_img_*` files permanently.
 */
class AIServiceTempFileLocationTest extends TestCase
{
    use UsesIsolatedTempDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpIsolatedTempDirectory();

        config(['services.ai' => [
            'enabled' => true,
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-key',
            'model' => 'gpt-4o',
            'temporary_prefix' => 'ai_img_unit_',
        ]]);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedTempDirectory();

        parent::tearDown();
    }

    public function test_temporary_image_is_created_inside_the_swept_app_temp_directory_and_removed(): void
    {
        Http::fake();
        $this->useTemporaryStorageDisk('photos');
        $photo = $this->storePhoto('not-an-image');

        /** @var AIService $service */
        $service = new class extends AIService
        {
            public ?string $createdPath = null;

            protected function createTemporaryFile(): string
            {
                return $this->createdPath = parent::createTemporaryFile();
            }
        };

        try {
            $service->generateMetadata($photo);
            $this->fail('Expected an invalid image to be rejected.');
        } catch (AIImageProcessingException $exception) {
            $this->assertSame(AIImageProcessingException::REASON_DECODE, $exception->reason);
        }

        Http::assertNothingSent();

        $this->assertNotNull($service->createdPath);
        $this->assertStringStartsWith(
            $this->isolatedTempDir('ai').DIRECTORY_SEPARATOR,
            $service->createdPath,
            'The temporary image must live under the directory swept by app:cleanup-temp.',
        );
        $this->assertFileDoesNotExist($service->createdPath);
    }

    private function storePhoto(string $contents): Photo
    {
        $photo = new Photo;
        $photo->setAttribute('gallery_id', 'gallery-1');
        $photo->setAttribute('filename', 'sample.jpg');
        Storage::disk('photos')->put('gallery-1/sample.jpg', $contents);

        return $photo;
    }
}
