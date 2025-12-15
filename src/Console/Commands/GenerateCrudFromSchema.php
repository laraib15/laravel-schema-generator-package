<?php

declare(strict_types=1);

namespace Laraib15\SchemaGenerator\Console\Commands;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Column;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class GenerateCrudFromSchema extends Command
{
    protected $signature = 'generate:crud {schema}';

    protected $description = 'Generate migration from schema string with relations, indexes, and soft deletes support';

    /**
     * Track all migration files created during this command execution
     * so we can clean them up on error.
     *
     * @var string[]
     */
    protected array $createdMigrationFiles = [];

    protected array $allowedColumnTypes = [
        'bigIncrements', 'bigInteger', 'binary', 'boolean', 'char', 'date', 'datetime',
        'dateTimeTz', 'decimal', 'double', 'enum', 'float', 'foreignId', 'geometry',
        'geometryCollection', 'increments', 'integer', 'ipAddress', 'json', 'jsonb',
        'lineString', 'longText', 'macAddress', 'mediumIncrements', 'mediumInteger',
        'mediumText', 'morphs', 'uuidMorphs', 'multiLineString', 'multiPoint',
        'multiPolygon', 'nullableMorphs', 'nullableUuidMorphs', 'point', 'polygon',
        'rememberToken', 'set', 'smallIncrements', 'smallInteger', 'softDeletes',
        'softDeletesTz', 'string', 'text', 'time', 'timeTz', 'timestamp', 'timestampTz',
        'tinyInteger', 'tinyText', 'unsignedBigInteger', 'unsignedDecimal',
        'unsignedInteger', 'unsignedMediumInteger', 'unsignedSmallInteger',
        'unsignedTinyInteger', 'uuid', 'year', 'timestamps', 'timestampsTz',
    ];

    public function handle(): int
    {
        $schema = $this->argument('schema');
        $this->info("Parsing schema: {$schema}");

        $tokens = explode(',', $schema);

        $compositeIndexTokens = [];
        $columnTokens = [];

        foreach ($tokens as $token) {
            // Identify composite index tokens like 'unique:user_id|ordered_at' or 'index:col1|col2'
            if (preg_match('/^(unique|index):[\w|]+$/', $token)) {
                $compositeIndexTokens[] = $token;
            } else {
                $columnTokens[] = $token;
            }
        }

        // Parse columns and flags from non-index tokens
        $parsed = $this->parseSchemaInput(implode(',', $columnTokens));
        $table = $parsed['table'];
        $columns = $parsed['columns'];
        $flags = $parsed['flags'] ?? [];

        // Parse composite indexes separately from the tokens
        $compositeIndexes = $this->parseCompositeIndexesFromTokens($compositeIndexTokens);

        if (! $this->validateColumnTypes($columns)) {
            $this->error('Migration aborted due to invalid column types.');

            return 1;
        }

        try {
            // If a create_* migration (or at least the table) already exists, we are in alter/modify/drop mode
            if ($this->migrationExists($table)) {
                // For alter/modify, Doctrine DBAL is required to inspect existing columns
                if (! class_exists(DriverManager::class)) {
                    $this->error('Doctrine DBAL is required to inspect existing columns. Run: composer require doctrine/dbal');

                    return 1;
                }

                $existingColumns = Schema::hasTable($table)
                    ? Schema::getColumnListing($table)
                    : [];

                $newColumns = [];
                $columnsToModify = [];
                $columnsToDrop = [];

                foreach ($columns as $name => $col) {
                    if (in_array($name, ['id', 'created_at', 'updated_at'], true)) {
                        continue; // skip system columns
                    }

                    // Explicit drop request in schema: table:column:type:drop
                    if (! empty($col['options']['drop'])) {
                        if (in_array($name, $existingColumns, true)) {
                            $columnsToDrop[$name] = $col;
                            $this->warn("Column '{$name}' is marked to be dropped.");
                        } else {
                            $this->warn("Column '{$name}' was marked to be dropped but does not exist in table '{$table}'.");
                        }

                        continue;
                    }

                    if (! in_array($name, $existingColumns, true)) {
                        $newColumns[$name] = $col;
                        $this->info("Column '{$name}' does not exist, will be added.");

                        continue;
                    }

                    // Column exists - check differences using Doctrine DBAL
                    $existingColumn = $this->getExistingColumn($table, $name);
                    if ($existingColumn) {
                        $typeObj = $existingColumn->getType();
                        $dbTypeClass = get_class($typeObj); // e.g. 'Doctrine\DBAL\Types\TextType'
                        $dbNullable = ! $existingColumn->getNotnull();

                        // Normalize types for comparison
                        $dbType = $this->normalizeTypeForComparison($dbTypeClass);

                        $requestedType = $this->normalizeTypeForComparison($col['type'], $dbType);
                        $requestedNullable = ! empty($col['options']['nullable']);

                        
                        $foreignIdMatch = (
                            ($dbType === 'biginttype' || $dbType === 'bigint')
                            && strtolower($requestedType) === 'foreignid'
                        );

                        if (! $foreignIdMatch && ($dbType !== $requestedType || $dbNullable !== $requestedNullable)) {
                            $columnsToModify[$name] = $col;
                            $this->warn(
                                "Column '{$name}' differs (DB: type={$dbType}, nullable=".($dbNullable ? 'true' : 'false').
                                "; Requested: type={$requestedType}, nullable=".($requestedNullable ? 'true' : 'false').'), will be modified.'
                            );
                        } else {
                            $this->info("Column '{$name}' matches DB schema, no modification needed.");
                        }
                    } else {
                        $this->warn("Could not inspect existing column '{$name}', skipping modification check.");
                    }
                }

                // Step 3: Generate migrations for new, modified, and dropped columns if any
                if (! empty($newColumns)) {
                    $this->generateAlterMigration($table, $newColumns);
                }
                if (! empty($columnsToModify)) {
                    $this->generateModifyMigration($table, $columnsToModify);
                }
                if (! empty($columnsToDrop)) {
                    $this->generateDropColumnsMigration($table, $columnsToDrop);
                }

                if (empty($newColumns) && empty($columnsToModify) && empty($columnsToDrop)) {
                    $this->info("No changes required. Table '{$table}' is up-to-date.");
                }

                $this->info('Migration generation completed successfully.');

                return 0;
            }

            // CREATE mode
            foreach ($columns as $colName => $col) {
                if ($col['type'] === 'foreignId') {
                    $referencedTable = $this->getReferencedTable($colName, $col['options']);
                    // If you want to enforce referenced table existence, uncomment:
                    // if (!Schema::hasTable($referencedTable)) {
                    //     $this->error("Referenced table '{$referencedTable}' for foreign key '{$colName}' does not exist.");
                    //     return 1;
                    // }
                }
            }

            // Generate create-table migration
            $this->generateMigration($table, $columns, $flags, $compositeIndexes);
        } catch (Throwable $e) {
            $this->error('Error generating migration: '.$e->getMessage());

            // Clean up any created migration files if an error occurs
            foreach ($this->createdMigrationFiles as $path) {
                if (File::exists($path)) {
                    File::delete($path);
                    $this->info("Deleted migration file due to error: {$path}");
                }
            }

            return 1;
        }

        $this->info('Migration generation completed successfully.');

        return 0;
    }

    /**
     * Track a created migration file so we can delete it on error.
     */
    protected function registerCreatedMigration(string $path): void
    {
        $this->createdMigrationFiles[] = $path;
    }

    /**
     * Parses composite indexes from tokens like ['unique:user_id|ordered_at', 'index:foo|bar']
     */
    protected function parseCompositeIndexesFromTokens(array $tokens): array
    {
        $indexes = [];
        foreach ($tokens as $token) {
            if (preg_match('/^(unique|index):([\w|]+)$/', $token, $matches)) {
                $type = $matches[1];
                $columns = explode('|', $matches[2]);
                $indexes[] = [
                    'type' => $type,
                    'columns' => $columns,
                    'name' => null,
                ];
            }
        }

        return $indexes;
    }

    /* ----------------------
     | Column code builder
     |-----------------------*/
    protected function buildFields(array $columns): string
    {
        $code = '';

        foreach ($columns as $name => $col) {
            // don't create id again
            if ($name === 'id') {
                continue;
            }

            $type = $col['type'];
            $opts = $col['options'] ?? [];

            // FOREIGN ID handling
            if ($type === 'foreignId') {
                $line = "\$table->foreignId('{$name}')";
                if (! empty($opts['nullable'])) {
                    $line .= '->nullable()';
                }
                // constrained can be boolean true (flag) or a table name (value)
                if (! empty($opts['constrained'])) {
                    $constrained = is_string($opts['constrained'])
                        ? $opts['constrained']
                        : $this->getReferencedTable($name, $opts);
                    $line .= "->constrained('{$constrained}')";
                }
                if (! empty($opts['onDelete'])) {
                    $line .= "->onDelete('{$opts['onDelete']}')";
                }
                $code .= $line.";\n            ";

                continue;
            }

            // Normal columns
            $line = "\$table->{$type}('{$name}')";
            if (! empty($opts['nullable'])) {
                $line .= '->nullable()';
            }
            if (! empty($opts['unique'])) {
                $line .= '->unique()';
            }

            $code .= $line.";\n            ";
        }

        return $code;
    }

    protected function normalizeTypeForComparison(string $type, ?string $dbColumnType = null): string
    {
        $this->info("Normalizing type for comparison: {$type}  DB column type: ".($dbColumnType ?? 'null'));

        if (str_contains($type, '\\')) {
            $type = strtolower(substr($type, strrpos($type, '\\') + 1));
        }

        $map = [
            'biginttype' => 'bigint',
            'bigincrementstype' => 'bigint',
            'bigintegertype' => 'bigint',
            'enumtype' => 'enum',
            'incrementstype' => 'integer',
            'integertype' => 'integer',
            'stringtype' => 'string',
            'texttype' => 'text',
            'booleantype' => 'boolean',
            'datetimetype' => 'datetime',
            'datetype' => 'date',
            'timestamptype' => 'timestamp',
            'floattype' => 'float',
            'decimaltype' => 'decimal',
            'jsontype' => 'json',
        ];

        $normalized = $map[$type] ?? $type;

        // Handle Doctrine quirk: datetime vs timestamp
        if ($normalized === 'datetime' && $dbColumnType === 'timestamp') {
            $normalized = 'timestamp';
        } elseif ($normalized === 'timestamp' && $dbColumnType === 'datetime') {
            $normalized = 'datetime';
        }

        $this->info('Normalized type: '.$normalized);

        return $normalized;
    }

    protected function generateDropColumnsMigration(string $table, array $columnsToDrop): void
    {
        $timestamp = date('Y_m_d_His');
        $file = database_path("migrations/{$timestamp}_drop_columns_from_{$table}_table.php");

        $upLines = [];
        $downLines = [];

        foreach ($columnsToDrop as $name => $col) {
            $type = $col['type'];
            $opts = $col['options'] ?? [];

            // If it was a foreignId, drop the foreign key first
            if ($type === 'foreignId') {
                $upLines[] = "\$table->dropForeign(['{$name}']);";
            }

            // Drop the column
            $upLines[] = "\$table->dropColumn('{$name}');";

            // Build down() to re-add the column using existing formatting
            $downLines[] = $this->formatColumnLine($name, $type, $opts);
        }

        $upCode = implode("\n                    ", $upLines);
        $downCode = implode("\n                    ", $downLines);

        $stub = <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up()
            {
                Schema::table('{$table}', function (Blueprint \$table) {
                    {$upCode}
                });
            }

            public function down()
            {
                Schema::table('{$table}', function (Blueprint \$table) {
                    {$downCode}
                });
            }
        };
        PHP;

        File::put($file, $stub);
        $this->registerCreatedMigration($file);
        $this->info("Drop-columns migration created: {$file}");
    }

    protected function parseSchemaInput(string $input): array
    {
        $table = null;
        $columns = [];
        $flags = [
            'softDeletes' => false,
            'timestamps' => false,
        ];
        $indexes = [];

        $tokens = explode(',', $input);

        foreach ($tokens as $token) {
            $parts = explode(':', $token);

            if (! $table) {
                $table = array_shift($parts);
                if (count($parts) === 0) {
                    continue;
                }
            }

            // Flags
            if (count($parts) === 0 && in_array($token, ['softDeletes', 'timestamps'], true)) {
                $flags[$token] = true;

                continue;
            }
            if (count($parts) === 1 && in_array($parts[0], ['softDeletes', 'timestamps'], true)) {
                $flags[$parts[0]] = true;

                continue;
            }

            $name = $parts[0];
            $type = $parts[1] ?? 'string';

            // Indexes
            if (in_array($type, ['unique', 'index'], true)) {
                $colsStr = $parts[2] ?? '';
                $cols = explode('|', $colsStr);
                $indexes[] = [
                    'name' => $name,
                    'type' => $type,
                    'columns' => $cols,
                ];

                continue;
            }

            // Options parsing
            $options = [];

            // Special: if string or char and next part is numeric, treat as length
            if (in_array($type, ['string', 'char'], true) && isset($parts[2]) && is_numeric($parts[2])) {
                $options['length'] = (int) $parts[2];
                $optionPartsStart = 3; // options start after length
            } else {
                $optionPartsStart = 2; // options start here normally
            }

            for ($i = $optionPartsStart; $i < count($parts); $i++) {
                $option = $parts[$i];
                if (strpos($option, '=') !== false) {
                    [$key, $val] = explode('=', $option, 2);
                    if ($type === 'enum' && $key === 'values') {
                        $options['values'] = explode('|', $val);
                    } else {
                        $options[$key] = $val;
                    }
                } else {
                    $options[$option] = true;
                }
            }

            $columns[$name] = [
                'type' => $type,
                'options' => $options,
            ];
        }

        return compact('table', 'columns', 'flags', 'indexes');
    }

    protected function formatColumnLine(string $name, string $type, array $opts): string
    {
        $line = '$table';

        // Handle special case: foreignId
        if ($type === 'foreignId') {
            $line .= "->foreignId('{$name}')";
            if (! empty($opts['nullable'])) {
                $line .= '->nullable()';
            }
            if (array_key_exists('constrained', $opts)) {
                if (is_string($opts['constrained']) && strlen($opts['constrained']) > 0 && $opts['constrained'] !== 'true') {
                    $line .= "->constrained('{$opts['constrained']}')";
                } else {
                    $line .= '->constrained()';
                }
            }
            if (! empty($opts['onDelete'])) {
                $line .= "->onDelete('{$opts['onDelete']}')";
            }
            if (! empty($opts['comment'])) {
                $comment = addslashes($opts['comment']);
                $line .= "->comment('{$comment}')";
            }
            $line .= ';';

            return $line;
        }

        // Handle enum
        if ($type === 'enum' && ! empty($opts['values']) && is_array($opts['values'])) {
            $vals = "['".implode("','", $opts['values'])."']";
            $line .= "->enum('{$name}', {$vals})";
        }
        // Handle decimal (precision, scale)
        elseif ($type === 'decimal') {
            $precision = $opts['precision'] ?? 8;
            $scale = $opts['scale'] ?? 2;
            $line .= "->decimal('{$name}', {$precision}, {$scale})";
        }
        // Handle uuid primary key
        elseif ($type === 'uuid' && ! empty($opts['primary'])) {
            $line .= "->uuid('{$name}')->primary()";
            if (! empty($opts['comment'])) {
                $comment = addslashes($opts['comment']);
                $line .= "->comment('{$comment}')";
            }
            $line .= ';';

            return $line;
        }
        // Handle string and char with length
        elseif (in_array($type, ['string', 'char'], true)) {
            $length = $opts['length'] ?? null;
            if ($length) {
                $line .= "->{$type}('{$name}', {$length})";
            } else {
                $line .= "->{$type}('{$name}')";
            }
        } else {
            $line .= "->{$type}('{$name}')";
        }

        // Nullable
        if (! empty($opts['nullable'])) {
            $line .= '->nullable()';
        }

        // Default value
        if (isset($opts['default'])) {
            $default = $opts['default'];
            if (is_bool($default)) {
                $default = $default ? 'true' : 'false';
            } elseif (is_string($default) && $default !== 'true' && $default !== 'false') {
                $default = "'{$default}'";
            }
            $line .= "->default({$default})";
        }

        // Unsigned
        if (! empty($opts['unsigned'])) {
            $line .= '->unsigned()';
        }

        // Single-column indexes
        if (! empty($opts['index'])) {
            $line .= '->index()';
        }
        if (! empty($opts['unique'])) {
            $line .= '->unique()';
        }
        if (! empty($opts['primary'])) {
            $line .= '->primary()';
        }

        // After (for alter migrations)
        if (! empty($opts['after'])) {
            $line .= "->after('{$opts['after']}')";
        }
        if (! empty($opts['comment'])) {
            $comment = addslashes($opts['comment']); // Escape quotes if any
            $line .= "->comment('{$comment}')";
        }

        $line .= ';';

        return $line;
    }

    protected function formatCompositeIndex(string $type, array $columns, ?string $indexName = null): string
    {
        $cols = implode("', '", $columns);
        $indexNamePart = $indexName ? ", '{$indexName}'" : '';

        if ($type === 'unique') {
            return "\$table->unique(['{$cols}']{$indexNamePart});";
        }
        if ($type === 'index') {
            return "\$table->index(['{$cols}']{$indexNamePart});";
        }

        return '';
    }

    protected function generateModifyMigration(string $table, array $columnsToModify): void
    {
        $timestamp = date('Y_m_d_His');
        $file = database_path("migrations/{$timestamp}_modify_columns_in_{$table}_table.php");

        $fieldsCode = '';
        $rollbackCode = '';

        foreach ($columnsToModify as $name => $col) {
            $type = $col['type'];
            $nullable = ! empty($col['options']['nullable']) ? '->nullable()' : '';

            // Get the existing column info to find old type & nullable status
            $existingColumn = $this->getExistingColumn($table, $name);
            if ($existingColumn) {
                $oldTypeObj = $existingColumn->getType();
                $oldType = $this->normalizeTypeForComparison(get_class($oldTypeObj), $type);
                $oldNullable = ! $existingColumn->getNotnull() ? '->nullable()' : '';
            } else {
                // fallback if no existing column info
                $oldType = 'string';
                $oldNullable = '';
            }

            $this->info("Preparing to modify column '{$name}' to type '{$type}'".($nullable ? ' with nullable' : ''));

            // up()
            $fieldsCode .= "\$table->{$type}('{$name}'){$nullable}->change();\n            ";
            // down()
            $rollbackCode .= "\$table->{$oldType}('{$name}'){$oldNullable}->change();\n            ";
        }

        $stub = <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration {
            public function up()
            {
                Schema::table('{$table}', function (Blueprint \$table) {
                    {$fieldsCode}
                });
            }

            public function down()
            {
                Schema::table('{$table}', function (Blueprint \$table) {
                    {$rollbackCode}
                });
            }
        };
        PHP;

        File::put($file, $stub);
        $this->registerCreatedMigration($file);
        $this->info("Modify migration created: {$file}");
    }

    protected function migrationExists(string $table): bool
    {
        $files = File::files(database_path('migrations'));
        $found = false;

        foreach ($files as $file) {
            if (Str::contains($file->getFilename(), "create_{$table}_table.php")) {
                $found = true;
                break;
            }
        }

        if (! $found && Schema::hasTable($table)) {
            $this->warn("Table '{$table}' exists but no create_{$table}_table migration was found. Treating as existing table.");

            return true;
        }

        return $found;
    }

    protected function getReferencedTable(string $columnName, array $options): string
    {
        // Default referenced table: plural of columnName without _id
        $defaultTable = Str::plural(Str::beforeLast($columnName, '_id'));

        // Check if 'on:tablename' option present
        foreach ($options as $opt) {
            if (is_string($opt) && Str::startsWith($opt, 'on:')) {
                return substr($opt, 3);
            }
        }

        return $defaultTable;
    }

    protected function generateMigration(string $table, array $columns, array $flags, array $compositeIndexes = []): void
    {
        $timestamp = date('Y_m_d_His');
        $filename = database_path("migrations/{$timestamp}_create_{$table}_table.php");
        $this->registerCreatedMigration($filename);

        // Check if 'id' column exists and is customized (not default id type or has primary option)
        $hasCustomId = false;
        if (isset($columns['id'])) {
            $idCol = $columns['id'];
            $type = $idCol['type'];
            $opts = $idCol['options'] ?? [];
            if ($type !== 'id' || ! empty($opts['primary'])) {
                $hasCustomId = true;
            }
        }

        $fieldsCode = '';

        // If no custom id, generate default id()
        if (! $hasCustomId) {
            $fieldsCode .= "\$table->id();\n\t\t\t";
        }

        foreach ($columns as $name => $col) {
            // Skip 'id' if handled by default id()
            if ($name === 'id' && ! $hasCustomId) {
                continue;
            }

            $type = $col['type'];
            $opts = $col['options'] ?? [];

            $fieldsCode .= $this->formatColumnLine($name, $type, $opts)."\n\t\t\t";
        }

        // Add composite indexes (multi-column indexes)
        foreach ($compositeIndexes as $index) {
            $fieldsCode .= $this->formatCompositeIndex($index['type'], $index['columns'], $index['name'] ?? null)."\n\t\t\t";
        }

        // Add softDeletes and timestamps if requested
        if (! empty($flags['softDeletes'])) {
            $fieldsCode .= "\$table->softDeletes();\n\t\t\t";
        }
        if (! empty($flags['timestamps'])) {
            $fieldsCode .= "\$table->timestamps();\n\t\t\t";
        }

        $stub = <<<EOD
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up()
            {
                Schema::create('{$table}', function (Blueprint \$table) {
                    {$fieldsCode}
                });
            }

            public function down()
            {
                Schema::dropIfExists('{$table}');
            }
        };

        EOD;

        File::put($filename, $stub);
        $this->info("Migration created: {$filename}");
    }

    protected function generateAlterMigration(string $table, array $newColumns): void
    {
        $timestamp = date('Y_m_d_His');
        $filename = database_path("migrations/{$timestamp}_add_columns_to_{$table}_table.php");

        $fieldsCode = '';
        $downLines = [];

        foreach ($newColumns as $name => $col) {
            $type = $col['type'];
            $opts = $col['options'] ?? [];

            $line = rtrim($this->formatColumnLine($name, $type, $opts), ';').';';

            $fieldsCode .= $line."\n\t\t\t";

            if ($type === 'foreignId') {
                $downLines[] = "\$table->dropForeign(['{$name}']);";
            }
            $downLines[] = "\$table->dropColumn('{$name}');";
        }

        $downCode = implode("\n                    ", $downLines);

        $stub = <<<EOD
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up()
            {
                Schema::table('{$table}', function (Blueprint \$table) {
                    {$fieldsCode}
                });
            }

            public function down()
            {
                Schema::table('{$table}', function (Blueprint \$table) {
                    {$downCode}
                });
            }
        };

        EOD;

        File::put($filename, $stub);
        $this->registerCreatedMigration($filename);
        $this->info("Alter migration created: {$filename}");
    }

    protected function validateColumnTypes(array $columns): bool
    {
        foreach ($columns as $name => $col) {
            $type = $col['type'];
            if (! in_array($type, $this->allowedColumnTypes, true)) {
                $this->error("Invalid column type '{$type}' for column '{$name}'.");

                return false;
            }
        }

        return true;
    }

    protected function getExistingColumn(string $table, string $column): ?Column
    {
        // If table doesn't exist, nothing to inspect
        if (! Schema::hasTable($table)) {
            return null;
        }

        // If Doctrine isn't installed, we can't introspect — fail gracefully
        if (! class_exists(DriverManager::class)) {
            $this->error('Doctrine DBAL is required to inspect existing columns. Run: composer require doctrine/dbal');

            return null;
        }

        try {
            $default = config('database.default');

            if ($default === 'mysql') {
                $connectionParams = [
                    'dbname' => config('database.connections.mysql.database'),
                    'user' => config('database.connections.mysql.username'),
                    'password' => config('database.connections.mysql.password'),
                    'host' => config('database.connections.mysql.host'),
                    'driver' => 'pdo_mysql',
                    'port' => config('database.connections.mysql.port'),
                ];

                $config = new Configuration;
                $doctrineConnection = DriverManager::getConnection($connectionParams, $config);
            } else {
                // Use the CURRENT Laravel connection (sqlite/pgsql/etc.)
                $laravelConnection = DB::connection($default);
                $pdo = $laravelConnection->getPdo();

                $driverName = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

                $doctrineDriver = match ($driverName) {
                    'mysql' => 'pdo_mysql',
                    'sqlite' => 'pdo_sqlite',
                    'pgsql' => 'pdo_pgsql',
                    default => throw new \RuntimeException("Unsupported PDO driver: {$driverName}"),
                };

                $connectionParams = [
                    'pdo' => $pdo,
                    'driver' => $doctrineDriver,
                ];

                $config = new Configuration;
                $doctrineConnection = DriverManager::getConnection($connectionParams, $config);
            }

            $schemaManager = $doctrineConnection->createSchemaManager();

            if (method_exists($schemaManager, 'introspectTable')) {
                $doctrineTable = $schemaManager->introspectTable($table);
            } else {
                $doctrineTable = $schemaManager->listTableDetails($table);
            }

            if ($doctrineTable->hasColumn($column)) {
                return $doctrineTable->getColumn($column);
            }
        } catch (Throwable $e) {
            $this->error('Exception inspecting column: '.$e->getMessage());
        }

        return null;
    }
}
