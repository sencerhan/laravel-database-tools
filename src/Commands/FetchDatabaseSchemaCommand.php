<?php

namespace Sencerhan\LaravelDbTools\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Sync database tables with migration files
 * 
 * Features:
 * - Updates database tables according to migration files
 * - Adds columns defined in migrations to database
 * - Modifies column properties to match migration definitions
 * - Adds or updates indexes from migration files
 * - Ensures database matches migration definitions
 * 
 * Usage:
 * php artisan db:fetch                      # All tables
 * php artisan db:fetch --tables=users,posts # Specific tables
 */
class FetchDatabaseSchemaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:fetch 
        {--tables= : Specify tables separated by commas}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update database schema to match migration files';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info("\nStarting database update process based on migrations...");

        try {
            // Get tables to process
            $specifiedTables = $this->option('tables')
                ? explode(',', $this->option('tables'))
                : [];

            if (!empty($specifiedTables)) {
                $tables = $specifiedTables;
                $this->info("Processing specified tables: " . implode(', ', $tables));
            } else {
                $tables = $this->getAllTables();
                $this->info("Processing all tables: " . count($tables) . " tables found.");
            }

            foreach ($tables as $table) {
                $this->processTable($table);
            }

            $this->info("\nDatabase update process completed!");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("\nAn error occurred: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    /**
     * Get all tables from database
     *
     * @return array
     */
    protected function getAllTables(): array
    {
        // Instead of getting tables from database, get them from migration files
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
     * Process a single table
     *
     * @param string $table
     * @return void
     */
    protected function processTable(string $table): void
    {
        $this->info("\nProcessing table: {$table}");
        
        // Find migration file for table
        $migrationFile = $this->findMigrationFile($table);
        
        if (!$migrationFile) {
            $this->warn("  No migration file found for table {$table}");
            return;
        }
        
        $this->info("  Found migration file: " . basename($migrationFile));
        
        // Extract migration schema data
        $migrationSchema = $this->extractMigrationSchema($migrationFile, $table);
        if (empty($migrationSchema)) {
            $this->warn("  Could not extract schema from migration file");
            return;
        }
        
        // Check if table exists in database
        if (Schema::hasTable($table)) {
            $this->updateExistingTable($table, $migrationSchema);
        } else {
            $this->createTable($table, $migrationSchema);
        }
    }

    /**
     * Find migration file for table
     *
     * @param string $table
     * @return string|null
     */
    protected function findMigrationFile(string $table): ?string
    {
        $migrations = File::glob(database_path('migrations/*_create_' . $table . '_table.php'));
        
        if (count($migrations) > 0) {
            return $migrations[0];
        }
        
        // Also search for migrations that might have different naming pattern
        $migrations = File::glob(database_path('migrations/*_' . $table . '.php'));
        
        if (count($migrations) > 0) {
            return $migrations[0];
        }
        
        return null;
    }

    /**
     * Extract schema information from migration file
     *
     * @param string $migrationFile
     * @param string $table
     * @return array
     */
    protected function extractMigrationSchema(string $migrationFile, string $table): array
    {
        $content = File::get($migrationFile);
        
        // Extract Schema::create block
        preg_match('/Schema::create\([\'"]' . $table . '[\'"],\s*function\s*\(Blueprint\s*\$table\)\s*{(.*?)}\);/s', $content, $matches);
        
        if (empty($matches[1])) {
            return [];
        }
        
        $schemaBlock = $matches[1];
        
        // Parse schema to extract columns and indexes
        return $this->parseMigrationSchema($schemaBlock);
    }

    /**
     * Parse migration schema block to extract columns and indexes
     *
     * @param string $schemaBlock
     * @return array
     */
    protected function parseMigrationSchema(string $schemaBlock): array
    {
        $lines = explode("\n", $schemaBlock);
        $columns = [];
        $indexes = [];
        $hasTimestamps = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Check for timestamps method
            if ($line === '$table->timestamps();') {
                $hasTimestamps = true;
                continue;
            }
            
            if (empty($line)) {
                continue;
            }
            
            // Extract column definitions - Updated regex to match complex column definitions
            if (preg_match('/\$table->([a-zA-Z0-9_]+)\((?:[\'"](.*?)[\'"])?(?:,?\s*(.*?))?\)((?:->.*?)*);/', $line, $matches)) {
                $columnType = $matches[1];
                $columnName = $matches[2] ?? '';
                $columnParams = isset($matches[3]) && !empty(trim($matches[3])) ? explode(',', $matches[3]) : [];
                $modifiers = $matches[4] ?? '';
                
                // Handle special case for array_column with enum
                if (strpos($line, 'array_column') !== false) {
                    preg_match('/\$table->enum\([\'"](.+?)[\'"],\s*array_column\(.*?::cases\(\),\s*[\'"]value[\'"]\)\)((?:->.*?)*);/', $line, $enumMatches);
                    if (!empty($enumMatches)) {
                        $columnType = 'enum';
                        $columnName = $enumMatches[1];
                        $modifiers = $enumMatches[2] ?? '';
                    }
                }
                
                $columns[] = [
                    'type' => $columnType,
                    'name' => $columnName,
                    'params' => array_map('trim', $columnParams),
                    'modifiers' => $this->parseModifiers($modifiers),
                    'original' => $line
                ];
            }
            
            // Extract index definitions
            else if (preg_match('/\$table->([a-zA-Z0-9_]+)\(\[(.*?)\],\s*[\'"](.+?)[\'"]\);/', $line, $matches)) {
                $indexType = $matches[1]; // unique, index, etc.
                $indexColumns = array_map('trim', explode("','", str_replace(["'", '"'], '', $matches[2])));
                $indexName = $matches[3];
                
                $indexes[] = [
                    'type' => $indexType,
                    'columns' => $indexColumns,
                    'name' => $indexName,
                    'original' => $line
                ];
            }
        }
        
        return [
            'columns' => $columns,
            'indexes' => $indexes,
            'hasTimestamps' => $hasTimestamps
        ];
    }

    /**
     * Parse column modifiers with improved handling
     *
     * @param string $modifiersStr
     * @return array
     */
    protected function parseModifiers(string $modifiersStr): array
    {
        $modifiers = [];
        
        if (empty($modifiersStr)) {
            return $modifiers;
        }
        
        // Extract modifier patterns
        if (strpos($modifiersStr, '->nullable()') !== false) {
            $modifiers['nullable'] = true;
        }
        
        if (preg_match('/->default\((.*?)\)/', $modifiersStr, $matches)) {
            // Handle quoted strings and non-quoted values
            $defaultValue = trim($matches[1]);
            $modifiers['default'] = $defaultValue;
        }
        
        if (strpos($modifiersStr, '->useCurrent()') !== false) {
            $modifiers['default'] = 'CURRENT_TIMESTAMP';
        }
        
        if (preg_match('/->comment\([\'"](.+?)[\'"]\)/', $modifiersStr, $matches)) {
            $modifiers['comment'] = $matches[1];
        }
        
        if (strpos($modifiersStr, '->autoIncrement()') !== false) {
            $modifiers['autoIncrement'] = true;
        }
        
        if (strpos($modifiersStr, '->unique()') !== false) {
            $modifiers['unique'] = true;
        }
        
        return $modifiers;
    }

    /**
     * Update an existing table to match migration schema
     *
     * @param string $table
     * @param array $migrationSchema
     * @return void
     */
    protected function updateExistingTable(string $table, array $migrationSchema): void
    {
        // Get current table columns
        $existingColumns = $this->getTableColumns($table);
        $existingColumnsMap = [];
        
        foreach ($existingColumns as $column) {
            $existingColumnsMap[$column->Field] = $column;
        }
        
        // Get current indexes
        $existingIndexes = $this->getTableIndexes($table);
        $existingIndexesMap = $this->groupIndexes($existingIndexes);
        
        // Generate SQL to modify table
        $alterStatements = [];
        $changesDetected = false;
        
        // Process columns from migration
        foreach ($migrationSchema['columns'] as $column) {
            if (empty($column['name'])) {
                continue; // Skip columns without names (like id())
            }
            
            if (isset($existingColumnsMap[$column['name']])) {
                // Column exists, check if it needs to be modified
                $existingColumn = $existingColumnsMap[$column['name']];
                
                if ($this->columnNeedsUpdate($column, $existingColumn)) {
                    $changesDetected = true;
                    $alterStatements[] = $this->generateAlterColumnSQL($table, $column, $existingColumn);
                }
            } else {
                // Column doesn't exist, add it
                $changesDetected = true;
                $alterStatements[] = $this->generateAddColumnSQL($table, $column);
            }
        }
        
        // Handle timestamps if they are defined in migration
        if (!empty($migrationSchema['hasTimestamps'])) {
            $timestampColumns = ['created_at', 'updated_at'];
            foreach ($timestampColumns as $timestampColumn) {
                if (!isset($existingColumnsMap[$timestampColumn])) {
                    // Add timestamp column if it doesn't exist
                    $changesDetected = true;
                    $alterStatements[] = "ALTER TABLE `{$table}` ADD COLUMN `{$timestampColumn}` TIMESTAMP NULL";
                    $this->line("    * Adding timestamp column {$timestampColumn}");
                }
            }
        }
        
        // Process indexes from migration
        foreach ($migrationSchema['indexes'] as $index) {
            $indexName = $index['name'];
            
            if (!isset($existingIndexesMap[$indexName])) {
                // Index doesn't exist, add it
                $changesDetected = true;
                $alterStatements[] = $this->generateAddIndexSQL($table, $index);
            } else {
                // Index exists, check if it needs to be updated
                $existingIndex = $existingIndexesMap[$indexName];
                
                if ($this->indexNeedsUpdate($index, $existingIndex)) {
                    $changesDetected = true;
                    $alterStatements[] = $this->generateDropIndexSQL($table, $indexName);
                    $alterStatements[] = $this->generateAddIndexSQL($table, $index);
                }
            }
        }
        
        // Execute ALTER statements only if changes are detected
        if ($changesDetected) {
            if (!empty($alterStatements)) {
                $this->info("  Executing " . count($alterStatements) . " ALTER statements...");
                
                foreach ($alterStatements as $sql) {
                    try {
                        $this->line("    - " . $sql);
                        DB::statement($sql);
                    } catch (\Exception $e) {
                        $this->error("    ! Error executing SQL: " . $e->getMessage());
                    }
                }
                
                $this->info("  Table {$table} updated successfully.");
            }
        } else {
            $this->info("  Table {$table} already matches migration. No changes needed.");
        }
    }

    /**
     * Create a new table based on migration schema
     *
     * @param string $table
     * @param array $migrationSchema
     * @return void
     */
    protected function createTable(string $table, array $migrationSchema): void
    {
        // This functionality is already handled by Laravel migrations
        $this->warn("  Table {$table} does not exist. Use 'php artisan migrate' to create it.");
    }

    /**
     * Check if a column needs to be updated - improved with detailed logging
     *
     * @param array $migrationColumn
     * @param object $existingColumn
     * @return bool
     */
    protected function columnNeedsUpdate(array $migrationColumn, object $existingColumn): bool
    {
        // Compare column type
        $migrationColumnType = $this->mapLaravelTypeToMySQLType($migrationColumn['type'], $migrationColumn['params']);
        $currentColumnType = strtolower($existingColumn->Type);
        
        if (!$this->columnTypesMatch($migrationColumnType, $currentColumnType)) {
            $this->line("    * Column {$existingColumn->Field}: Type mismatch - Migration: {$migrationColumnType}, Current: {$currentColumnType}");
            return true;
        }
        
        // Compare nullable
        $migrationNullable = isset($migrationColumn['modifiers']['nullable']);
        $currentNullable = $existingColumn->Null === 'YES';
        
        if ($migrationNullable !== $currentNullable) {
            $migrationNullText = $migrationNullable ? 'NULL' : 'NOT NULL';
            $currentNullText = $currentNullable ? 'NULL' : 'NOT NULL';
            $this->line("    * Column {$existingColumn->Field}: Nullable mismatch - Migration: {$migrationNullText}, Current: {$currentNullText}");
            return true;
        }
        
        // Compare default value
        $migrationDefault = $migrationColumn['modifiers']['default'] ?? null;
        $currentDefault = $existingColumn->Default;
        
        // Special handling for defaults to avoid unnecessary updates
        if ($this->defaultsAreDifferent($migrationDefault, $currentDefault)) {
            $migrationDefaultText = is_null($migrationDefault) ? 'NULL' : $migrationDefault;
            $currentDefaultText = is_null($currentDefault) ? 'NULL' : $currentDefault;
            $this->line("    * Column {$existingColumn->Field}: Default mismatch - Migration: {$migrationDefaultText}, Current: {$currentDefaultText}");
            return true;
        }
        
        return false;
    }
    
    /**
     * Compare default values with special handling
     * 
     * @param mixed $default1
     * @param mixed $default2
     * @return bool
     */
    protected function defaultsAreDifferent($default1, $default2): bool
    {
        // If both are null or empty, they're the same
        if (($default1 === null || $default1 === '') && ($default2 === null || $default2 === '')) {
            return false;
        }
        
        // If one is null but other isn't
        if (($default1 === null && $default2 !== null) || ($default1 !== null && $default2 === null)) {
            return true;
        }
        
        // Handle quoted values
        $default1 = trim($default1, "'\"");
        $default2 = trim($default2, "'\"");
        
        // CURRENT_TIMESTAMP special case
        if (($default1 === 'CURRENT_TIMESTAMP' || $default1 === 'now()') && 
            ($default2 === 'CURRENT_TIMESTAMP' || $default2 === 'now()')) {
            return false;
        }
        
        // Compare as strings
        return (string)$default1 !== (string)$default2;
    }

    /**
     * Map Laravel column type to MySQL type with better precision
     *
     * @param string $laravelType
     * @param array $params
     * @return string
     */
    protected function mapLaravelTypeToMySQLType(string $laravelType, array $params): string
    {
        switch ($laravelType) {
            case 'id':
                return 'bigint(20) unsigned';
                
            case 'foreignId':
                return 'bigint(20) unsigned';
                
            case 'string':
                $length = !empty($params[0]) ? (int)$params[0] : 255;
                return "varchar({$length})";
                
            case 'char':
                $length = !empty($params[0]) ? (int)$params[0] : 255;
                return "char({$length})";
                
            case 'integer':
                return 'int(11)';
                
            case 'bigInteger':
                return 'bigint(20)';
                
            case 'tinyInteger':
                return 'tinyint(4)';
                
            case 'smallInteger':
                return 'smallint(6)';
                
            case 'mediumInteger':
                return 'mediumint(9)';
                
            case 'unsignedInteger':
                return 'int(10) unsigned';
                
            case 'unsignedBigInteger':
                return 'bigint(20) unsigned';
                
            case 'unsignedTinyInteger':
                return 'tinyint(3) unsigned';
                
            case 'unsignedSmallInteger':
                return 'smallint(5) unsigned';
                
            case 'unsignedMediumInteger':
                return 'mediumint(8) unsigned';
                
            case 'decimal':
                $precision = !empty($params[0]) ? trim($params[0]) : 8;
                $scale = !empty($params[1]) ? trim($params[1]) : 2;
                return "decimal({$precision},{$scale})";
                
            case 'float':
                return 'float';
                
            case 'double':
                return 'double';
                
            case 'boolean':
                return 'tinyint(1)';
                
            case 'date':
                return 'date';
                
            case 'datetime':
                return 'datetime';
                
            case 'time':
                return 'time';
                
            case 'timestamp':
                return 'timestamp';
                
            case 'text':
                return 'text';
                
            case 'mediumText':
                return 'mediumtext';
                
            case 'longText':
                return 'longtext';
                
            case 'json':
                return 'json';
                
            case 'enum':
                return 'enum'; // Special handling needed for enum values
                
            case 'uuid':
                return 'char(36)';
                
            case 'ipAddress':
                return 'varchar(45)';
                
            case 'macAddress':
                return 'varchar(17)';
                
            default:
                return $laravelType;
        }
    }

    /**
     * Check if column types match
     *
     * @param string $type1
     * @param string $type2
     * @return bool
     */
    protected function columnTypesMatch(string $type1, string $type2): bool
    {
        // Normalize types for comparison
        $type1 = strtolower($type1);
        $type2 = strtolower($type2);
        
        // Extract base type without size/length
        preg_match('/^([a-z]+)/', $type1, $matches1);
        preg_match('/^([a-z]+)/', $type2, $matches2);
        
        $baseType1 = $matches1[1] ?? $type1;
        $baseType2 = $matches2[1] ?? $type2;
        
        // Some types are equivalent
        $equivalentTypes = [
            'int' => ['integer', 'int'],
            'varchar' => ['varchar', 'string', 'char'],
            'tinyint' => ['tinyint', 'tinyinteger', 'boolean'],
            'timestamp' => ['timestamp', 'datetime'],
        ];
        
        foreach ($equivalentTypes as $group) {
            if (in_array($baseType1, $group) && in_array($baseType2, $group)) {
                return true;
            }
        }
        
        return $baseType1 === $baseType2;
    }

    /**
     * Generate SQL to alter an existing column with better data type handling
     *
     * @param string $table
     * @param array $column
     * @param object $existingColumn
     * @return string
     */
    protected function generateAlterColumnSQL(string $table, array $column, object $existingColumn): string
    {
        $sqlType = $this->mapLaravelTypeToMySQLType($column['type'], $column['params']);
        $nullable = isset($column['modifiers']['nullable']) ? 'NULL' : 'NOT NULL';
        $default = '';
        
        if (isset($column['modifiers']['default'])) {
            $defaultVal = $column['modifiers']['default'];
            
            if ($defaultVal === 'CURRENT_TIMESTAMP') {
                $default = "DEFAULT CURRENT_TIMESTAMP";
            } else if (is_numeric($defaultVal) && !str_starts_with($defaultVal, "'") && !str_starts_with($defaultVal, '"')) {
                $default = "DEFAULT {$defaultVal}";
            } else {
                // Remove quotes if they're already in the default value
                $defaultVal = trim($defaultVal, "'\"");
                $default = "DEFAULT '{$defaultVal}'";
            }
        }
        
        return "ALTER TABLE `{$table}` MODIFY COLUMN `{$column['name']}` {$sqlType} {$nullable} {$default}";
    }

    /**
     * Generate SQL to add a new column with better type support
     *
     * @param string $table
     * @param array $column
     * @return string
     */
    protected function generateAddColumnSQL(string $table, array $column): string
    {
        $sqlType = $this->mapLaravelTypeToMySQLType($column['type'], $column['params']);
        $nullable = isset($column['modifiers']['nullable']) ? 'NULL' : 'NOT NULL';
        $default = '';
        
        if (isset($column['modifiers']['default'])) {
            $defaultVal = $column['modifiers']['default'];
            
            if ($defaultVal === 'CURRENT_TIMESTAMP') {
                $default = "DEFAULT CURRENT_TIMESTAMP";
            } else if (is_numeric($defaultVal) && !str_starts_with($defaultVal, "'") && !str_starts_with($defaultVal, '"')) {
                $default = "DEFAULT {$defaultVal}";
            } else {
                // Remove quotes if they're already in the default value
                $defaultVal = trim($defaultVal, "'\"");
                $default = "DEFAULT '{$defaultVal}'";
            }
        }
        
        // Special handling for enum type
        if ($column['type'] === 'enum') {
            // Since we cannot easily extract enum values here, we'll use a more generic approach
            $this->warn("  - Adding enum column {$column['name']} with generic type. You may need to adjust it manually.");
            $sqlType = "VARCHAR(255)";
        }
        
        // Check for after clause to position the column correctly
        $after = '';
        if (isset($column['after'])) {
            $after = " AFTER `{$column['after']}`";
        }
        
        return "ALTER TABLE `{$table}` ADD COLUMN `{$column['name']}` {$sqlType} {$nullable} {$default}{$after}";
    }

    /**
     * Get columns for table from database
     *
     * @param string $table
     * @return array
     */
    protected function getTableColumns(string $table): array
    {
        return DB::select("SHOW FULL COLUMNS FROM `{$table}`");
    }

    /**
     * Get indexes for table from database
     *
     * @param string $table
     * @return array
     */
    protected function getTableIndexes(string $table): array
    {
        return DB::select("SHOW INDEXES FROM `{$table}`");
    }

    /**
     * Check if an index needs to be updated
     *
     * @param array $migrationIndex
     * @param array $existingIndex
     * @return bool
     */
    protected function indexNeedsUpdate(array $migrationIndex, array $existingIndex): bool
    {
        // Check if index type is different (unique vs. normal)
        if ($migrationIndex['type'] === 'unique' && !$existingIndex['unique']) {
            $this->line("    * Index {$migrationIndex['name']}: Type mismatch - Migration: UNIQUE, Current: INDEX");
            return true;
        }
        
        if ($migrationIndex['type'] !== 'unique' && $existingIndex['unique']) {
            $this->line("    * Index {$migrationIndex['name']}: Type mismatch - Migration: INDEX, Current: UNIQUE");
            return true;
        }
        
        // Check if index columns are different
        $migrationColumns = $migrationIndex['columns'];
        $existingColumns = $existingIndex['columns'];
        
        sort($migrationColumns);
        sort($existingColumns);
        
        if ($migrationColumns !== $existingColumns) {
            $migrationColumnsList = implode(', ', $migrationColumns);
            $existingColumnsList = implode(', ', $existingColumns);
            $this->line("    * Index {$migrationIndex['name']}: Columns mismatch - Migration: [{$migrationColumnsList}], Current: [{$existingColumnsList}]");
            return true;
        }
        
        return false;
    }

    /**
     * Generate SQL to add a new index
     *
     * @param string $table
     * @param array $index
     * @return string
     */
    protected function generateAddIndexSQL(string $table, array $index): string
    {
        $columns = '`' . implode('`, `', $index['columns']) . '`';
        $indexType = $index['type'] === 'unique' ? 'UNIQUE' : '';
        $indexName = $index['name'];
        
        return "ALTER TABLE `{$table}` ADD {$indexType} INDEX `{$indexName}` ({$columns})";
    }

    /**
     * Generate SQL to drop an index
     *
     * @param string $table
     * @param string $indexName
     * @return string
     */
    protected function generateDropIndexSQL(string $table, string $indexName): string
    {
        return "ALTER TABLE `{$table}` DROP INDEX `{$indexName}`";
    }

    /**
     * Group indexes by name
     *
     * @param array $indexes
     * @return array
     */
    protected function groupIndexes(array $indexes): array
    {
        $grouped = [];
        foreach ($indexes as $index) {
            $name = $index->Key_name;
            if ($name === 'PRIMARY') {
                continue; // Skip primary key
            }
            
            if (!isset($grouped[$name])) {
                $grouped[$name] = [
                    'type' => $index->Index_type,
                    'columns' => [],
                    'unique' => ($index->Non_unique == 0)
                ];
            }
            $grouped[$name]['columns'][] = $index->Column_name;
        }
        return $grouped;
    }
}
