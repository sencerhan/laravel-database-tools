<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CreateSeederFromDatabaseCommand extends Command
{
    protected $signature = 'seeders:from-database {--table=* : Belirli tabloları seç}';
    protected $description = 'Veritabanındaki tablolardan seeder dosyaları oluştur';

    public function handle(): int
    {
        $this->info('Seeder oluşturma işlemi başlatıldı...');
        
        // Tablo listesini al
        $specifiedTables = $this->option('table');
        $tables = empty($specifiedTables) 
            ? $this->getAllTables() 
            : $specifiedTables;

        $this->info('Toplam ' . count($tables) . ' tablo bulundu.');

        $createdSeeders = [];
        foreach ($tables as $table) {
            $this->info("'{$table}' tablosu işleniyor...");
            
            $seederClass = $this->createSeederForTable($table);
            if ($seederClass) {
                $createdSeeders[] = $seederClass;
                $this->info("[✓] {$seederClass} başarıyla oluşturuldu.");
            } else {
                $this->warn("[!] {$table} tablosu boş olduğu için seeder oluşturulmadı.");
            }
        }

        if (!empty($createdSeeders)) {
            $this->info('DatabaseSeeder güncelleniyor...');
            $this->updateDatabaseSeeder($createdSeeders);
            $this->info("[✓] DatabaseSeeder güncellendi. Toplam " . count($createdSeeders) . " seeder oluşturuldu.");
        } else {
            $this->warn("[!] Hiç seeder oluşturulmadı. Tablolar boş olabilir.");
        }

        $this->info('İşlem tamamlandı.');
        return Command::SUCCESS;
    }

    protected function getAllTables(): array
    {
        $tables = DB::select('SHOW TABLES');
        $dbName = DB::getDatabaseName();
        $columnName = 'Tables_in_' . $dbName;
        
        return array_filter(array_map(function($table) use ($columnName) {
            return $table->$columnName;
        }, $tables), function($table) {
            return $table !== 'migrations';
        });
    }

    private function createSeederForTable(string $table): ?string
    {
        $data = DB::table($table)->get();
        if ($data->isEmpty()) {
            return null;
        }

        $this->line("- {$table} tablosundan " . $data->count() . " kayıt okunuyor...");
        
        $className = Str::studly(Str::singular($table)) . 'Seeder';
        $content = $this->generateSeederContent($className, $table, $data);
        
        $path = database_path("seeders/{$className}.php");
        File::put($path, $content);

        return $className;
    }

    private function updateDatabaseSeeder(array $seeders): void
    {
        $seederCalls = array_map(function ($seeder) {
            return "            $seeder::class,";
        }, $seeders);

        $content = <<<PHP
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        \$this->call([
{$this->formatSeederCalls($seederCalls)}
        ]);
    }
}
PHP;

        File::put(database_path('seeders/DatabaseSeeder.php'), $content);
    }

    private function generateSeederContent(string $className, string $table, $data): string
    {
        $columns = DB::getSchemaBuilder()->getColumnListing($table);
        
        $rows = [];
        foreach ($data as $row) {
            $rowArray = (array) $row;
            $values = [];
            
            foreach ($columns as $column) {
                $value = $rowArray[$column] ?? null;
                
                if (in_array($column, ['created_at', 'updated_at'])) {
                    $values[$column] = 'now()';
                } else if (is_null($value)) {
                    // NULL değer kontrolü
                    $columnInfo = DB::select("SHOW COLUMNS FROM `{$table}` WHERE Field = ?", [$column])[0];
                    if ($columnInfo->Null === 'NO' && $columnInfo->Default !== null) {
                        // NOT NULL ve default değeri varsa onu kullanalım
                        $values[$column] = is_numeric($columnInfo->Default) 
                            ? $columnInfo->Default 
                            : "'" . addslashes($columnInfo->Default) . "'";
                    } else {
                        $values[$column] = 'null';
                    }
                } else if (is_bool($value)) {
                    $values[$column] = $value ? 'true' : 'false';
                } else if (is_numeric($value)) {
                    $stringValue = (string) $value;
                    if ($stringValue[0] === '0') {
                        $values[$column] = "'" . $value . "'";
                    } else {
                        $values[$column] = $value;
                    }
                } else {
                    $values[$column] = "'" . addslashes($value) . "'";
                }
            }
            
            $formattedValues = [];
            foreach ($values as $key => $value) {
                $formattedValues[] = "                '$key' => $value";
            }
            
            $rows[] = '[' . PHP_EOL . 
                      implode(',' . PHP_EOL, $formattedValues) . 
                      PHP_EOL . '            ]';
        }

        return <<<PHP
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class {$className} extends Seeder
{
    public function run(): void
    {
        \$rows = [
            {$this->formatRows($rows)}
        ];

        foreach (\$rows as \$row) {
            // NULL değerleri filtrele
            \$row = array_filter(\$row, function(\$value) {
                return \$value !== 'null';
            });
            
            DB::table('{$table}')->insert(\$row);
        }
    }
}
PHP;
    }

    private function formatRows(array $rows): string
    {
        if (empty($rows)) {
            return '';
        }

        $lastKey = array_key_last($rows);
        $formatted = '';

        foreach ($rows as $key => $row) {
            $formatted .= $row;
            if ($key !== $lastKey) {
                $formatted .= ',' . PHP_EOL . '            ';
            }
        }

        return $formatted;
    }

    private function formatSeederCalls(array $calls): string
    {
        if (empty($calls)) {
            return '';
        }

        $lastKey = array_key_last($calls);
        $formatted = '';

        foreach ($calls as $key => $call) {
            $formatted .= rtrim($call, ',');
            if ($key !== $lastKey) {
                $formatted .= ',' . PHP_EOL;
            }
        }

        return $formatted;
    }
} 