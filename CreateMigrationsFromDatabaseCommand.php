<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateMigrationsFromDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrations:from-database {--table=* : Belirli tabloları seç}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Veritabanındaki tablolardan migration dosyaları oluştur';

    /**
     * Geçici olarak index bilgilerini saklar
     * @var array|null
     */
    protected $currentIndexes = null;

    /**
     * Geçici olarak tablo adını saklar
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
        $this->info("\nMigration oluşturma işlemi başlatılıyor...");

        try {
            // Belirtilen tablolar veya tüm tabloları al
            $tables = $this->getTables();

            if (empty($tables)) {
                $this->warn('İşlenecek tablo bulunamadı!');
                return Command::SUCCESS;
            }

            $this->processTables($tables);

            $this->info("\nMigration oluşturma işlemi tamamlandı!");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("\nBir hata oluştu: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    protected function getTables(): array 
    {
        $specifiedTables = $this->option('table');
        
        if (!empty($specifiedTables)) {
            return array_filter($specifiedTables, fn($table) => $table !== 'migrations');
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
            fn($table) => $table !== 'migrations'
        );
    }

    protected function processTables($tables)
    {
        $totalTables = count($tables);
        $current = 1;

        foreach ($tables as $table) {
            $this->info("\n[$current/$totalTables] '{$table}' tablosu için migration oluşturuluyor...");
            
            try {
                // Sütun bilgilerini al
                $this->line("  - Sütun bilgileri alınıyor...");
                $columns = DB::select("SHOW FULL COLUMNS FROM `{$table}`");
                $this->info("  ✓ Sütun bilgileri alındı");
                
                // İndeks bilgilerini al
                $this->line("  - İndeks bilgileri alınıyor...");
                $indexes = DB::select("SHOW INDEXES FROM `{$table}`");
                $this->info("  ✓ İndeks bilgileri alındı");

                $this->line("  - Migration dosyası oluşturuluyor...");
                $migrationContent = $this->generateMigrationContent($table, $columns, $indexes);
                $fileName = $this->createMigrationFile($table, $migrationContent);
                $this->info("  ✓ Migration dosyası oluşturuldu");
                
                $this->info("  Başarılı! Migration dosyası oluşturuldu: {$fileName}");
            } catch (\Exception $e) {
                $this->error("\n  HATA! {$table} tablosu için migration oluşturulurken hata: " . $e->getMessage());
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
        
        // Integer tipleri için Laravel standartları
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
        
        // Diğer tipler için Laravel standartları
        if (str_contains($type, 'varchar')) return 'string';
        if (str_contains($type, 'char')) return 'char';
        if (str_contains($type, 'text')) {
            if (str_contains($type, 'tiny')) return 'tinyText';
            if (str_contains($type, 'medium')) return 'mediumText';
            if (str_contains($type, 'long')) return 'longText';
            return 'text';
        }
        if (str_contains($type, 'json')) return 'json';
        if (str_contains($type, 'blob')) return 'binary';
        if (str_contains($type, 'timestamp')) return 'timestamp';
        if (str_contains($type, 'datetime')) return 'datetime';
        if (str_contains($type, 'date')) return 'date';
        if (str_contains($type, 'time')) return 'time';
        if (str_contains($type, 'year')) return 'year';
        if (str_contains($type, 'decimal')) {
            preg_match('/decimal\((\d+),(\d+)\)/', $type, $matches);
            if (isset($matches[1], $matches[2])) {
                return "decimal('{$matches[1]}', '{$matches[2]}')";
            }
            return 'decimal';
        }
        if (str_contains($type, 'float')) return 'float';
        if (str_contains($type, 'double')) return 'double';
        if (str_contains($type, 'boolean')) return 'boolean';
        if (str_contains($type, 'enum')) {
            preg_match('/enum\((.*?)\)/', $type, $matches);
            $values = str_replace("'", '', $matches[1]);
            return "enum('{$column->Field}', [{$values}])";
        }
        
        return 'string';
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