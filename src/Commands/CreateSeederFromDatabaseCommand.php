<?php

namespace Sencerhan\LaravelDbTools\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Generate seeder files from existing database table data
 * 
 * Features:
 * - Creates individual seeder files for each table
 * - Updates DatabaseSeeder with new seeder classes
 * - Handles NULL values and defaults
 * - Preserves data types (numbers, strings, booleans)
 * - Special handling for timestamps
 * - Escapes special characters in string values
 * 
 * Usage:
 * php artisan seeders:from-database                      # All tables
 * php artisan seeders:from-database --tables=users,posts # Specific tables
 * php artisan seeders:from-database --without_tables=logs # Exclude tables
 */
class CreateSeederFromDatabaseCommand extends Command
{
    protected $signature = 'seeders:from-database 
        {--tables= : Specify tables separated by commas}
        {--without_tables= : Exclude tables separated by commas}';
    protected $description = 'Create seeder files from existing database tables';

    public function handle(): int
    {
        $this->info('Starting seeder creation process...');
        
        $specifiedTables = $this->option('tables')
            ? explode(',', $this->option('tables'))
            : [];
            
        $tables = empty($specifiedTables) 
            ? $this->getAllTables() 
            : $specifiedTables;

        $this->info('Total ' . count($tables) . ' tables found.');

        $createdSeeders = [];
        foreach ($tables as $table) {
            $this->info("Processing '{$table}' table...");
            
            $seederClass = $this->createSeederForTable($table);
            if ($seederClass) {
                $createdSeeders[] = $seederClass;
                $this->info("[✓] {$seederClass} successfully created.");
            } else {
                $this->warn("[!] No seeder created for {$table} table as it is empty.");
            }
        }

        if (!empty($createdSeeders)) {
            $this->info('Updating DatabaseSeeder...');
            $this->updateDatabaseSeeder($createdSeeders);
            $this->info("[✓] DatabaseSeeder updated. Total " . count($createdSeeders) . " seeders created.");
        } else {
            $this->warn("[!] No seeders created. Tables might be empty.");
        }

        $this->info('Process completed.');
        return Command::SUCCESS;
    }

    protected function getAllTables(): array
    {
        $tables = DB::select('SHOW TABLES');
        $dbName = DB::getDatabaseName();
        $columnName = 'Tables_in_' . $dbName;
        
        $excludedTables = $this->option('without_tables') 
            ? explode(',', $this->option('without_tables')) 
            : [];
        
        return array_filter(array_map(function($table) use ($columnName) {
            return $table->$columnName;
        }, $tables), function($table) use ($excludedTables) {
            return $table !== 'migrations' && !in_array($table, $excludedTables);
        });
    }

    private function createSeederForTable(string $table): ?string
    {
        $data = DB::table($table)->get();
        if ($data->isEmpty()) {
            return null;
        }

        $this->line("- Reading " . $data->count() . " records from {$table} table...");
        
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