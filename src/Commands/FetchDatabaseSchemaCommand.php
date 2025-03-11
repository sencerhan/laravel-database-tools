<?php

namespace Sencerhan\LaravelDbTools\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use function database_path;


class FetchDatabaseSchemaCommand extends Command
{
    protected $tablesBeingProcessed = [];
    protected $signature = 'db:fetch {--tables= : Specify tables separated by commas}
                                 {--debug : Shows debug information}
                                 {--force : Force schema changes even with constraints}';
    protected $description = 'Update database schema to match migration files';
    private $debug_mode;
    public function handle()
    {
        $this->output->writeln([
            '╔═══════════════════════════════════════════════════════════════╗',
            '║ <fg=bright-blue>Database Schema Synchronizer</> <fg=gray>v1.0</fg>                    ║',
            '╚═══════════════════════════════════════════════════════════════╝',
        ]);

        $this->info("\nStarting database update process based on migrations...");

        try {
            // Get tables to process
            $specifiedTables = $this->option('tables')
                ? explode(',', $this->option('tables'))
                : [];
            $this->debug_mode = $this->option('debug') ? true : false;
            if (!empty($specifiedTables)) {
                $tables = $specifiedTables;
                $this->info("Processing specified tables: " . implode(', ', $tables));
            } else {
                $tables = $this->getAllTables();
                $this->info("Processing all tables: " . count($tables) . " tables found.");
            }

            // Store tables for progress tracking
            $this->tablesBeingProcessed = $tables;

            foreach ($tables as $table) {
                $this->processTable($table);
                $this->output->writeln("<fg=gray>──────────────────────────────────────────────────────────────</>");
            }

            $this->displaySummary([
                'processed' => count($this->tablesBeingProcessed),
                'total' => count($this->getAllTables()),
                'added' => DB::getQueryLog() ? count(array_filter(DB::getQueryLog(), function($query) {
                    return strpos($query['query'], 'ADD COLUMN') !== false;
                })) : 0,
                'modified' => DB::getQueryLog() ? count(array_filter(DB::getQueryLog(), function($query) {
                    return strpos($query['query'], 'MODIFY COLUMN') !== false;
                })) : 0,
                'dropped' => DB::getQueryLog() ? count(array_filter(DB::getQueryLog(), function($query) {
                    return strpos($query['query'], 'DROP COLUMN') !== false;
                })) : 0,
                'errors' => 0,
                'error_details' => [],
                'duration' => number_format(microtime(true) - LARAVEL_START, 2)
            ]);
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("\nAn error occurred: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    /**
     * Process a single table
     */
    protected function processTable(string $table): void
    {
        $this->output->writeln([
            '',
            " <fg=bright-blue>┌─────────────────────────────────────────┐</>",
            " <fg=bright-blue>│</> Processing Table: <fg=white;bg=blue> {$table} </> <fg=bright-blue>│</>",
            " <fg=bright-blue>└─────────────────────────────────────────┘</>",
        ]);

        try {
            // Find migration file for table
            $migrationFile = $this->findMigrationFile($table);

            if (!$migrationFile) {
                $this->warn("  No migration file found for table {$table}");
                return;
            }

            $this->line(" <fg=blue>ℹ</> Found migration file: " . basename($migrationFile));

            // Extract migration schema data
            $content = File::get($migrationFile);
            $this->line("  Migration content found, parsing schema...");

            $migrationSchema = $this->parseMigrationStructure($content);
            if ($this->debug_mode) {
                $this->line("  Parsed migration structure: " . json_encode($migrationSchema, JSON_PRETTY_PRINT));
            }
            if (empty($migrationSchema)) {
                $this->warn("  Could not extract schema from migration file");
                return;
            }

            // Get existing table structure for comparison
            if (Schema::hasTable($table)) {
                $existingColumns = DB::select("SHOW FULL COLUMNS FROM `{$table}`");
                if ($this->debug_mode) {
                    $this->line("  Current table columns: " . json_encode($existingColumns, JSON_PRETTY_PRINT));
                }

                // Compare and generate changes
                $changes = [
                    'drop_columns' => [],
                    'add_columns' => [],
                    'modify_columns' => [],
                    'drop_indexes' => [],
                    'add_indexes' => []
                ];

                // Map existing columns by name
                $existingColumnsMap = [];
                foreach ($existingColumns as $col) {
                    $existingColumnsMap[$col->Field] = $col;
                }

                // Check for columns to add or modify
                foreach ($migrationSchema['columns'] as $column) {
                    if (empty($column['name'])) continue;

                    if (!isset($existingColumnsMap[$column['name']])) {
                        $this->info("  Column '{$column['name']}' needs to be added");
                        $changes['add_columns'][] = $column;
                    } else {
                        // Compare column properties
                        $differences = $this->compareColumnDefinitions($column, $existingColumnsMap[$column['name']]);
                        if (!empty($differences)) {
                            $this->info("  Column '{$column['name']}' needs modification: " . json_encode($differences));
                            $column['differences'] = $differences; // Add differences to column
                            $changes['modify_columns'][] = $column;
                        }
                    }
                }

                // Check for columns to drop
                foreach ($existingColumnsMap as $columnName => $column) {
                    if ($this->shouldPreserveColumn($columnName, $migrationSchema['hasTimestamps'])) {
                        continue; // Skip preserved columns
                    }

                    $found = false;
                    foreach ($migrationSchema['columns'] as $migrationColumn) {
                        if ($migrationColumn['name'] === $columnName) {
                            $found = true;
                            break;
                        }
                    }

                    if (!$found) {
                        $this->line(" <fg=yellow>⚠</> Column '{$columnName}' needs to be dropped");
                        $changes['drop_columns'][] = $columnName;
                    }
                }

                // Process the changes
                if (
                    !empty($changes['drop_columns']) || !empty($changes['add_columns']) ||
                    !empty($changes['modify_columns']) || !empty($changes['drop_indexes']) ||
                    !empty($changes['add_indexes'])
                ) {

                    $this->info("  Applying changes to table...");
                    $this->processTableUpdates($table, $changes);
                } else {
                    $this->info("  No changes needed for table {$table}");
                }
            } else {
                $this->warn("  Table {$table} does not exist. Use 'php artisan migrate' to create it.");
            }
        } catch (\Exception $e) {
            $this->line(" <fg=red>✗</> Error processing table {$table}: " . $e->getMessage());
            $this->line("  Stack trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Find migration file for table
     */
    protected function findMigrationFile(string $table): ?string
    {
        $patterns = [
            database_path('migrations/*_create_' . $table . '_table.php'),
            database_path('migrations/*_' . $table . '.php')
        ];

        foreach ($patterns as $pattern) {
            $files = File::glob($pattern);
            if (!empty($files)) {
                return $files[0];
            }
        }

        return null;
    }

    /**
     * Laravel column types and their MySQL equivalents
     */
    protected array $columnDefinitions = [
        'id' => [
            'type' => 'bigint',
            'length' => 20,
            'unsigned' => true,
            'autoIncrement' => true,
            'primary' => true,
            'nullable' => false
        ],
        'string' => [
            'type' => 'varchar',
            'default_length' => 255,
            'allows' => ['length', 'nullable', 'default', 'unique', 'index']
        ],
        'integer' => [
            'type' => 'int',
            'default_length' => 11,
            'allows' => ['unsigned', 'nullable', 'default', 'unique', 'index']
        ],
        'foreignId' => [
            'type' => 'bigint',
            'length' => 20,
            'unsigned' => true,
            'nullable' => false,
            'index' => true
        ],
        // Add other types as needed...
    ];

    /**
     * Column type definitions with their variations and modifiers
     */
    protected array $columnTypes = [
        // Temel ID ve Primary Key tipleri
        'id' => [
            'mysql_type' => 'bigint',
            'length' => 20,
            'attributes' => ['unsigned', 'auto_increment'],
            'index' => 'primary',
            'nullable' => false
        ],
        'increments' => [
            'mysql_type' => 'int',
            'attributes' => ['unsigned', 'auto_increment'],
            'index' => 'primary'
        ],
        'tinyIncrements' => [
            'mysql_type' => 'tinyint',
            'attributes' => ['unsigned', 'auto_increment'],
            'index' => 'primary'
        ],
        'smallIncrements' => [
            'mysql_type' => 'smallint',
            'attributes' => ['unsigned', 'auto_increment'],
            'index' => 'primary'
        ],
        'mediumIncrements' => [
            'mysql_type' => 'mediumint',
            'attributes' => ['unsigned', 'auto_increment'],
            'index' => 'primary'
        ],
        'bigIncrements' => [
            'mysql_type' => 'bigint',
            'attributes' => ['unsigned', 'auto_increment'],
            'index' => 'primary'
        ],

        // String ve Text tipleri
        'char' => [
            'mysql_type' => 'char',
            'default_length' => 255,
            'allows' => ['length', 'nullable', 'default', 'unique', 'index', 'collation']
        ],
        'string' => [
            'mysql_type' => 'varchar',
            'default_length' => 255,
            'allows' => ['length', 'nullable', 'default', 'unique', 'index', 'collation']
        ],
        'text' => [
            'mysql_type' => 'text',
            'allows' => ['nullable', 'collation']
        ],
        'mediumText' => [
            'mysql_type' => 'mediumtext',
            'allows' => ['nullable', 'collation']
        ],
        'longText' => [
            'mysql_type' => 'longtext',
            'allows' => ['nullable', 'collation']
        ],

        // Sayısal tipler
        'integer' => [
            'mysql_type' => 'int',
            'default_length' => 11,
            'allows' => ['unsigned', 'nullable', 'default', 'unique', 'index']
        ],
        'tinyInteger' => [
            'mysql_type' => 'tinyint',
            'default_length' => 4,
            'allows' => ['unsigned', 'nullable', 'default', 'unique', 'index']
        ],
        'smallInteger' => [
            'mysql_type' => 'smallint',
            'default_length' => 6,
            'allows' => ['unsigned', 'nullable', 'default', 'unique', 'index']
        ],
        'mediumInteger' => [
            'mysql_type' => 'mediumint',
            'default_length' => 9,
            'allows' => ['unsigned', 'nullable', 'default', 'unique', 'index']
        ],
        'bigInteger' => [
            'mysql_type' => 'bigint',
            'default_length' => 20,
            'allows' => ['unsigned', 'nullable', 'default', 'unique', 'index']
        ],

        // Decimal ve Float tipleri
        'decimal' => [
            'mysql_type' => 'decimal',
            'default_precision' => 8,
            'default_scale' => 2,
            'allows' => ['unsigned', 'nullable', 'default', 'unique', 'index']
        ],
        'float' => [
            'mysql_type' => 'float',
            'allows' => ['unsigned', 'nullable', 'default', 'unique', 'index']
        ],
        'double' => [
            'mysql_type' => 'double',
            'allows' => ['unsigned', 'nullable', 'default', 'unique', 'index']
        ],

        // Boolean
        'boolean' => [
            'mysql_type' => 'tinyint',
            'length' => 1,
            'allows' => ['nullable', 'default']
        ],

        // Tarih ve Zaman tipleri
        'date' => [
            'mysql_type' => 'date',
            'allows' => ['nullable', 'default', 'index']
        ],
        'dateTime' => [
            'mysql_type' => 'datetime',
            'allows' => ['nullable', 'default', 'index', 'precision']
        ],
        'dateTimeTz' => [
            'mysql_type' => 'datetime',
            'attributes' => ['timezone'],
            'allows' => ['nullable', 'default', 'index', 'precision']
        ],
        'time' => [
            'mysql_type' => 'time',
            'allows' => ['nullable', 'default', 'index']
        ],
        'timeTz' => [
            'mysql_type' => 'time',
            'attributes' => ['timezone'],
            'allows' => ['nullable', 'default', 'index']
        ],
        'timestamp' => [
            'mysql_type' => 'timestamp',
            'allows' => ['nullable', 'default', 'index', 'precision']
        ],
        'timestampTz' => [
            'mysql_type' => 'timestamp',
            'attributes' => ['timezone'],
            'allows' => ['nullable', 'default', 'index', 'precision']
        ],

        // JSON ve Binary
        'binary' => [
            'mysql_type' => 'blob',
            'allows' => ['nullable']
        ],
        'json' => [
            'mysql_type' => 'json',
            'allows' => ['nullable']
        ],
        'jsonb' => [
            'mysql_type' => 'json',
            'allows' => ['nullable']
        ],

        // Özel tipler
        'enum' => [
            'mysql_type' => 'enum',
            'requires' => ['values'],
            'allows' => ['nullable', 'default']
        ],
        'set' => [
            'mysql_type' => 'set',
            'requires' => ['values'],
            'allows' => ['nullable', 'default']
        ],

        // Laravel özel tipleri
        'rememberToken' => [
            'mysql_type' => 'varchar',
            'length' => 100,
            'nullable' => true
        ],
        'softDeletes' => [
            'mysql_type' => 'timestamp',
            'name' => 'deleted_at',
            'nullable' => true
        ],
        'foreignId' => [
            'mysql_type' => 'bigint',
            'attributes' => ['unsigned'],
            'creates_index' => true,
            'allows' => ['nullable', 'default', 'constrained']
        ]
    ];

    /**
     * Column modifiers and their MySQL equivalents
     */
    protected array $columnModifiers = [
        'nullable' => ['sql' => 'NULL', 'default' => 'NOT NULL'],
        'unsigned' => ['sql' => 'unsigned', 'requires_types' => ['integer', 'bigInteger']],
        'unique' => ['sql' => 'UNIQUE', 'creates_index' => true],
        'index' => ['sql' => '', 'creates_index' => true],
        'default' => ['sql' => 'DEFAULT ?', 'allows_value' => true],
        'after' => ['sql' => 'AFTER ?', 'allows_value' => true]
    ];

    /**
     * Parse migration file structure in a more organized way
     */
    protected function parseMigrationStructure(string $content): array
    {
        $structure = [
            'columns' => [],
            'indexes' => [],
            'foreign_keys' => [],
            'primary_key' => null,
            'hasTimestamps' => false
        ];

        // Extract schema definition block
        preg_match('/Schema::create.*?{(.*?)}\);/s', $content, $matches);
        if (empty($matches[1])) return $structure;

        $lines = explode("\n", $matches[1]);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Handle multi-column unique indexes
            if (preg_match('/\$table->unique\(\[(.*?)\](?:,\s*[\'"]([^\'"]+)[\'"])?\)/', $line, $matches)) {
                $columns = array_map(function ($col) {
                    return trim(trim($col, "'\""), ' ');
                }, explode(',', $matches[1]));

                $indexName = $matches[2] ?? $this->generateIndexName('unique', $columns);

                $structure['indexes'][] = [
                    'type' => 'unique',
                    'columns' => $columns,
                    'name' => $indexName,
                    'original' => $line
                ];
                continue;
            }

            // Check for timestamps first
            if (strpos($line, '$table->timestamps()') !== false) {
                $structure['hasTimestamps'] = true;
                $structure['columns'][] = [
                    'type' => 'timestamp',
                    'name' => 'created_at',
                    'params' => [],
                    'modifiers' => ['nullable' => true],
                    'original' => '$table->timestamp("created_at")->nullable()'
                ];
                $structure['columns'][] = [
                    'type' => 'timestamp',
                    'name' => 'updated_at',
                    'params' => [],
                    'modifiers' => ['nullable' => true],
                    'original' => '$table->timestamp("updated_at")->nullable()'
                ];
                continue;
            }

            // Parse each line based on type
            if ($this->isColumnDefinition($line)) {
                $structure['columns'][] = $this->parseColumnDefinition($line);
            } elseif ($this->isIndexDefinition($line)) {
                $structure['indexes'][] = $this->parseIndexDefinition($line);
            } elseif ($this->isForeignKeyDefinition($line)) {
                $structure['foreign_keys'][] = $this->parseForeignKeyDefinition($line);
            }
        }

        return $structure;
    }

    /**
     * Process migration line by line
     */
    protected function parseMigrationLine(string $line): ?array
    {
        $line = trim($line);
        if (empty($line)) return null;

        // Özel durumları kontrol et
        foreach ($this->columnTypes as $type => $definition) {
            if (preg_match($this->buildPattern($type), $line, $matches)) {
                return [
                    'type' => $type,
                    'name' => $matches['name'] ?? 'id',
                    'params' => $this->extractParams($matches, $definition),
                    'modifiers' => $this->extractModifiers($matches['modifiers'] ?? '', $definition['allows'] ?? [])
                ];
            }
        }

        return null;
    }

    protected function buildPattern(string $type): string
    {
        return '/\$table->' . $type . '\(' .
            '(?:[\'"]{1}(?<name>[^\'"]*)[\'"]{1})?' .
            '(?:,\s*(?<params>[^)]*))?'  .
            '\)' .
            '(?<modifiers>(?:->[\w]+(?:\([^)]*\))?)*);/';
    }

    /**
     * Check dependencies before dropping anything
     */
    protected function checkDependencies(string $table, string $column): array
    {
        $dependencies = [];

        // Check foreign key constraints
        $fks = $this->getForeignKeyDependencies($table, $column);
        if (!empty($fks)) {
            $dependencies['foreign_keys'] = $fks;
        }

        // Check indexes
        $indexes = $this->getIndexDependencies($table, $column);
        if (!empty($indexes)) {
            $dependencies['indexes'] = $indexes;
        }

        return $dependencies;
    }

    /**
     * Check for column dependencies before modifications
     */
    protected function getColumnDependencies(string $table, string $column): array
    {
        return [
            'foreign_keys' => $this->getForeignKeyConstraints($table, $column),
            'indexes' => $this->getColumnIndexes($table, $column),
            'references' => $this->getColumnReferences($table, $column)
        ];
    }

    /**
     * Get foreign keys that reference this column
     */
    protected function getColumnReferences(string $table, string $column): array
    {
        return DB::select("
            SELECT 
                CONSTRAINT_NAME,
                TABLE_NAME,
                COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE 
                TABLE_SCHEMA = ? AND
                REFERENCED_TABLE_NAME = ? AND
                REFERENCED_COLUMN_NAME = ?
        ", [config('database.connections.mysql.database'), $table, $column]);
    }

    /**
     * Compare column definitions
     */
    protected function compareColumnDefinitions(array $migration, object $existing): array
    {
        $differences = [];

        // Skip type comparison for columns that are part of unique indexes
        if (!isset($migration['modifiers']['unique'])) {
            // Type comparison
            if (!$this->typesMatch($migration['type'], $existing->Type)) {
                $differences['type'] = [
                    'migration' => $migration['type'],
                    'existing' => $existing->Type,
                    'message' => "Type mismatch - Migration wants '{$migration['type']}', currently '{$existing->Type}'"
                ];
            }
        }

        // Nullable comparison
        $migrationNullable = $migration['modifiers']['nullable'] ?? false;
        $existingNullable = $existing->Null === 'YES';
        if ($migrationNullable !== $existingNullable) {
            $differences['nullable'] = [
                'migration' => $migrationNullable,
                'existing' => $existingNullable,
                'message' => "Nullability mismatch - Migration wants " .
                    ($migrationNullable ? 'NULL' : 'NOT NULL') .
                    ", currently " . ($existingNullable ? 'NULL' : 'NOT NULL')
            ];
        }

        // Default value comparison
        $migrationDefault = $migration['modifiers']['default'] ?? null;
        $existingDefault = $existing->Default;
        if ($this->defaultsAreDifferent($migrationDefault, $existingDefault)) {
            $differences['default'] = [
                'migration' => $migrationDefault,
                'existing' => $existingDefault,
                'message' => "Default value mismatch - Migration wants '" .
                    ($migrationDefault ?? 'NULL') .
                    "', currently '" . ($existingDefault ?? 'NULL') . "'"
            ];
        }

        return $differences;
    }

    protected function processTableUpdates(string $table, array $changes): void
    {
        try {
            // Store foreign keys for reference
            $originalForeignKeys = $this->getTableForeignKeys($table);
            $originalReferencingKeys = $this->getReferencingForeignKeys($table);

            // Check if changes are risky (multiple column modifications, etc.)
            $isRiskyOperation = $this->isRiskyOperation($changes);

            if ($isRiskyOperation) {
                $this->info("  Using temporary table for safe updates...");
                $this->performSafeTableUpdate($table, $changes);
            } else {
                // Regular update process
                foreach ($changes['modify_columns'] ?? [] as $column) {
                    $message = "<fg=yellow>Column '{$column['name']}'</> will be modified:";
                    $this->line($message);

                    // Show detailed differences
                    if (isset($column['differences'])) {
                        foreach ($column['differences'] as $type => $diff) {
                            if (isset($diff['message'])) {
                                $this->line("    • {$diff['message']}");
                            }
                        }
                    }
                }

                // Ask for confirmation if not in force mode
                if (!empty($changes['modify_columns'])) {
                    $this->line("\n<fg=yellow>The above columns will be modified.</>");
                }

                // Drop foreign keys first
                $this->dropTableForeignKeys($table);

                // Drop indexes
                foreach ($changes['drop_indexes'] ?? [] as $index) {
                    if ($sql = $this->generateDropIndexSQL($table, $index)) {
                        DB::statement($sql);
                    }
                }

                // Drop columns
                foreach ($changes['drop_columns'] ?? [] as $column) {
                    // Check for and potentially drop foreign key constraints
                    $this->dropForeignKeyConstraintsForColumn($table, $column);

                    // Now proceed with dropping the column
                    if ($sql = $this->generateDropColumnSQL($table, $column)) {
                        DB::statement($sql);
                    }
                }

                // Add new columns
                foreach ($changes['add_columns'] ?? [] as $column) {
                    if ($sql = $this->generateAddColumnSQL($table, $column)) {
                        DB::statement($sql);
                    }
                }

                // Modify existing columns
                foreach ($changes['modify_columns'] ?? [] as $column) {
                    if ($sql = $this->generateAlterColumnSQL($table, $column)) {
                        DB::statement($sql);
                    }
                }

                // Recreate indexes and foreign keys
                $this->recreateTableIndexesAndForeignKeys($table, $changes);

                $startTime = microtime(true);
                // ... perform operation ...
                $endTime = microtime(true);
                $duration = round($endTime - $startTime, 2);
                $this->line(" <fg=green>✓</> Table update completed successfully <fg=gray>(in {$duration}s)</>");
            }
        } catch (\Exception $e) {
            // Log error and attempt to restore foreign keys if possible
            $this->error("  Error while processing changes: " . $e->getMessage());

            try {
                // Attempt to restore foreign keys
                $this->restoreForeignKeys($table, $originalForeignKeys, $originalReferencingKeys);
            } catch (\Exception $restoreException) {
                $this->warn("  Could not restore foreign keys: " . $restoreException->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Check if changes are risky and require temporary table
     */
    protected function isRiskyOperation(array $changes): bool
    {
        // Skip temporary table when using force option for simple column drops
        if (
            $this->option('force') &&
            count($changes['drop_columns'] ?? []) > 0 &&
            empty($changes['modify_columns']) &&
            empty($changes['add_columns'])
        ) {
            return false;
        }

        // Multiple column modifications
        if (count($changes['modify_columns'] ?? []) > 1) {
            return true;
        }

        // Column modifications with index changes
        if (
            !empty($changes['modify_columns']) &&
            (!empty($changes['drop_indexes']) || !empty($changes['add_indexes']))
        ) {
            return true;
        }

        // Data type changes that might cause data loss
        foreach ($changes['modify_columns'] ?? [] as $column) {
            if (isset($column['differences']['type'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Perform safe table update using temporary table
     */
    protected function performSafeTableUpdate(string $table, array $changes): void
    {
        $tempTable = $table . '_temp_' . time();
        $backupTable = $table . '_backup_' . time();

        try {
            // First drop all foreign key constraints referencing this table
            $referencingKeys = $this->getReferencingForeignKeys($table);
            $this->info("  Dropping foreign key constraints that reference '{$table}'...");
            foreach ($referencingKeys as $fk) {
                if ($this->checkConstraintExists($fk->TABLE_NAME, $fk->CONSTRAINT_NAME)) {
                    $this->info("    - Dropping {$fk->CONSTRAINT_NAME} from {$fk->TABLE_NAME}");
                    DB::statement("ALTER TABLE `{$fk->TABLE_NAME}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                }
            }

            // Create temporary table
            DB::statement("CREATE TABLE `{$tempTable}` LIKE `{$table}`");

            // Apply structure changes to temporary table
            $this->applyChangesToTable($tempTable, $changes);

            // Copy data
            $this->copyTableData($table, $tempTable);

            // Rename tables
            DB::statement("RENAME TABLE `{$table}` TO `{$backupTable}`, `{$tempTable}` TO `{$table}`");

            // Verify data integrity
            if ($this->verifyDataIntegrity($table, $backupTable) || $this->option('force')) {
                // Drop backup
                $this->info("  Dropping backup table...");
                DB::statement("DROP TABLE IF EXISTS `{$backupTable}`");
                $this->line(" <fg=green>✓</> Table update completed successfully.");

                // Restore foreign keys to point to the new table
                $this->info("  Restoring foreign key references...");
                foreach ($referencingKeys as $fk) {
                    // Skip if the referenced column was dropped
                    if (in_array($fk->REFERENCED_COLUMN_NAME, $changes['drop_columns'] ?? [])) {
                        $this->warn("    - Skipping foreign key {$fk->CONSTRAINT_NAME} because referenced column {$fk->REFERENCED_COLUMN_NAME} was dropped");
                        continue;
                    }

                    try {
                        // Check if referenced column still exists in the new table
                        $columnExists = DB::select("SHOW COLUMNS FROM `{$table}` LIKE ?", [$fk->REFERENCED_COLUMN_NAME]);
                        if (!empty($columnExists)) {
                            $sql = sprintf(
                                "ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`)",
                                $fk->TABLE_NAME,
                                $fk->CONSTRAINT_NAME,
                                $fk->COLUMN_NAME,
                                $table,
                                $fk->REFERENCED_COLUMN_NAME
                            );
                            DB::statement($sql);
                            $this->info("    - Restored foreign key {$fk->CONSTRAINT_NAME} on {$fk->TABLE_NAME}");
                        }
                    } catch (\Exception $e) {
                        $this->warn("    - Could not restore foreign key {$fk->CONSTRAINT_NAME}: " . $e->getMessage());
                        if (!$this->option('force')) {
                            throw $e;
                        }
                    }
                }
            } else {
                // Rollback if verification fails and not in force mode
                $this->warn("  Data integrity check failed. Rolling back...");
                DB::statement("DROP TABLE `{$table}`");
                DB::statement("RENAME TABLE `{$backupTable}` TO `{$table}`");
                throw new \Exception("Data integrity check failed");
            }
        } catch (\Exception $e) {
            // Cleanup on failure
            if ($this->tableExists($tempTable)) {
                DB::statement("DROP TABLE IF EXISTS `{$tempTable}`");
            }
            throw $e;
        }
    }

    /**
     * Copy data between tables with progress indication
     */
    protected function copyTableData(string $sourceTable, string $targetTable): void
    {
        // Get columns that exist in both tables
        $sourceColumns = DB::getSchemaBuilder()->getColumnListing($sourceTable);
        $targetColumns = DB::getSchemaBuilder()->getColumnListing($targetTable);
        $commonColumns = array_values(array_intersect($sourceColumns, $targetColumns));

        if (empty($commonColumns)) {
            throw new \Exception("No common columns found between source and target tables");
        }

        // Get NOT NULL constraints info from target table
        $targetColumnInfo = DB::select("SHOW FULL COLUMNS FROM `{$targetTable}`");
        $notNullColumns = [];
        foreach ($targetColumnInfo as $column) {
            if ($column->Null === 'NO' && $column->Default === null) {
                $notNullColumns[$column->Field] = true;
            }
        }

        // Build the column list with COALESCE for NOT NULL columns
        $selectColumns = [];
        foreach ($commonColumns as $column) {
            if (isset($notNullColumns[$column])) {
                // For NOT NULL columns without default, provide a default value based on column type
                $columnType = $this->getColumnType($targetTable, $column);
                $defaultValue = $this->getDefaultValueForType($columnType);
                $selectColumns[] = "COALESCE(`{$column}`, {$defaultValue}) AS `{$column}`";
            } else {
                $selectColumns[] = "`{$column}`";
            }
        }

        $selectColumnList = implode(', ', $selectColumns);
        $insertColumnList = '`' . implode('`, `', $commonColumns) . '`';

        $count = DB::table($sourceTable)->count();
        $batchSize = 1000;

        // Get table progress information
        $currentTableIndex = array_search($sourceTable, $this->tablesBeingProcessed ?? []) !== false ?
            array_search($sourceTable, $this->tablesBeingProcessed) + 1 : 0;
        $totalTables = count($this->tablesBeingProcessed ?? []);

        $tableProgress = $totalTables > 0 ? " [Table {$currentTableIndex}/{$totalTables}]" : "";
        $this->info("  Copying {$count} rows from {$sourceTable}{$tableProgress}...");
        $progress = $this->output->createProgressBar($count);

        for ($offset = 0; $offset < $count; $offset += $batchSize) {
            // Only select columns that exist in both tables
            DB::statement("INSERT INTO `{$targetTable}` ({$insertColumnList}) 
                          SELECT {$selectColumnList} FROM `{$sourceTable}` 
                          LIMIT {$offset}, {$batchSize}");

            $processed = min($offset + $batchSize, $count);
            $progress->advance(min($batchSize, $count - $offset));
        }

        $progress->finish();
        $this->info("\n  Data copy completed.");
    }

    /**
     * Get column type information from database
     */
    protected function getColumnType(string $table, string $column): string
    {
        $result = DB::select("SHOW COLUMNS FROM `{$table}` WHERE Field = ?", [$column]);
        return $result[0]->Type ?? 'varchar';
    }

    /**
     * Get default value for a column type
     */
    protected function getDefaultValueForType(string $type): string
    {
        // Extract base type without length/precision
        $baseType = preg_replace('/\(.*\)/', '', strtolower($type));

        // Handle different types
        if (in_array($baseType, ['int', 'bigint', 'tinyint', 'smallint', 'mediumint'])) {
            return '0';
        }

        if (in_array($baseType, ['decimal', 'float', 'double'])) {
            return '0.0';
        }

        if (in_array($baseType, ['datetime', 'timestamp'])) {
            return "CURRENT_TIMESTAMP()";
        }

        if ($baseType === 'date') {
            return "CURRENT_DATE()";
        }

        if ($baseType === 'time') {
            return "CURRENT_TIME()";
        }

        if ($baseType === 'boolean' || $baseType === 'bool') {
            return '0';
        }

        // Default for strings and other types
        return "''";
    }

    /**
     * Verify data integrity between two tables
     */
    protected function verifyDataIntegrity(string $newTable, string $oldTable): bool
    {
        // Compare row counts first - they should be the same
        $newCount = DB::table($newTable)->count();
        $oldCount = DB::table($oldTable)->count();

        if ($newCount !== $oldCount) {
            $this->error("  Row count mismatch: {$newTable}={$newCount}, {$oldTable}={$oldCount}");
            if ($this->option('force')) {
                $this->warn("  Ignoring row count mismatch due to --force option");
            } else {
                return false;
            }
        }

        // When in force mode, skip detailed integrity checks
        if ($this->option('force')) {
            return true;
        }

        // Compare checksums for key columns
        $columns = $this->getKeyColumns($newTable);
        foreach ($columns as $column) {
            try {
                $newChecksum = DB::select("CHECKSUM TABLE `{$newTable}`")[0]->Checksum;
                $oldChecksum = DB::select("CHECKSUM TABLE `{$oldTable}`")[0]->Checksum;

                if ($newChecksum !== $oldChecksum) {
                    $this->error("  Checksum mismatch for column {$column}");
                    return false;
                }
            } catch (\Exception $e) {
                $this->warn("  Could not verify checksum: " . $e->getMessage());
                // Continue with other checks instead of failing immediately
            }
        }

        return true;
    }

    /**
     * Get key columns for a table
     */
    protected function getKeyColumns(string $table): array
    {
        $columns = [];

        // Get primary key
        $primaryKey = DB::select("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
        if (!empty($primaryKey)) {
            $columns[] = $primaryKey[0]->Column_name;
        }

        // Get unique keys
        $uniqueKeys = DB::select("SHOW KEYS FROM `{$table}` WHERE Non_unique = 0");
        foreach ($uniqueKeys as $key) {
            if (!in_array($key->Column_name, $columns)) {
                $columns[] = $key->Column_name;
            }
        }

        return $columns;
    }

    /**
     * Check if table exists
     */
    protected function tableExists(string $table): bool
    {
        return Schema::hasTable($table);
    }

    /**
     * Attempt to restore foreign keys after a failure
     */
    protected function restoreForeignKeys(string $table, array $originalKeys, array $referencingKeys): void
    {
        try {
            foreach ($originalKeys as $fk) {
                $sql = sprintf(
                    "ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`)",
                    $table,
                    $fk->CONSTRAINT_NAME,
                    $fk->COLUMN_NAME,
                    $fk->REFERENCED_TABLE_NAME,
                    $fk->REFERENCED_COLUMN_NAME
                );
                DB::statement($sql);
                $this->line("    * Restored foreign key {$fk->CONSTRAINT_NAME}");
            }

            foreach ($referencingKeys as $fk) {
                $sql = sprintf(
                    "ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`)",
                    $fk->TABLE_NAME,
                    $fk->CONSTRAINT_NAME,
                    $fk->COLUMN_NAME,
                    $table,
                    $fk->REFERENCED_COLUMN_NAME
                );
                DB::statement($sql);
                $this->line("    * Restored foreign key {$fk->CONSTRAINT_NAME} on {$fk->TABLE_NAME}");
            }
        } catch (\Exception $e) {
            $this->warn("    ! Could not restore all foreign keys: " . $e->getMessage());
        }
    }

    /**
     * Check if line contains a column definition
     */
    protected function isColumnDefinition(string $line): bool
    {
        return preg_match('/\$table->([a-zA-Z]+)\(.*?\)/', $line) === 1;
    }

    /**
     * Check if line contains an index definition
     */
    protected function isIndexDefinition(string $line): bool
    {
        $patterns = [
            '/\$table->index\(/',
            '/\$table->unique\(/',
            '/\$table->primary\(/',
            '/->index\(\)/',
            '/->unique\(\)/'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if line contains a foreign key definition
     */
    protected function isForeignKeyDefinition(string $line): bool
    {
        $patterns = [
            '/\$table->foreign\(/',
            '/->references\(/',
            '/->on\(/',
            '/->foreignId\(.*?\)->constrained\(/'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse a column definition line
     */
    protected function parseColumnDefinition(string $line): array
    {
        $definition = [];

        // Handle special cases first
        if ($line === '$table->id();') {
            return [
                'type' => 'id',
                'name' => 'id',
                'params' => [],
                'modifiers' => ['unsigned' => true, 'autoIncrement' => true]
            ];
        }

        // Parse regular column definition
        if (preg_match('/\$table->([a-zA-Z]+)\((.*?)\)((?:->.*?)*);/', $line, $matches)) {
            $type = $matches[1];
            $params = $this->parseParameters($matches[2]);
            $modifiers = $this->parseModifiers($matches[3] ?? '');

            // Special handling for string type
            if ($type === 'string') {
                // Extract base parameters
                $name = $params[0] ?? null;
                $length = isset($params[1]) && is_numeric($params[1]) ? (int)$params[1] : 255;

                // Extract string-specific modifiers
                $charset = null;
                $collation = null;
                if (preg_match('/->charset\([\'"]([^\'"]+)[\'"]\)/', $matches[3] ?? '', $charsetMatch)) {
                    $charset = $charsetMatch[1];
                }
                if (preg_match('/->collation\([\'"]([^\'"]+)[\'"]\)/', $matches[3] ?? '', $collationMatch)) {
                    $collation = $collationMatch[1];
                }

                // Handle method chaining order variations
                $isNullable = strpos($matches[3] ?? '', '->nullable()') !== false;
                $isUnique = strpos($matches[3] ?? '', '->unique()') !== false;
                $hasIndex = strpos($matches[3] ?? '', '->index()') !== false;

                // Extract default value if exists
                $default = null;
                if (preg_match('/->default\((.*?)\)/', $matches[3] ?? '', $defaultMatch)) {
                    $default = trim($defaultMatch[1], "'\"");
                }

                // Extract comment if exists
                $comment = null;
                if (preg_match('/->comment\([\'"]([^\'"]+)[\'"]\)/', $matches[3] ?? '', $commentMatch)) {
                    $comment = $commentMatch[1];
                }

                // Extract after clause if exists
                $after = null;
                if (preg_match('/->after\([\'"]([^\'"]+)[\'"]\)/', $matches[3] ?? '', $afterMatch)) {
                    $after = $afterMatch[1];
                }

                return [
                    'type' => 'string',
                    'name' => $name,
                    'length' => $length,
                    'charset' => $charset,
                    'collation' => $collation,
                    'modifiers' => array_merge($modifiers, [
                        'nullable' => $isNullable,
                        'unique' => $isUnique,
                        'index' => $hasIndex,
                        'default' => $default,
                        'comment' => $comment,
                        'after' => $after
                    ]),
                    'original' => $line
                ];
            }

            // Special handling for decimal and float types
            if (in_array($type, ['decimal', 'float', 'double', 'unsignedDecimal'])) {
                $precision = $params[1] ?? ($type === 'decimal' ? 8 : null);
                $scale = $params[2] ?? ($type === 'decimal' ? 2 : null);

                return [
                    'type' => $type,
                    'name' => $params[0],
                    'precision' => $precision,
                    'scale' => $scale,
                    'modifiers' => array_merge($modifiers, [
                        'unsigned' => strpos($type, 'unsigned') === 0,
                        'precision' => $precision,
                        'scale' => $scale
                    ]),
                    'original' => $line
                ];
            }

            // Special handling for enum and set types
            if (in_array($type, ['enum', 'set'])) {
                $name = $params[0] ?? null;
                // Extract values from array parameter
                $values = [];
                if (preg_match('/\[(.*?)\]/', $matches[2], $valueMatches)) {
                    $values = array_map(function ($val) {
                        return trim(trim($val, "'\""), ' ');
                    }, explode(',', $valueMatches[1]));
                }

                return [
                    'type' => $type,
                    'name' => $name,
                    'values' => $values,
                    'modifiers' => array_merge($modifiers, [
                        'nullable' => strpos($matches[3] ?? '', '->nullable()') !== false,
                        'default' => $this->extractDefaultValue($matches[3] ?? '')
                    ]),
                    'original' => $line
                ];
            }

            // Extract length from parameters if available
            $length = $params[1] ?? null;

            $definition = [
                'type' => $type,
                'name' => $params[0] ?? null,
                'length' => $length,        // Add length support
                'params' => array_slice($params, $length ? 2 : 1),
                'modifiers' => $modifiers,
                'charset' => null,          // Add charset support
                'collation' => null,        // Add collation support
                'original' => $line
            ];

            return $definition;
        }

        return $definition;
    }

    /**
     * Parse an index definition line
     */
    protected function parseIndexDefinition(string $line): array
    {
        $definition = [];

        // 1. Temel index tanımları
        $indexPatterns = [
            // $table->index(['user_id']);
            '/\$table->index\(\[(.*?)\]\)/' => function ($matches) {
                return [
                    'type' => 'index',
                    'columns' => $this->parseColumns($matches[1]),
                    'name' => null
                ];
            },

            // $table->index('user_id');
            '/\$table->index\([\'"]([^\'"]+)[\'"]\)/' => function ($matches) {
                return [
                    'type' => 'index',
                    'columns' => [$matches[1]],
                    'name' => null
                ];
            },

            // $table->index(['user_id'], 'custom_name');
            '/\$table->index\(\[(.*?)\],\s*[\'"]([^\'"]+)[\'"]\)/' => function ($matches) {
                return [
                    'type' => 'index',
                    'columns' => $this->parseColumns($matches[1]),
                    'name' => null
                ];
            },

            // Method chaining: ->index()
            '/->index\(\)/' => function ($matches, $context) {
                if (preg_match('/\$table->([a-zA-Z]+)\([\'"]([^\'"]+)[\'"]/', $context, $colMatch)) {
                    return [
                        'type' => 'index',
                        'columns' => [$colMatch[2]],
                        'name' => null
                    ];
                }
                return null;
            },

            // Spatial index
            '/\$table->spatialIndex\([\'"]([^\'"]+)[\'"]\)/' => function ($matches) {
                return [
                    'type' => 'spatial',
                    'columns' => [$matches[1]],
                    'name' => null
                ];
            },

            // Hash algorithm specified
            '/\$table->index\(\[(.*?)\],\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\)/' => function ($matches) {
                return [
                    'type' => 'index',
                    'columns' => $this->parseColumns($matches[1]),
                    'name' => $matches[2],
                    'algorithm' => $matches[3]
                ];
            }
        ];

        // Try each pattern
        foreach ($indexPatterns as $pattern => $handler) {
            if (preg_match($pattern, $line, $matches)) {
                $result = $handler($matches, $line);
                if ($result) {
                    $definition = array_merge($result, [
                        'original' => $line
                    ]);

                    // Generate name if not provided
                    if (empty($definition['name'])) {
                        $definition['name'] = $this->generateIndexName(
                            $definition['type'],
                            $definition['columns']
                        );
                    }

                    break;
                }
            }
        }

        return $definition;
    }

    protected function parseColumns(string $columnsStr): array
    {
        $columns = [];
        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $columnsStr, $matches);
        if (!empty($matches[1])) {
            $columns = array_map('trim', $matches[1]);
        }
        return $columns;
    }

    protected function generateIndexName(string $type, array $columns): string
    {
        // Use the table name from the columns or a default value
        $tableName = $columns[0] ?? 'table';
        sort($columns);

        // Handle special cases
        switch ($type) {
            case 'spatial':
                return sprintf('%s_%s_spatialindex', $tableName, implode('_', $columns));
            case 'hash':
                return sprintf('%s_%s_hashindex', $tableName, implode('_', $columns));
            case 'unique':
                return sprintf('%s_%s_unique', $tableName, implode('_', $columns));
            default:
                return sprintf('%s_%s_index', $tableName, implode('_', $columns));
        }
    }

    /**
     * Parse a foreign key definition line
     */
    protected function parseForeignKeyDefinition(string $line): array
    {
        $definition = [];

        // Foreign key patterns
        $patterns = [
            // 1. Klasik foreign tanımı
            // $table->foreign('user_id')->references('id')->on('users')
            '/\$table->foreign\([\'"]([^\'"]+)[\'"]\)->references\([\'"]([^\'"]+)[\'"]\)->on\([\'"]([^\'"]+)[\'"]\)((?:->.*?)*)/'
            => function ($matches) {
                return [
                    'type' => 'foreign',
                    'column' => $matches[1],
                    'references' => $matches[2],
                    'on' => $matches[3],
                    'modifiers' => $this->parseForeignKeyModifiers($matches[4] ?? '')
                ];
            },

            // 2. foreignId ile tanımlama
            // $table->foreignId('user_id')->constrained()
            '/\$table->foreignId\([\'"]([^\'"]+)[\'"]\)(?:->constrained\((?:[\'"]([^\'"]+)[\'"]\))?)?((?:->.*?)*)/'
            => function ($matches) {
                $column = $matches[1];
                $table = $matches[2] ?? Str::plural(preg_replace('/_id$/', '', $column));
                return [
                    'type' => 'foreignId',
                    'column' => $column,
                    'references' => 'id',
                    'on' => $table,
                    'modifiers' => $this->parseForeignKeyModifiers($matches[3] ?? '')
                ];
            },

            // 3. foreignUuid ile tanımlama
            // $table->foreignUuid('user_id')->constrained()
            '/\$table->foreignUuid\([\'"]([^\'"]+)[\'"]\)(?:->constrained\((?:[\'"]([^\'"]+)[\'"]\))?)?((?:->.*?)*)/'
            => function ($matches) {
                $column = $matches[1];
                $table = $matches[2] ?? Str::plural(preg_replace('/_id$/', '', $column));
                return [
                    'type' => 'foreignUuid',
                    'column' => $column,
                    'references' => 'id',
                    'on' => $table,
                    'modifiers' => $this->parseForeignKeyModifiers($matches[3] ?? '')
                ];
            }
        ];

        foreach ($patterns as $pattern => $handler) {
            if (preg_match($pattern, $line, $matches)) {
                $parsed = $handler($matches);
                return array_merge($parsed, ['original' => $line]);
            }
        }

        return $definition;
    }

    protected function parseForeignKeyModifiers(string $modifiers): array
    {
        $parsed = [];

        // onDelete modifier
        if (preg_match('/->onDelete\([\'"]([^\'"]+)[\'"]\)/', $modifiers, $matches)) {
            $parsed['onDelete'] = $matches[1];
        }

        // onUpdate modifier
        if (preg_match('/->onUpdate\([\'"]([^\'"]+)[\'"]\)/', $modifiers, $matches)) {
            $parsed['onUpdate'] = $matches[1];
        }

        // cascadeOnDelete shorthand
        if (strpos($modifiers, '->cascadeOnDelete()') !== false) {
            $parsed['onDelete'] = 'cascade';
        }

        // nullOnDelete shorthand
        if (strpos($modifiers, '->nullOnDelete()') !== false) {
            $parsed['onDelete'] = 'set null';
        }

        // restrictOnDelete shorthand
        if (strpos($modifiers, '->restrictOnDelete()') !== false) {
            $parsed['onDelete'] = 'restrict';
        }

        return $parsed;
    }

    protected function generateForeignKeySQL(string $table, array $foreignKey): string
    {
        $onDelete = $foreignKey['modifiers']['onDelete'] ?? 'restrict';
        $onUpdate = $foreignKey['modifiers']['onUpdate'] ?? 'restrict';

        return sprintf(
            "ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s ON UPDATE %s",
            $table,
            $this->generateForeignKeyName($table, $foreignKey),
            $foreignKey['column'],
            $foreignKey['on'],
            $foreignKey['references'],
            strtoupper($onDelete),
            strtoupper($onUpdate)
        );
    }

    protected function generateForeignKeyName(string $table, array $foreignKey): string
    {
        return sprintf(
            '%s_%s_%s_foreign',
            $table,
            $foreignKey['column'],
            $foreignKey['on']
        );
    }

    /**
     * Get all tables from database
     */
    protected function getAllTables(): array
    {
        $migrationFiles = File::glob(database_path('migrations/*_create_*_table.php'));

        $tables = [];
        foreach ($migrationFiles as $file) {
            preg_match('/\d{4}_\d{2}_\d{2}_\d{6}_create_(.+)_table\.php/', basename($file), $matches);
            if (!empty($matches[1])) {
                $tables[] = $matches[1];
            }
        }

        return $tables;
    }

    /**
     * Parse parameters from migration definition
     */
    protected function parseParameters(string $paramsStr): array
    {
        $params = [];
        if (empty($paramsStr)) return $params;

        // Handle array parameters like in enum
        if (strpos($paramsStr, '[') !== false) {
            preg_match_all('/[\'"]([^\'"]+)[\'"]/', $paramsStr, $matches);
            return $matches[1] ?? [];
        }

        // Handle regular parameters
        preg_match_all('/(?:\'([^\']+)\'|"([^"]+)"|(\d+(?:\.\d+)?))/', $paramsStr, $matches);
        foreach ($matches[0] as $match) {
            $params[] = is_numeric($match) ? $match : trim($match, "'\"");
        }

        return $params;
    }

    /**
     * Extract parameters from migration matches
     */
    protected function extractParams(array $matches, array $definition): array
    {
        $params = [];

        if (empty($matches['params'])) {
            if (isset($definition['default_length'])) {
                $params[] = $definition['default_length'];
            }
            return $params;
        }

        $rawParams = $matches['params'];

        // Handle array parameters
        if (strpos($rawParams, '[') !== false) {
            preg_match_all('/[\'"]([^\'"]+)[\'"]/', $rawParams, $paramMatches);
            return $paramMatches[1] ?? [];
        }

        // Handle regular parameters
        foreach (explode(',', $rawParams) as $param) {
            $param = trim($param);
            if (empty($param)) continue;

            // Handle numeric values
            if (is_numeric($param)) {
                $params[] = $param;
                continue;
            }

            // Handle quoted strings
            $params[] = trim($param, "'\"");
        }

        return $params;
    }

    /**
     * Extract modifiers from migration matches
     */
    protected function extractModifiers(string $modifiersStr, array $allowedModifiers = []): array
    {
        $modifiers = [];
        if (empty($modifiersStr)) {
            return $modifiers;
        }

        // Extract modifier methods without parameters
        preg_match_all('/->([a-zA-Z]+)\(\)/', $modifiersStr, $matches);
        foreach ($matches[1] as $modifier) {
            if (empty($allowedModifiers) || in_array($modifier, $allowedModifiers)) {
                $modifiers[$modifier] = true;
            }
        }

        // Extract modifier methods with parameters
        preg_match_all('/->([a-zA-Z]+)\((.*?)\)/', $modifiersStr, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $modifier = $match[1];
            if (empty($allowedModifiers) || in_array($modifier, $allowedModifiers)) {
                $value = trim($match[2], "'\"");
                $modifiers[$modifier] = $value ?: true;
            }
        }

        return $modifiers;
    }

    /**
     * Parse column modifiers (nullable, unsigned, etc.)
     */
    protected function parseModifiers(string $modifiers): array
    {
        $parsed = [];
        if (empty($modifiers)) {
            return $parsed;
        }

        // Add support for all modifier combinations
        $modifierPatterns = [
            'nullable' => '/->nullable\(\)/',
            'unsigned' => '/->unsigned\(\)/',
            'unique' => '/->unique\(\)/',
            'index' => '/->index\(\)/',
            'default' => '/->default\((.*?)\)/',
            'charset' => '/->charset\([\'"]([^\'"]+)[\'"]\)/',
            'collation' => '/->collation\([\'"]([^\'"]+)[\'"]\)/',
            'comment' => '/->comment\([\'"]([^\'"]+)[\'"]\)/',
            'after' => '/->after\([\'"]([^\'"]+)[\'"]\)/',
        ];

        foreach ($modifierPatterns as $modifier => $pattern) {
            if (preg_match($pattern, $modifiers, $matches)) {
                if (isset($matches[1])) {
                    $parsed[$modifier] = trim($matches[1], "'\"");
                } else {
                    $parsed[$modifier] = true;
                }
            }
        }

        return $parsed;
    }

    /**
     * Add a new column to the table
     */
    protected function addColumn(string $table, array $column): void
    {
        $sql = $this->generateAddColumnSQL($table, $column);
        $this->line("  Executing: " . $sql);
        DB::statement($sql);
    }

    /**
     * Drop a column from the table
     */
    protected function dropColumn(string $table, string $column): void
    {
        $sql = $this->generateDropColumnSQL($table, $column);
        $this->line("  Executing: " . $sql);
        DB::statement($sql);
    }

    /**
     * Modify an existing column
     */
    protected function modifyColumn(string $table, array $column): void
    {
        $sql = $this->generateAlterColumnSQL($table, $column);
        $this->line("  Executing: " . $sql);
        DB::statement($sql);
    }

    protected $laravelSpecialColumns = [
        'created_at' => [
            'type' => 'timestamp',
            'nullable' => true,
            'alternatives' => ['created', 'creation_date', 'date_created', 'create_time']
        ],
        'updated_at' => [
            'type' => 'timestamp',
            'nullable' => true,
            'alternatives' => ['updated', 'last_update', 'modified_at', 'last_modified']
        ],
        'deleted_at' => [
            'type' => 'timestamp',
            'nullable' => true,
            'alternatives' => ['deleted', 'deletion_date', 'date_deleted']
        ],
        'id' => [
            'type' => 'bigint',
            'unsigned' => true,
            'autoIncrement' => true,
            'primary' => true,
            'alternatives' => ['uid', 'uuid', 'guid']
        ]
    ];

    /**
     * Generate SQL to drop a column
     */
    protected function generateDropColumnSQL(string $table, string $column): string
    {
        // Don't drop special Laravel columns
        foreach ($this->laravelSpecialColumns as $specialColumn => $info) {
            if (
                $column === $specialColumn ||
                (isset($info['alternatives']) && in_array($column, $info['alternatives']))
            ) {
                $this->warn("    Skipping drop of special column: {$column}");
                return '';
            }
        }
        return "ALTER TABLE `{$table}` DROP COLUMN `{$column}`";
    }

    /**
     * Generate SQL to add a column
     */
    protected function generateAddColumnSQL(string $table, array $column): string
    {
        $type = $this->mapLaravelTypeToMySQLType($column['type'], $column['params'] ?? []);
        $nullable = isset($column['modifiers']['nullable']) ? 'NULL' : 'NOT NULL';
        $default = '';

        if (isset($column['modifiers']['default'])) {
            $value = $column['modifiers']['default'];
            if (is_numeric($value)) {
                $default = "DEFAULT {$value}";
            } else {
                $default = "DEFAULT '" . addslashes($value) . "'";
            }
        }
        return "ALTER TABLE `{$table}` ADD COLUMN `{$column['name']}` {$type} {$nullable} {$default}";
    }

    /**
     * Generate SQL to alter a column
     */
    protected function generateAlterColumnSQL(string $table, array $column): string
    {
        // Don't modify special Laravel columns
        foreach ($this->laravelSpecialColumns as $specialColumn => $info) {
            if (
                $column['name'] === $specialColumn ||
                (isset($info['alternatives']) && in_array($column['name'], $info['alternatives']))
            ) {
                $this->warn("    Skipping modification of special column: {$column['name']}");
                return '';
            }
        }

        // Check if the column type is a modifier rather than a real type
        $type = $column['type'];
        if (in_array(strtolower($type), ['unique', 'index', 'primary'])) {
            // If it's a modifier, look up the actual existing column type from database
            $existingColumn = DB::select("SHOW COLUMNS FROM `{$table}` WHERE Field = ?", [$column['name']]);
            if (!empty($existingColumn)) {
                $type = $existingColumn[0]->Type;
            } else {
                $type = 'varchar(255)'; // Default fallback
            }
        } else {
            // Standard mapping for normal types
            $type = $this->mapLaravelTypeToMySQLType($type, $column['params'] ?? []);
        }

        $nullable = isset($column['modifiers']['nullable']) ? 'NULL' : 'NOT NULL';
        $default = '';

        if (isset($column['modifiers']['default'])) {
            $value = $column['modifiers']['default'];
            if (is_numeric($value)) {
                $default = "DEFAULT {$value}";
            } else {
                $default = "DEFAULT '" . addslashes($value) . "'";
            }
        }

        // Basic ALTER statement without constraints
        $sql = "ALTER TABLE `{$table}` MODIFY COLUMN `{$column['name']}` {$type} {$nullable} {$default}";

        // Add unique constraint in separate statement if needed
        if (isset($column['modifiers']['unique']) && $column['modifiers']['unique']) {
            $indexName = $column['name'] . '_unique';
            $sql .= "; ALTER TABLE `{$table}` ADD UNIQUE INDEX `{$indexName}` (`{$column['name']}`)";
        }

        return $sql;
    }

    /**
     * Check if a column is a Laravel special column
     */
    protected function isSpecialColumn(string $columnName): bool
    {
        foreach ($this->laravelSpecialColumns as $specialColumn => $info) {
            if (
                $columnName === $specialColumn ||
                (isset($info['alternatives']) && in_array($columnName, $info['alternatives']))
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a column should be preserved
     */
    protected function shouldPreserveColumn(string $columnName, bool $hasTimestamps): bool
    {
        // Always preserve primary key
        if ($columnName === 'id') {
            return true;
        }

        // Preserve timestamp columns if timestamps are used
        if ($hasTimestamps && in_array($columnName, ['created_at', 'updated_at'])) {
            return true;
        }

        // Check other special columns
        foreach ($this->laravelSpecialColumns as $specialColumn => $info) {
            if (
                $columnName === $specialColumn ||
                (isset($info['alternatives']) && in_array($columnName, $info['alternatives']))
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map Laravel type to MySQL type
     */
    protected function mapLaravelTypeToMySQLType(string $type, array $params = []): string
    {
        // Handle unsigned integer variants first
        $unsignedTypes = [
            'unsignedtinyinteger' => 'tinyint unsigned',
            'unsignedsmallinteger' => 'smallint unsigned',
            'unsignedmediuminteger' => 'mediumint unsigned',
            'unsignedinteger' => 'int unsigned',
            'unsignedbiginteger' => 'bigint unsigned'
        ];

        $lowerType = strtolower($type);
        if (isset($unsignedTypes[$lowerType])) {
            return $unsignedTypes[$lowerType];
        }

        if (isset($this->columnTypes[$type])) {
            $typeInfo = $this->columnTypes[$type];
            $mysqlType = $typeInfo['mysql_type'];

            // Add length if defined
            if (isset($params[0]) && in_array('length', $typeInfo['allows'] ?? [])) {
                return "{$mysqlType}({$params[0]})";
            }

            // Add default length if defined
            if (isset($typeInfo['default_length'])) {
                return "{$mysqlType}({$typeInfo['default_length']})";
            }

            return $mysqlType;
        }

        // Handle decimal and float types with precision and scale
        switch ($lowerType) {
            case 'decimal':
            case 'unsigneddecimal':
                $precision = $params['precision'] ?? 8;
                $scale = $params['scale'] ?? 2;
                return "decimal({$precision},{$scale})" .
                    (strpos($lowerType, 'unsigned') === 0 ? ' unsigned' : '');

            case 'float':
            case 'double':
                if (isset($params['precision']) && isset($params['scale'])) {
                    return "{$lowerType}({$params['precision']},{$params['scale']})";
                }
                return $lowerType;
        }

        // Handle enum and set types
        if (in_array($lowerType, ['enum', 'set'])) {
            if (!empty($params['values'])) {
                $values = array_map(function ($val) {
                    return "'" . addslashes($val) . "'";
                }, $params['values']);
                return "{$lowerType}(" . implode(',', $values) . ")";
            }
            // Fallback if no values provided
            return 'varchar(255)';
        }

        // Fallback for unknown types - ensure we return valid MySQL types
        if (strpos($lowerType, 'integer') !== false) {
            return 'int' . (strpos($lowerType, 'unsigned') === 0 ? ' unsigned' : '');
        }

        return strtolower($type);
    }

    /**
     * Drop all foreign keys from a table
     */
    protected function dropTableForeignKeys(string $table): void
    {
        $foreignKeys = $this->getTableForeignKeys($table);
        foreach ($foreignKeys as $fk) {
            if ($this->checkConstraintExists($table, $fk->CONSTRAINT_NAME)) {
                DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                $this->line("    * Dropped foreign key {$fk->CONSTRAINT_NAME}");
            }
        }

        // Also drop foreign keys that reference this table
        $referencingForeignKeys = $this->getReferencingForeignKeys($table);
        foreach ($referencingForeignKeys as $fk) {
            if ($this->checkConstraintExists($fk->TABLE_NAME, $fk->CONSTRAINT_NAME)) {
                DB::statement("ALTER TABLE `{$fk->TABLE_NAME}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
                $this->line("    * Dropped foreign key {$fk->CONSTRAINT_NAME} from {$fk->TABLE_NAME}");
            }
        }
    }

    /**
     * Recreate table indexes and foreign keys
     */
    protected function recreateTableIndexesAndForeignKeys(string $table, array $changes): void
    {
        // First recreate indexes
        foreach ($changes['add_indexes'] ?? [] as $index) {
            if ($sql = $this->generateAddIndexSQL($table, $index)) {
                DB::statement($sql);
                $this->line("    * Recreated index {$index['name']}");
            }
        }

        // Then recreate foreign keys
        $foreignKeys = $this->getTableForeignKeys($table);
        foreach ($foreignKeys as $fk) {
            if ($this->shouldRecreateConstraint($fk, $changes)) {
                $sql = sprintf(
                    "ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`)",
                    $table,
                    $fk->CONSTRAINT_NAME,
                    $fk->COLUMN_NAME,
                    $fk->REFERENCED_TABLE_NAME,
                    $fk->REFERENCED_COLUMN_NAME
                );
                DB::statement($sql);
                $this->line("    * Recreated foreign key {$fk->CONSTRAINT_NAME}");
            }
        }

        // Recreate foreign keys that reference this table
        $referencingForeignKeys = $this->getReferencingForeignKeys($table);
        foreach ($referencingForeignKeys as $fk) {
            if ($this->shouldRecreateConstraint($fk, $changes)) {
                $sql = sprintf(
                    "ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`)",
                    $fk->TABLE_NAME,
                    $fk->CONSTRAINT_NAME,
                    $fk->COLUMN_NAME,
                    $table,
                    $fk->REFERENCED_COLUMN_NAME
                );
                DB::statement($sql);
                $this->line("    * Recreated foreign key {$fk->CONSTRAINT_NAME} on {$fk->TABLE_NAME}");
            }
        }
    }

    /**
     * Get foreign keys for a table
     */
    protected function getTableForeignKeys(string $table): array
    {
        return DB::select("
            SELECT 
                CONSTRAINT_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE 
                TABLE_SCHEMA = ? AND
                TABLE_NAME = ? AND
                REFERENCED_TABLE_NAME IS NOT NULL
        ", [$this->getDatabaseName(), $table]);
    }

    /**
     * Get foreign keys that reference this table
     */
    protected function getReferencingForeignKeys(string $table): array
    {
        return DB::select("
            SELECT 
                CONSTRAINT_NAME,
                TABLE_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE 
                TABLE_SCHEMA = ? AND
                REFERENCED_TABLE_NAME = ?
        ", [config('database.connections.mysql.database'), $table]);
    }

    /**
     * Generate SQL to drop an index
     */
    protected function generateDropIndexSQL(string $table, string $indexName): string
    {
        $indexes = DB::select("SHOW INDEXES FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        if (empty($indexes)) {
            return '';
        }

        if ($this->checkConstraintExists($table, $indexName)) {
            return "ALTER TABLE `{$table}` DROP FOREIGN KEY `{$indexName}`";
        }

        return "ALTER TABLE `{$table}` DROP INDEX `{$indexName}`";
    }

    /**
     * Generate SQL to add an index
     */
    protected function generateAddIndexSQL(string $table, array $index): string
    {
        if ($this->checkIndexExists($table, $index['columns'], $index['type'] === 'unique')) {
            return '';
        }

        return sprintf(
            'ALTER TABLE `%s` ADD %s INDEX `%s` (`%s`)',
            $table,
            $index['type'] === 'unique' ? 'UNIQUE' : '',
            $index['name'],
            implode('`, `', $index['columns'])
        );
    }

    /**
     * Check if an index exists
     */
    protected function checkIndexExists(string $table, array $columns, bool $isUnique = false): bool
    {
        $columns = array_map(fn($col) => trim(trim($col, "'\""), ' '), $columns);
        sort($columns);

        $indexes = DB::select("SHOW INDEXES FROM `{$table}`");
        $groupedIndexes = [];

        foreach ($indexes as $index) {
            $keyName = $index->Key_name;
            if (!isset($groupedIndexes[$keyName])) {
                $groupedIndexes[$keyName] = [
                    'columns' => [],
                    'unique' => $index->Non_unique == 0
                ];
            }
            $groupedIndexes[$keyName]['columns'][] = $index->Column_name;
        }

        foreach ($groupedIndexes as $indexInfo) {
            sort($indexInfo['columns']);
            if ($columns === $indexInfo['columns'] && $isUnique === $indexInfo['unique']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a constraint exists
     */
    protected function checkConstraintExists(string $table, string $constraintName): bool
    {
        $constraints = DB::select("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
        ", [$this->getDatabaseName(), $table, $constraintName]);

        return !empty($constraints);
    }

    /**
     * Check if two database types match, with support for length and other attributes
     */
    /**
     * Check if Laravel column type matches with MySQL type
     *
     * @param string $laravelType The column type from Laravel migration
     * @param string $mysqlType The column type from MySQL database
     * @return bool Whether the types are equivalent
     */
    protected function typesMatch(string $laravelType, string $mysqlType): bool
    {
        // Normalize types to lowercase for comparison
        $laravelType = strtolower($laravelType);
        $mysqlType = strtolower($mysqlType);

        // Skip modifiers that aren't actual column types
        if ($this->isModifier($laravelType)) {
            return true;
        }

        // Handle special case for ID type
        if ($laravelType === 'id' && $mysqlType === 'bigint unsigned') {
            return true;
        }

        // Check unsigned type variations
        if ($this->matchesUnsignedType($laravelType, $mysqlType)) {
            return true;
        }

        // Parse MySQL type into components
        $mysqlTypeComponents = $this->parseMySQLType($mysqlType);

        // Check if types are equivalent based on type mappings
        return $this->typesAreEquivalent(
            $laravelType,
            $mysqlTypeComponents['baseType'],
            $mysqlTypeComponents['attributes']
        );
    }

    /**
     * Check if the type is a modifier rather than an actual column type
     */
    private function isModifier(string $type): bool
    {
        return in_array($type, ['unique', 'index', 'primary']);
    }

    /**
     * Check if Laravel unsigned type matches MySQL unsigned type
     */
    private function typesAreEquivalent(string $laravelType, string $mysqlBaseType, string $attributes): bool
    {
        $equivalents = [
            'string' => ['varchar', 'char'],
            'text' => ['text', 'mediumtext', 'longtext'],
            'integer' => ['int', 'integer'],
            'biginteger' => ['bigint'],
            'tinyinteger' => ['tinyint'],
            'smallinteger' => ['smallint'],
            'mediuminteger' => ['mediumint'],
            'boolean' => ['tinyint', 'bool', 'boolean'],
            'decimal' => ['decimal', 'numeric'],
            'float' => ['float', 'double'],
            'timestamp' => ['timestamp', 'datetime'],
            'binary' => ['blob', 'binary'],
            'foreignid' => ['bigint unsigned'],
        ];

        if (isset($equivalents[$laravelType])) {
            $baseTypeMatches = in_array($mysqlBaseType, $equivalents[$laravelType]);

            // For unsigned types, also check attributes
            if ($baseTypeMatches && strpos($laravelType, 'unsigned') !== false) {
                return strpos($attributes, 'unsigned') !== false;
            }

            return $baseTypeMatches;
        }

        // Handle enum and set types
        if (($laravelType === 'enum' || $laravelType === 'set') && function_exists('strpos')) {
            return $laravelType === $mysqlBaseType;
        }

        return $laravelType === $mysqlBaseType;
    }

    /**
     * Check if we should recreate a constraint
     */
    protected function shouldRecreateConstraint($constraint, array $changes): bool
    {
        // Implementation depends on your specific needs
        return true; // Default to always recreate
    }



    protected function extractDefaultValue(string $modifiers): ?string
    {
        if (preg_match('/->default\((.*?)\)/', $modifiers, $matches)) {
            return trim($matches[1], "'\"");
        }
        return null;
    }

    protected function defaultsAreDifferent($default1, $default2): bool
    {
        // If both are null or empty strings, they're considered the same
        if (($default1 === null || $default1 === '') && ($default2 === null || $default2 === '')) {
            return false;
        }

        // If one is null and the other isn't
        if (($default1 === null && $default2 !== null) || ($default1 !== null && $default2 === null)) {
            return true;
        }

        // Normalize values by trimming quotes and whitespace
        $default1 = trim($default1 ?? '', "'\"");
        $default2 = trim($default2 ?? '', "'\"");

        // Special case for CURRENT_TIMESTAMP
        if (strtoupper($default1) === 'CURRENT_TIMESTAMP' && strtoupper($default2) === 'CURRENT_TIMESTAMP') {
            return false;
        }

        // String comparison
        return $default1 !== $default2;
    }

    /**
     * Parse MySQL type into components
     */
    protected function parseMySQLType(string $mysqlType): array
    {
        $baseType = $mysqlType;
        $attributes = '';

        // Extract unsigned attribute
        if (strpos($mysqlType, 'unsigned') !== false) {
            $baseType = trim(str_replace('unsigned', '', $mysqlType));
            $attributes = 'unsigned';
        }

        // Extract type length/precision if present
        if (preg_match('/(.+?)\((.+?)\)/', $baseType, $matches)) {
            $baseType = $matches[1];
        }

        return [
            'baseType' => trim($baseType),
            'attributes' => $attributes
        ];
    }

    /**
     * Check if Laravel unsigned type matches MySQL unsigned type
     */
    protected function matchesUnsignedType(string $laravelType, string $mysqlType): bool
    {
        if (strpos($laravelType, 'unsigned') === 0) {
            $baseType = substr($laravelType, 8); // Remove 'unsigned'
            return strpos($mysqlType, $baseType) !== false &&
                strpos($mysqlType, 'unsigned') !== false;
        }
        return false;
    }



    /**
     * Helper function to access Laravel's config safely
     */
    private function getDatabaseName(): string
    {
        return \config('database.connections.mysql.database');
    }

    /**
     * Apply structure changes to a table (used for temporary table updates)
     */
    protected function applyChangesToTable(string $table, array $changes): void
    {
        // Drop any foreign keys first
        $this->dropTableForeignKeys($table);

        // Drop indexes
        foreach ($changes['drop_indexes'] ?? [] as $index) {
            if ($sql = $this->generateDropIndexSQL($table, $index)) {
                DB::statement($sql);
            }
        }

        // Drop columns
        foreach ($changes['drop_columns'] ?? [] as $column) {
            if ($sql = $this->generateDropColumnSQL($table, $column)) {
                DB::statement($sql);
            }
        }

        // Add new columns
        foreach ($changes['add_columns'] ?? [] as $column) {
            if ($sql = $this->generateAddColumnSQL($table, $column)) {
                DB::statement($sql);
            }
        }

        // Modify columns
        foreach ($changes['modify_columns'] ?? [] as $column) {
            if ($sql = $this->generateAlterColumnSQL($table, $column)) {
                DB::statement($sql);
            }
        }

        // Add new indexes
        foreach ($changes['add_indexes'] ?? [] as $index) {
            if ($sql = $this->generateAddIndexSQL($table, $index)) {
                DB::statement($sql);
            }
        }
    }

    /**
     * Check and drop foreign key constraints for a column before modification
     */
    protected function dropForeignKeyConstraintsForColumn(string $table, string $column): void
    {
        // Find all foreign keys that reference this column
        $references = DB::select("
            SELECT 
                CONSTRAINT_NAME,
                TABLE_NAME,
                COLUMN_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE 
                TABLE_SCHEMA = ? AND
                REFERENCED_TABLE_NAME = ? AND
                REFERENCED_COLUMN_NAME = ?
        ", [$this->getDatabaseName(), $table, $column]);

        if (!empty($references)) {
            $this->warn("  Found foreign key constraints referencing column '{$column}' in table '{$table}':");
            foreach ($references as $ref) {
                $this->warn("    - {$ref->TABLE_NAME}.{$ref->COLUMN_NAME} (constraint: {$ref->CONSTRAINT_NAME})");

                if ($this->option('force')) {
                    $this->info("    Dropping foreign key constraint {$ref->CONSTRAINT_NAME} from {$ref->TABLE_NAME} (force mode)");
                    DB::statement("ALTER TABLE `{$ref->TABLE_NAME}` DROP FOREIGN KEY `{$ref->CONSTRAINT_NAME}`");
                }
            }

            if (!$this->option('force')) {
                throw new \Exception("Cannot drop column with foreign key constraints. Use --force to override.");
            }
        }
    }

    protected function showColumnChanges(array $columns): void
    {
        $rows = [];
        foreach ($columns as $column) {
            $rows[] = [
                $column['name'],
                isset($column['differences']['type']) ? "Type: {$column['differences']['type']['existing']} → {$column['differences']['type']['migration']}" : '',
                isset($column['differences']['nullable']) ? "Nullable: " . ($column['differences']['nullable']['existing'] ? 'Yes' : 'No') . " → " . ($column['differences']['nullable']['migration'] ? 'Yes' : 'No') : '',
            ];
        }

        $this->table(['Column', 'Type Change', 'Nullable Change'], $rows);
    }

    /**
     * Display a summary of all operations performed
     */
    protected function displaySummary(array $stats): void
    {
        $this->output->writeln([
            '',
            ' <fg=bright-green>┌───────────────────────────────────┐</>',
            ' <fg=bright-green>│</> Operation Summary <fg=bright-green>               │</>',
            ' <fg=bright-green>└───────────────────────────────────┘</>',
            '',
            " <fg=white>➤</> Tables Processed:    <fg=green>{$stats['processed']}</> of {$stats['total']}",
            " <fg=white>➤</> Columns Added:       <fg=green>{$stats['added']}</>",
            " <fg=white>➤</> Columns Modified:    <fg=green>{$stats['modified']}</>",
            " <fg=white>➤</> Columns Dropped:     <fg=green>{$stats['dropped']}</>",
            " <fg=white>➤</> Errors Encountered:  <fg=" . ($stats['errors'] > 0 ? 'red' : 'green') . ">{$stats['errors']}</>",
            ''
        ]);
        
        // If there were errors, show details
        if ($stats['errors'] > 0 && !empty($stats['error_details'])) {
            $this->output->writeln(" <fg=yellow>Error Details:</>");
            foreach ($stats['error_details'] as $error) {
                $this->output->writeln(" <fg=red>✗</> {$error['table']}: {$error['message']}");
            }
            $this->output->writeln('');
        }
        
        // Show timing information
        if (isset($stats['duration'])) {
            $this->output->writeln(" <fg=gray>Total execution time: {$stats['duration']} seconds</>");
        }
    }  
}
