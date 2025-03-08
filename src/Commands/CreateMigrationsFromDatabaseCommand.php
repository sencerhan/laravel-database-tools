<?php

namespace Sencerhan\LaravelDbTools\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generate migration files from existing database tables
 * 
 * Features:
 * - Supports all MySQL column types including spatial types
 * - Automatic detection of foreign keys
 * - Handles unique and normal indexes
 * - Native PHP 8.1+ enum support
 * - Supports timestamps with CURRENT_TIMESTAMP
 * - Auto-detects UUID, IP Address and MAC Address fields
 * - Preserves column comments and defaults
 * 
 * Usage:
 * php artisan migrations:from-database                      # All tables
 * php artisan migrations:from-database --tables=users,posts # Specific tables
 * php artisan migrations:from-database --without_tables=logs # Exclude tables
 */
class CreateMigrationsFromDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrations:from-database 
        {--tables= : Specify tables separated by commas}
        {--without_tables= : Exclude tables separated by commas}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create migration files from existing database tables';

    /**
     * Temporarily stores index information
     * @var array|null
     */
    protected $currentIndexes = null;

    /**
     * Temporarily stores table name
     * @var string|null
     */
    protected $currentTable = null;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info("\nStarting migration creation process...");

        try {
            // Belirtilen tablolar veya tüm tabloları al
            $tables = $this->getTables();

            if (empty($tables)) {
                $this->warn('No tables found to process!');
                return Command::SUCCESS;
            }

            $this->processTables($tables);

            $this->info("\nMigration creation process completed!");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("\nAn error occurred: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    protected function getTables(): array
    {
        $specifiedTables = $this->option('tables')
            ? explode(',', $this->option('tables'))
            : [];
        $excludedTables = $this->option('without_tables')
            ? explode(',', $this->option('without_tables'))
            : [];

        if (!empty($specifiedTables)) {
            return array_filter(
                $specifiedTables,
                fn($table) =>
                $table !== 'migrations' && !in_array($table, $excludedTables)
            );
        }

        // Tabloları oluşturulma tarihine göre sıralı olarak al
        $tables = DB::select("
            SELECT TABLE_NAME, CREATE_TIME 
            FROM information_schema.TABLES 
            WHERE TABLE_SCHEMA = ?
            ORDER BY CREATE_TIME ASC
        ", [config('database.connections.mysql.database')]);

        return array_filter(
            array_map(fn($table) => $table->TABLE_NAME, $tables),
            fn($table) => $table !== 'migrations' && !in_array($table, $excludedTables)
        );
    }

    protected function processTables($tables)
    {
        $totalTables = count($tables);
        $current = 1;

        foreach ($tables as $table) {
            $this->info("\n[$current/$totalTables] Creating migration for '{$table}' table...");

            try {
                // Sütun bilgilerini al
                $this->line("  - Getting column information...");
                $columns = DB::select("SHOW FULL COLUMNS FROM `{$table}`");
                $this->info("  ✓ Column information retrieved");

                // İndeks bilgilerini al
                $this->line("  - Getting index information...");
                $indexes = DB::select("SHOW INDEXES FROM `{$table}`");
                $this->info("  ✓ Index information retrieved");

                $this->line("  - Creating migration file...");
                $migrationContent = $this->generateMigrationContent($table, $columns, $indexes);
                $fileName = $this->createMigrationFile($table, $migrationContent);
                $this->info("  ✓ Migration file created");

                $this->info("  Success! Migration file created: {$fileName}");
            } catch (\Exception $e) {
                $this->error("\n  ERROR! Failed to create migration for {$table} table: " . $e->getMessage());
            }

            $current++;
        }
    }

    protected function generateMigrationContent($table, $columns, $indexes)
    {
        // Index bilgisini sınıf içinde saklayalım
        $this->currentIndexes = $indexes;
        // Tablo adını da saklayalım
        $this->currentTable = $table;

        $schemaLines = [];
        $indexLines = [];

        foreach ($columns as $column) {
            $line = $this->generateColumnDefinition($column);
            if ($line !== null) {
                $schemaLines[] = $line;
            }
        }

        // İndeksleri gruplandıralım
        $groupedIndexes = $this->groupIndexes($indexes);
        foreach ($groupedIndexes as $indexName => $indexData) {
            $line = $this->generateIndexDefinition($indexName, $indexData);
            if ($line) {
                $indexLines[] = $line;
            }
        }

        $allLines = array_merge($schemaLines, $indexLines);

        // Temizlik yapalım
        $this->currentIndexes = null;
        $this->currentTable = null;

        return $this->getMigrationTemplate($table, $allLines);
    }

    protected function generateColumnDefinition($column)
    {
        if (in_array($column->Field, ['created_at', 'updated_at'])) {
            return null;
        }

        // ID alanları için özel kontrol
        if ($column->Field === 'id' && str_contains(strtolower($column->Extra), 'auto_increment')) {
            return '$table->id();'; // Laravel'in id() metodunu kullanalım
        }

        // Foreign key kontrolü
        if (str_ends_with($column->Field, '_id')) {
            $nullable = $column->Null === 'YES' ? '->nullable()' : '';
            return "\$table->foreignId('{$column->Field}'){$nullable};";
        }

        $type = $this->getColumnType($column);
        $modifiers = [];

        if ($column->Null === 'YES') {
            $modifiers[] = '->nullable()';
        }

        // Timestamp için özel kontrol
        if (str_contains(strtolower($column->Type), 'timestamp')) {
            if ($column->Default === 'CURRENT_TIMESTAMP') {
                $modifiers[] = '->useCurrent()';
            } elseif ($column->Default !== null) {
                $default = "'{$column->Default}'";
                $modifiers[] = "->default({$default})";
            }
        }
        // Diğer tipler için default değer kontrolü
        elseif ($column->Default !== null) {
            $default = is_numeric($column->Default) ? $column->Default : "'{$column->Default}'";
            $modifiers[] = "->default({$default})";
        }

        if (str_contains(strtolower($column->Extra), 'auto_increment')) {
            $modifiers[] = '->autoIncrement()';
        }

        if ($column->Comment) {
            $modifiers[] = "->comment('{$column->Comment}')";
        }

        // Unique index kontrolü - sadece index tablosunda olmayan unique'ler için
        if (str_contains(strtolower($column->Key), 'uni') && !$this->hasExplicitIndex($column->Field, $this->currentTable)) {
            $modifiers[] = '->unique()';
        }

        $modifiersStr = implode('', $modifiers);

        // Decimal için özel işlem ekleyelim
        if (str_contains($type, 'decimal,')) {
            $parts = explode(',', $type);
            return "\$table->decimal('{$column->Field}', " . trim($parts[1]) . ", " . trim($parts[2]) . "){$modifiersStr};";
        }

        // VARCHAR için özel işlem
        if ($type === 'string') {
            preg_match('/varchar\((\d+)\)/', strtolower($column->Type), $matches);
            $length = $matches[1] ?? 255;
            return "\$table->string('{$column->Field}', {$length}){$modifiersStr};";
        }

        return "\$table->{$type}('{$column->Field}'){$modifiersStr};";
    }

    protected function getColumnType($column)
    {
        $type = strtolower($column->Type);

        // Integer types
        if (str_contains($type, 'int')) {
            if (str_contains($type, 'unsigned')) {
                if (str_contains($type, 'tiny')) return 'unsignedTinyInteger';
                if (str_contains($type, 'small')) return 'unsignedSmallInteger';
                if (str_contains($type, 'medium')) return 'unsignedMediumInteger';
                if (str_contains($type, 'big')) return 'unsignedBigInteger';
                return 'unsignedInteger';
            }
            if (str_contains($type, 'tiny')) return 'tinyInteger';
            if (str_contains($type, 'small')) return 'smallInteger';
            if (str_contains($type, 'medium')) return 'mediumInteger';
            if (str_contains($type, 'big')) return 'bigInteger';
            return 'integer';
        }

        // String & Text types
        if (str_contains($type, 'varchar')) return 'string';
        if (str_contains($type, 'char')) return 'char';
        if (str_contains($type, 'text')) {
            if (str_contains($type, 'tiny')) return 'tinyText';
            if (str_contains($type, 'medium')) return 'mediumText';
            if (str_contains($type, 'long')) return 'longText';
            return 'text';
        }

        // Binary types
        if (str_contains($type, 'blob')) {
            if (str_contains($type, 'tiny')) return 'binary';
            if (str_contains($type, 'medium')) return 'mediumBinary';
            if (str_contains($type, 'long')) return 'longBinary';
            return 'binary';
        }
        if (str_contains($type, 'binary')) return 'binary';
        if (str_contains($type, 'varbinary')) return 'binary';

        // Date and Time types
        if (str_contains($type, 'timestamp')) return 'timestamp';
        if (str_contains($type, 'datetime')) return 'datetime';
        if (str_contains($type, 'date')) return 'date';
        if (str_contains($type, 'time')) return 'time';
        if (str_contains($type, 'year')) return 'year';

        // Numeric types
        if (str_contains($type, 'decimal')) {
            preg_match('/decimal\((\d+),(\d+)\)/', $type, $matches);
            if (isset($matches[1], $matches[2])) {
                return "decimal, {$matches[1]}, {$matches[2]}";
            }
            return 'decimal';
        }
        if (str_contains($type, 'double')) return 'double';
        if (str_contains($type, 'float')) return 'float';
        if (str_contains($type, 'real')) return 'float';

        // Boolean type
        if (str_contains($type, 'bool') || str_contains($type, 'tinyint(1)')) return 'boolean';

        // JSON type
        if (str_contains($type, 'json')) return 'json';

        // Spatial types
        if (str_contains($type, 'geometry')) return 'geometry';
        if (str_contains($type, 'point')) return 'point';
        if (str_contains($type, 'linestring')) return 'lineString';
        if (str_contains($type, 'polygon')) return 'polygon';
        if (str_contains($type, 'multipoint')) return 'multiPoint';
        if (str_contains($type, 'multilinestring')) return 'multiLineString';
        if (str_contains($type, 'multipolygon')) return 'multiPolygon';
        if (str_contains($type, 'geometrycollection')) return 'geometryCollection';

        // Set & Enum types
        if (str_contains($type, 'enum')) {
            preg_match('/enum\((.*?)\)/', $type, $matches);
            $values = str_replace("'", '', $matches[1]);

            // Laravel 8+ native enum desteği için sınıf adı oluştur
            $enumClassName = Str::studly(Str::singular($this->currentTable)) . Str::studly($column->Field) . 'Type';

            // Enum değerlerini PHP enum formatına dönüştür
            $enumValues = array_map('trim', explode(',', $values));
            $enumValues = array_map(function ($value) {
                return Str::upper(Str::snake($value));
            }, $enumValues);

            // Enum sınıfını oluştur
            $this->createEnumClass($enumClassName, $enumValues);

            return "enum('{$column->Field}', \\App\\Enums\\{$enumClassName}::class)";
        }

        // IP Address type
        if ($column->Field === 'ip_address' || $column->Field === 'ip') {
            return 'ipAddress';
        }

        // MAC Address type
        if ($column->Field === 'mac_address') {
            return 'macAddress';
        }

        // UUID type
        if (str_contains($type, 'char(36)') && (
            str_contains($column->Field, 'uuid') ||
            str_contains($column->Field, 'guid')
        )) {
            return 'uuid';
        }

        // Default to string if no match
        return 'string';
    }

    protected function createEnumClass(string $className, array $values): void
    {
        $enumPath = app_path('Enums');
        if (!File::exists($enumPath)) {
            File::makeDirectory($enumPath, 0755, true);
        }

        $cases = implode("\n    ", array_map(function ($value) {
            return "case {$value};";
        }, $values));

        $content = <<<PHP
<?php

namespace App\Enums;

enum {$className}: string
{
    {$cases}
}
PHP;

        File::put("{$enumPath}/{$className}.php", $content);
        $this->line("  - Created enum class: App\\Enums\\{$className}");
    }

    protected function groupIndexes($indexes)
    {
        $grouped = [];
        foreach ($indexes as $index) {
            $name = $index->Key_name;
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

    protected function generateIndexDefinition($indexName, $indexData)
    {
        if ($indexName === 'PRIMARY') {
            return null; // Primary key zaten column tanımında belirtildi
        }

        // Eğer tek kolonlu unique index ise ve kolon tanımında zaten unique varsa, tekrar eklemeyelim
        if ($indexData['unique'] && count($indexData['columns']) === 1) {
            $columnName = reset($indexData['columns']);
            if ($this->hasExplicitIndex($columnName)) {
                return null;
            }
        }

        $columns = "'" . implode("', '", $indexData['columns']) . "'";

        if ($indexData['unique']) {
            return "\$table->unique([{$columns}], '{$indexName}');";
        }

        return "\$table->index([{$columns}], '{$indexName}');";
    }

    protected function getMigrationTemplate($table, $lines)
    {
        $className = 'Create' . Str::studly($table) . 'Table';
        $linesStr = implode("\n            ", $lines);

        return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('{$table}', function (Blueprint \$table) {
            {$linesStr}
            \$table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('{$table}');
    }
};
PHP;
    }

    protected function createMigrationFile($table, $content)
    {
        $fileName = date('Y_m_d_His') . '_create_' . $table . '_table.php';
        $path = database_path('migrations/' . $fileName);
        file_put_contents($path, $content);
        return $fileName;
    }

    protected function hasExplicitIndex($columnName, $tableName = null): bool
    {
        static $indexColumns = [];
        $tableName = $tableName ?? $this->currentTable;
        $cacheKey = "{$tableName}.{$columnName}";

        if (!isset($indexColumns[$cacheKey])) {
            $indexColumns[$cacheKey] = false;
            if (!empty($this->currentIndexes)) {
                foreach ($this->currentIndexes as $index) {
                    if ($index->Column_name === $columnName && $index->Non_unique == 0) {
                        $indexColumns[$cacheKey] = true;
                        break;
                    }
                }
            }
        }

        return $indexColumns[$cacheKey];
    }
}
