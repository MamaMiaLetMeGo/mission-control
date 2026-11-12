<?php

namespace Tests\Feature;

use App\Services\ProductionVerification;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProductionVerificationTest extends TestCase
{
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_import_binds_successful_commands_and_output_hashes_to_the_exact_release(): void
    {
        [$repository, $plan, $receipt, $receiptPath] = $this->fixture();

        $result = app(ProductionVerification::class)->read($repository, $plan, 'release/checks.json');

        $this->assertSame('passed', $result['state']);
        $this->assertSame($plan['hash'], $result['plan_hash']);
        $this->assertSame($plan['release_revision'], $result['head_revision']);
        $this->assertSame(hash_file('sha256', $receiptPath), $result['receipt_digest']);
        $this->assertCount(count($receipt['checks']), $result['checks']);
        $this->assertSame(0, $result['checks'][0]['exit_code']);
        $this->assertSame($result, app(ProductionVerification::class)->read($repository, $plan, 'release/checks.json', $result['receipt_digest']));
    }

    #[TestWith(['plan'])]
    #[TestWith(['revision'])]
    #[TestWith(['tree'])]
    #[TestWith(['missing_check'])]
    #[TestWith(['changed_command'])]
    #[TestWith(['failed_check'])]
    #[TestWith(['duplicate_check'])]
    #[TestWith(['invalid_timestamp'])]
    #[TestWith(['malformed_check'])]
    #[TestWith(['malformed_command'])]
    public function test_unrelated_or_incomplete_verification_cannot_be_imported(string $mutation): void
    {
        [$repository, $plan, $receipt, $receiptPath] = $this->fixture();
        switch ($mutation) {
            case 'plan':
                $receipt['planHash'] = str_repeat('b', 64);
                break;
            case 'revision':
                $receipt['revision'] = str_repeat('b', 40);
                break;
            case 'tree':
                $receipt['tree'] = str_repeat('b', 40);
                break;
            case 'missing_check':
                array_pop($receipt['checks']);
                break;
            case 'changed_command':
                $receipt['checks'][0]['command'] = ['true'];
                break;
            case 'failed_check':
                $receipt['checks'][0]['exitCode'] = 1;
                break;
            case 'duplicate_check':
                $receipt['checks'][] = $receipt['checks'][0];
                break;
            case 'invalid_timestamp':
                $receipt['completedAt'] = 'not a timestamp';
                break;
            case 'malformed_check':
                $receipt['checks'][0] = 'not a check';
                break;
            case 'malformed_command':
                $receipt['checks'][0]['command'] = [['not a string']];
                break;
        }
        file_put_contents($receiptPath, json_encode($receipt, JSON_THROW_ON_ERROR));
        $this->expectException(ValidationException::class);

        app(ProductionVerification::class)->read($repository, $plan, 'release/checks.json');
    }

    public function test_changed_check_output_invalidates_an_imported_receipt(): void
    {
        [$repository, $plan, $receipt, $receiptPath] = $this->fixture();
        $result = app(ProductionVerification::class)->read($repository, $plan, 'release/checks.json');
        file_put_contents(dirname($receiptPath).'/'.$receipt['checks'][0]['outputFile'], 'Replaced output');
        $this->expectException(ValidationException::class);

        app(ProductionVerification::class)->read($repository, $plan, 'release/checks.json', $result['receipt_digest']);
    }

    public function test_changed_receipt_invalidates_prior_verification_even_if_its_checks_still_match(): void
    {
        [$repository, $plan, $receipt, $receiptPath] = $this->fixture();
        $result = app(ProductionVerification::class)->read($repository, $plan, 'release/checks.json');
        $receipt['completedAt'] = '2026-11-08T16:00:00Z';
        file_put_contents($receiptPath, json_encode($receipt, JSON_THROW_ON_ERROR));
        $this->expectException(ValidationException::class);

        app(ProductionVerification::class)->read($repository, $plan, 'release/checks.json', $result['receipt_digest']);
    }

    #[TestWith(['dirty'])]
    #[TestWith(['moved'])]
    public function test_worktree_changes_invalidate_verification(string $mutation): void
    {
        [$repository, $plan] = $this->fixture();
        file_put_contents($repository.'/source.txt', 'Changed source');
        if ($mutation === 'moved') {
            $this->git($repository, ['add', 'source.txt']);
            $this->git($repository, ['commit', '-m', 'Changed release']);
        }
        $this->expectException(ValidationException::class);

        app(ProductionVerification::class)->read($repository, $plan, 'release/checks.json');
    }

    public function test_symlinked_output_cannot_escape_the_receipt_directory(): void
    {
        [$repository, $plan, $receipt, $receiptPath] = $this->fixture();
        $output = dirname($receiptPath).'/'.$receipt['checks'][0]['outputFile'];
        $external = dirname($repository).'/outside.log';
        rename($output, $external);
        symlink($external, $output);
        $this->expectException(ValidationException::class);

        app(ProductionVerification::class)->read($repository, $plan, 'release/checks.json');
    }

    public function test_symlinked_receipt_cannot_escape_the_verification_directory(): void
    {
        [$repository, $plan, , $receiptPath] = $this->fixture();
        $external = dirname($repository).'/outside.json';
        rename($receiptPath, $external);
        symlink($external, $receiptPath);
        $this->expectException(ValidationException::class);

        app(ProductionVerification::class)->read($repository, $plan, 'release/checks.json');
    }

    /** @return array{string, array<string, mixed>, array<string, mixed>, string} */
    private function fixture(): array
    {
        $root = sys_get_temp_dir().'/mission-production-verification-'.Str::uuid();
        $this->directories[] = $root;
        $repository = $root.'/repository';
        mkdir($repository, 0700, true);
        mkdir($root.'/verification/release', 0700, true);
        config(['mission.production_verification_root' => $root.'/verification']);
        $this->git($repository, ['init', '-b', 'release']);
        $this->git($repository, ['config', 'user.name', 'Verification fixture']);
        $this->git($repository, ['config', 'user.email', 'verification@example.test']);
        file_put_contents($repository.'/source.txt', "Reviewed source\n");
        $this->git($repository, ['add', 'source.txt']);
        $this->git($repository, ['commit', '-m', 'Release source']);
        $plan = ['hash' => str_repeat('a', 64), 'release_revision' => $this->git($repository, ['rev-parse', 'HEAD']), 'tree_revision' => $this->git($repository, ['rev-parse', 'HEAD^{tree}'])];
        $checks = [];
        foreach (config('mission.production_required_checks') as $name => $check) {
            $outputFile = $name.'.log';
            $output = 'Fixture passed output for '.$name."\n";
            file_put_contents($root.'/verification/release/'.$outputFile, $output);
            $checks[] = [...$check, 'name' => $name, 'exitCode' => 0, 'outputFile' => $outputFile, 'outputSha256' => hash('sha256', $output)];
        }
        $receipt = ['version' => 1, 'planHash' => $plan['hash'], 'revision' => $plan['release_revision'], 'tree' => $plan['tree_revision'], 'worktreePath' => $repository, 'completedAt' => '2026-11-08T15:00:00Z', 'checks' => $checks];
        $receiptPath = $root.'/verification/release/checks.json';
        file_put_contents($receiptPath, json_encode($receipt, JSON_THROW_ON_ERROR));

        return [$repository, $plan, $receipt, $receiptPath];
    }

    /** @param list<string> $arguments */
    private function git(string $directory, array $arguments): string
    {
        $process = new Process(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', $directory, ...$arguments]);
        $process->setTimeout(15);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
