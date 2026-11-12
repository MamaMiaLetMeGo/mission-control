<?php

namespace Tests\Feature;

use App\Services\PreviewProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PreviewProcessTest extends TestCase
{
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_preview_child_receives_structured_input_without_api_credentials(): void
    {
        $this->fixture(<<<'PHP'
<?php
$payload = json_decode(stream_get_contents(STDIN), true);
echo json_encode(['success' => true, 'preview' => null, 'hasKey' => getenv('OPENAI_API_KEY') !== false, 'mode' => $argv[1], 'taskId' => $payload['taskId']]);
PHP);
        $old = $_ENV['OPENAI_API_KEY'] ?? null;
        $_ENV['OPENAI_API_KEY'] = 'fake-secret-for-process-test';
        try {
            $result = app(PreviewProcess::class)->execute('status', ['taskId' => 42, 'previewRoot' => '/fixture/previews']);

            $this->assertFalse($result['hasKey']);
            $this->assertSame('status', $result['mode']);
            $this->assertSame(42, $result['taskId']);
        } finally {
            if ($old === null) {
                unset($_ENV['OPENAI_API_KEY']);
            } else {
                $_ENV['OPENAI_API_KEY'] = $old;
            }
        }
    }

    public function test_invalid_service_output_is_reported_without_a_preview_handle(): void
    {
        $this->fixture('<?php echo "not-json";');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The preview service could not complete this action.');

        app(PreviewProcess::class)->execute('start', ['taskId' => 1]);
    }

    #[TestWith(['start', 240])]
    #[TestWith(['stop', 45])]
    public function test_preview_operations_extend_the_php_request_limit_beyond_the_child_timeout(string $mode, int $expectedLimit): void
    {
        $this->fixture('<?php echo json_encode(["success" => true, "preview" => null]);');
        $previousLimit = (int) ini_get('max_execution_time');
        set_time_limit(30);
        try {
            app(PreviewProcess::class)->execute($mode, ['taskId' => 1]);

            $this->assertSame($expectedLimit, (int) ini_get('max_execution_time'));
        } finally {
            set_time_limit($previousLimit);
        }
    }

    private function fixture(string $source): void
    {
        $directory = storage_path('framework/testing/preview-process-'.Str::uuid());
        $this->directories[] = $directory;
        mkdir($directory, 0700, true);
        file_put_contents($directory.'/fixture.php', $source);
        config(['mission.node_binary' => PHP_BINARY, 'mission.preview_cli_path' => $directory.'/fixture.php']);
    }
}
