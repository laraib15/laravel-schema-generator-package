<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('mysql')]
class GenerateCrudCommandModifyMySqlTest extends TestCase
{
    /**
     * Migration files generated during these tests
     * so we can delete them in tearDown().
     *
     * @var string[]
     */
    protected array $generatedMigrationFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Only run if explicitly enabled
        if (! env('CRUD_MYSQL_TEST_ENABLED', false)) {
            $this->markTestSkipped('MySQL integration tests are disabled (CRUD_MYSQL_TEST_ENABLED=false).');
        }

        // Force MySQL config, DO NOT read DB_DATABASE / DB_CONNECTION here
        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => [
                'driver' => 'mysql',
                'host' => env('CRUD_MYSQL_HOST', '127.0.0.1'),
                'port' => env('CRUD_MYSQL_PORT', '3306'),
                'database' => env('CRUD_MYSQL_DATABASE', 'crud_testing'),
                'username' => env('CRUD_MYSQL_USERNAME', 'root'),
                'password' => env('CRUD_MYSQL_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'engine' => null,
            ],
        ]);

        // Reset and reconnect using the new config
        DB::purge('mysql');
        DB::reconnect('mysql');

        // Explicitly set default connection
        DB::setDefaultConnection('mysql');

        // Verify MySQL is actually reachable
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL is not reachable: '.$e->getMessage());
        }

        // Clean schema
        Schema::dropAllTables();
    }

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

    /**
     * Helper: call generate:crud and return [exitCode, output, newFiles[]].
     * Also tracks new files for cleanup on success.
     */
    protected function callGenerateCrud(string $schema): array
    {
        $before = $this->getMigrationFiles();

        $exitCode = Artisan::call('generate:crud', ['schema' => $schema]);

        clearstatcache();

        $after = $this->getMigrationFiles();
        $newFiles = array_values(array_diff($after, $before));
        $output = Artisan::output();

        if ($exitCode === 0 && ! empty($newFiles)) {
            $this->generatedMigrationFiles = array_values(array_unique(
                array_merge($this->generatedMigrationFiles, $newFiles)
            ));
        }

        return [$exitCode, $output, $newFiles];
    }

    /**
     * When you *expect* at least one migration file, use this.
     */
    protected function generateMigrationForSchema(string $schema): string
    {
        [$exitCode, $output, $newFiles] = $this->callGenerateCrud($schema);

        $this->assertSame(0, $exitCode, "generate:crud failed. Output:\n{$output}");
        $this->assertNotEmpty($newFiles, 'No migration file was generated for schema: '.$schema);

        return $newFiles[0];
    }

    #[Test]
    public function it_generates_migration_to_modify_column_type_on_mysql()
    {
        $tableName = uniqid('test_');

        // 1. create table on MySQL
        $initialSchema = "$tableName:id:uuid:primary,name:string";
        $createMigration = $this->generateMigrationForSchema($initialSchema);
        $this->assertFileExists($createMigration);

        $migrationInstance = include $createMigration;
        $migrationInstance->up();

        // sanity
        $this->assertTrue(Schema::hasTable($tableName));

        // 2. modify 'name' from string -> text
        $updatedSchema = "$tableName:name:text";
        $modifyMigration = $this->generateMigrationForSchema($updatedSchema);
        $this->assertFileExists($modifyMigration);

        // run the modify migration on MySQL (this is where Doctrine + ->change() matter)
        $migrationInstance = include $modifyMigration;
        $migrationInstance->up();

        $content = file_get_contents($modifyMigration);
        $this->assertStringContainsString("Schema::table('$tableName'", $content);
        $this->assertStringContainsString("\$table->text('name')->change()", $content);
    }

    #[Test]
    public function it_generates_migrations_to_modify_existing_and_add_new_columns_on_mysql()
    {
        $tableName = uniqid('test_');

        // 1. Create table on MySQL
        $initialSchema = "$tableName:id:uuid:primary,name:string";
        $createMigration = $this->generateMigrationForSchema($initialSchema);
        $this->assertFileExists($createMigration);

        // Run initial migration so the table exists in the DB
        $migrationInstance = include $createMigration;
        $migrationInstance->up();

        // Sanity check that table exists
        $this->assertTrue(Schema::hasTable($tableName));

        // 2. Prepare updated schema: modify `name` type (string -> text) + add new `code` column
        $updatedSchema = "$tableName:name:text,code:integer";

        // Capture migration files before running the update
        $before = $this->getMigrationFiles();

        // Run generator for updated schema
        [$exitCode, $output, $newFiles] = $this->callGenerateCrud($updatedSchema);
        $this->assertSame(0, $exitCode, "generate:crud should exit with code 0. Output:\n{$output}");

        clearstatcache();

        $after = $this->getMigrationFiles();
        $newFiles = array_values(array_diff($after, $before));

        // We expect 2 migrations: one "modify" and one "add columns"
        $this->assertNotEmpty($newFiles, 'No migration files were generated for updated schema.');
        $this->assertCount(
            2,
            $newFiles,
            'Expected 2 migrations (modify + add columns), got '.count($newFiles)
        );

        // Track for cleanup
        $this->generatedMigrationFiles = array_values(array_unique(
            array_merge($this->generatedMigrationFiles, $newFiles)
        ));

        $modifyMigrationPath = null;
        $addColumnsPath = null;

        // 3. Inspect contents of the new migrations
        foreach ($newFiles as $file) {
            $content = file_get_contents($file);

            // Modify migration should contain a change() call to text('name')
            if (str_contains($content, "\$table->text('name')->change()")) {
                $modifyMigrationPath = $file;
            }

            // Add-columns migration should contain an integer 'code' definition
            if (str_contains($content, "\$table->integer('code')")) {
                $addColumnsPath = $file;
            }
        }

        $this->assertNotNull($modifyMigrationPath, 'Modify migration for name->text was not generated.');
        $this->assertNotNull($addColumnsPath, 'Add-columns migration for code:integer was not generated.');

        // Run modify migration
        $modifyInstance = include $modifyMigrationPath;
        $modifyInstance->up();

        // Run add-columns migration
        $addColumnsInstance = include $addColumnsPath;
        $addColumnsInstance->up();

        // 5. Verify DB state:
        // - `code` column should now exist
        $this->assertTrue(
            Schema::hasColumn($tableName, 'code'),
            "Expected 'code' column to exist after add-columns migration."
        );
    }

    #[Test]
    public function it_does_not_generate_migration_when_schema_matches_database()
    {
        $tableName = uniqid('test_');
        $schema = "$tableName:id:uuid:primary,name:string";

        // 1. First run: create migration + run it
        $createMigration = $this->generateMigrationForSchema($schema);
        $this->assertFileExists($createMigration);

        $createInstance = include $createMigration;
        $createInstance->up();

        $this->assertTrue(
            Schema::hasTable($tableName),
            "Expected table '{$tableName}' to exist after running create migration."
        );

        // 2. Second run with SAME schema -> should not create any new migrations
        $before = $this->getMigrationFiles();

        [$exitCode, $output, $newFiles] = $this->callGenerateCrud($schema);

        $this->assertSame(0, $exitCode, "Second generate:crud call failed. Output:\n{$output}");

        // recompute newFiles relative to our own "before"
        clearstatcache();
        $after = $this->getMigrationFiles();
        $newFiles = array_values(array_diff($after, $before));

        $this->assertEmpty(
            $newFiles,
            'Expected no new migration files when schema matches existing database schema.'
        );

        $this->assertStringContainsString(
            "No changes required. Table '{$tableName}' is up-to-date.",
            $output
        );
    }

    #[Test]
    public function it_drops_foreign_id_columns_and_restores_them_in_down()
    {
        $tableName = uniqid('test_');

        // 0. Create the referenced `users` table so the FK can be created
        Schema::create('users', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id(); // big integer primary key, matches default foreignId() reference
        });

        $this->assertTrue(
            Schema::hasTable('users'),
            "Expected 'users' table to exist before creating foreign key to it."
        );

        // 1. Create table with a foreignId
        $initialSchema = "$tableName:id:uuid:primary,user_id:foreignId:constrained=users:onDelete=cascade";
        $createMigration = $this->generateMigrationForSchema($initialSchema);
        $this->assertFileExists($createMigration);

        $createInstance = include $createMigration;
        $createInstance->up();

        $this->assertTrue(Schema::hasTable($tableName));
        $this->assertTrue(Schema::hasColumn($tableName, 'user_id'));

        // 2. Request dropping `user_id` via :drop flag
        $dropSchema = "$tableName:user_id:foreignId:drop";

        $dropMigrationPath = $this->generateMigrationForSchema($dropSchema);
        $this->assertFileExists($dropMigrationPath);

        $dropContent = file_get_contents($dropMigrationPath);

        // up() should drop FK then column
        $this->assertStringContainsString("Schema::table('$tableName'", $dropContent);
        $this->assertStringContainsString("\$table->dropForeign(['user_id'])", $dropContent);
        $this->assertStringContainsString("\$table->dropColumn('user_id')", $dropContent);

        // down() should re-create the foreignId with constraints
        $this->assertStringContainsString("\$table->foreignId('user_id')", $dropContent);

        // 3. Run drop migration to ensure it is valid
        $dropInstance = include $dropMigrationPath;
        $dropInstance->up();

        $this->assertFalse(
            Schema::hasColumn($tableName, 'user_id'),
            "Expected 'user_id' to be dropped after drop-columns migration."
        );

        // 4. Run down() and ensure the column is back
        $dropInstance->down();

        $this->assertTrue(
            Schema::hasColumn($tableName, 'user_id'),
            "Expected 'user_id' to be restored after down() of drop-columns migration."
        );
    }
}
