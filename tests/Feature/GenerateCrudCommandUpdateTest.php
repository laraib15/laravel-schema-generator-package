<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateCrudCommandUpdateTest extends TestCase
{
    protected array $generatedMigrationFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->generatedMigrationFiles as $path) {
            if (File::exists($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    protected function getMigrationFiles(): array
    {
        return collect(File::files(database_path('migrations')))
            ->map(fn ($file) => $file->getRealPath())
            ->all();
    }

    protected function generateMigrationForSchema(string $schema): string
    {
        $before = $this->getMigrationFiles();

        Artisan::call('generate:crud', ['schema' => $schema]);

        clearstatcache();

        $after = $this->getMigrationFiles();
        $newFiles = array_values(array_diff($after, $before));

        $this->assertNotEmpty($newFiles, 'No migration file was generated for schema: '.$schema);

        $this->generatedMigrationFiles = array_values(array_unique(
            array_merge($this->generatedMigrationFiles, $newFiles)
        ));

        return $newFiles[0];
    }

    #[Test]
    public function it_creates_table_with_initial_columns()
    {
        $tableName = uniqid('test_');
        $schema = "$tableName:id:uuid:primary,name:string,email:string:unique";
        $migrationFile = $this->generateMigrationForSchema($schema);

        $this->assertFileExists($migrationFile);

        $migrationContent = file_get_contents($migrationFile);

        $this->assertStringContainsString("\$table->uuid('id')->primary()", $migrationContent);
        $this->assertStringContainsString("\$table->string('name')", $migrationContent);
        $this->assertStringContainsString("\$table->string('email')->unique()", $migrationContent);
    }

    #[Test]
    public function it_generates_migration_to_add_columns()
    {
        $tableName = uniqid('test_');

        $initialSchema = "$tableName:id:uuid:primary,name:string";
        $createMigrationFile = $this->generateMigrationForSchema($initialSchema);
        $this->assertFileExists($createMigrationFile);

        // Run create migration so table exists
        $migrationInstance = include $createMigrationFile;
        $migrationInstance->up();

        $createMigrationContent = file_get_contents($createMigrationFile);
        $this->assertStringContainsString("Schema::create('$tableName'", $createMigrationContent);
        $this->assertStringContainsString("\$table->uuid('id')->primary()", $createMigrationContent);
        $this->assertStringContainsString("\$table->string('name')", $createMigrationContent);

        // Now add new column
        $updatedSchema = "$tableName:email:string:unique";
        $addColumnMigrationFile = $this->generateMigrationForSchema($updatedSchema);
        $this->assertFileExists($addColumnMigrationFile);

        $addColumnMigrationContent = file_get_contents($addColumnMigrationFile);
        $this->assertStringContainsString("Schema::table('$tableName'", $addColumnMigrationContent);
        $this->assertStringContainsString("\$table->string('email')->unique()", $addColumnMigrationContent);
    }

    #[Test]
    public function it_generates_migration_to_drop_column()
    {
        $tableName = uniqid('test_');

        $initialSchema = "$tableName:id:uuid:primary,name:string,email:string";
        $createMigration = $this->generateMigrationForSchema($initialSchema);
        $this->assertFileExists($createMigration);

        // Run create migration so table exists
        $migrationInstance = include $createMigration;
        $migrationInstance->up();

        $dropSchema = "$tableName:email:string:drop";
        $dropMigration = $this->generateMigrationForSchema($dropSchema);

        $this->assertFileExists($dropMigration);

        $migrationContent = file_get_contents($dropMigration);
        $this->assertStringContainsString("Schema::table('$tableName'", $migrationContent);
        $this->assertStringContainsString("\$table->dropColumn('email')", $migrationContent);
        $this->assertStringContainsString("\$table->string('email')", $migrationContent);
    }

    // NOTE: no MySQL/DBAL-sensitive modify-type test here
}
