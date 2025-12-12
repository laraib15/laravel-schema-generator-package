<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateCrudCommandTest extends TestCase
{
    /**
     * Track migration files generated in this test class
     * so we can delete them in tearDown().
     *
     * @var string[]
     */
    protected array $generatedMigrationFiles = [];

    protected function tearDown(): void
    {
        // Delete any migration files created during these tests
        foreach ($this->generatedMigrationFiles as $path) {
            if (is_string($path) && file_exists($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    /**
     * Return all current migration file real paths.
     */
    protected function getMigrationFiles(): array
    {
        return collect(File::files(database_path('migrations')))
            ->map(fn ($file) => $file->getRealPath())
            ->all();
    }

    /**
     * Helper: call generate:crud, return [exitCode, output, newFiles[]].
     *
     * - Tracks newly created migration files (for cleanup).
     */
    protected function callGenerateCrud(string $schema): array
    {
        $before = $this->getMigrationFiles();

        $exitCode = Artisan::call('generate:crud', ['schema' => $schema]);

        // avoid stale FS cache
        clearstatcache();

        $after = $this->getMigrationFiles();
        $newFiles = array_values(array_diff($after, $before));
        $output = Artisan::output();

        // Track only when command "succeeds" (exitCode 0)
        if ($exitCode === 0 && !empty($newFiles)) {
            $this->generatedMigrationFiles = array_values(array_unique(
                array_merge($this->generatedMigrationFiles, $newFiles)
            ));
        }

        return [$exitCode, $output, $newFiles];
    }

    #[Test]
    public function it_runs_with_basic_schema_and_succeeds()
    {
        $tableName = uniqid('test_');
        $schema = "$tableName:id:uuid:primary,user_id:foreignId:constrained=users:onDelete=cascade,status:enum:values=pending|processing|completed|cancelled";

        [$exitCode, $output, $newFiles] = $this->callGenerateCrud($schema);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Parsing schema:', $output);
        $this->assertStringContainsString('Migration generation completed successfully.', $output);

        $this->assertNotEmpty($newFiles);
        $migrationFile = $newFiles[0];
        $this->assertFileExists($migrationFile);
    }

    #[Test]
    public function it_handles_comments_on_columns()
    {
        $tableName = uniqid('test_');
        $schema = "$tableName:id:uuid:primary:comment=Primary key,user_id:foreignId:constrained=users:onDelete=cascade:comment=References users table";

        [$exitCode, $output, $newFiles] = $this->callGenerateCrud($schema);

        $this->assertEquals(0, $exitCode);
        $this->assertNotEmpty($newFiles);

        $migrationFile = $newFiles[0];
        $this->assertFileExists($migrationFile);

        $migrationContent = file_get_contents($migrationFile);

        $this->assertStringContainsString("->comment('Primary key')", $migrationContent);
        $this->assertStringContainsString("->comment('References users table')", $migrationContent);
    }

    #[Test]
    public function it_parses_nullable_and_default_values()
    {
        $tableName = uniqid('test_');
        $schema = "$tableName:status:enum:values=pending|processing|completed|cancelled:nullable:default=pending";

        [$exitCode, $output, $newFiles] = $this->callGenerateCrud($schema);

        $this->assertEquals(0, $exitCode);
        $this->assertNotEmpty($newFiles);

        $migrationFile = $newFiles[0];
        $this->assertFileExists($migrationFile);

        $migrationContent = file_get_contents($migrationFile);
        $this->assertStringContainsString('->nullable()', $migrationContent);
        $this->assertStringContainsString("->default('pending')", $migrationContent);
    }

    #[Test]
    public function it_parses_composite_unique_index()
    {
        $tableName = uniqid('test_');
        $schema = "$tableName:user_id:foreignId:constrained=users:onDelete=cascade,ordered_at:timestamp:nullable,unique:user_id|ordered_at";

        [$exitCode, $output, $newFiles] = $this->callGenerateCrud($schema);

        $this->assertEquals(0, $exitCode);
        $this->assertNotEmpty($newFiles);

        $migrationFile = $newFiles[0];
        $this->assertFileExists($migrationFile);

        $migrationContent = file_get_contents($migrationFile);

        $this->assertStringContainsString("->unique(['user_id', 'ordered_at'])", $migrationContent);
    }

    #[Test]
    public function it_fails_with_invalid_column_type()
    {
        $tableName = uniqid('test_');
        $schema = "$tableName:bad_column:unknownType";

        [$exitCode, $output, $newFiles] = $this->callGenerateCrud($schema);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('Migration aborted due to invalid column types.', $output);

        // No migration file should be generated on failure
        $this->assertEmpty($newFiles);
    }
}
