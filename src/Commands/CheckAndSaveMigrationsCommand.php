<?php

namespace Sencerhan\LaravelDbTools\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class CheckAndSaveMigrationsCommand extends Command
{
    protected $signature = 'migrations:check-and-save';
    protected $description = 'Check all migration files and save them to migrations table if tables exist';

    public function handle(): int
    {
        $this->info('Starting migration check process...');

        $migrationsPath = database_path('migrations');
        if (!File::exists($migrationsPath)) {
            $this->error('Migrations directory not found!');
            return Command::FAILURE;
        }

        $files = File::glob($migrationsPath . '/*_table.php');
        $this->info(count($files) . ' migration files found.');

        $batch = $this->getLastBatch() + 1;
        $processed = 0;

        foreach ($files as $file) {
            $filename = basename($file);
            
            // Skip if migration already exists in migrations table
            if ($this->migrationExists($filename)) {
                $this->line("Migration {$filename} already exists in migrations table. Skipping...");
                continue;
            }

            // Extract table name from filename
            preg_match('/\d{4}_\d{2}_\d{2}_\d{6}_create_(.+)_table\.php/', $filename, $matches);
            if (empty($matches[1])) {
                $this->warn("Could not extract table name from {$filename}. Skipping...");
                continue;
            }

            $tableName = $matches[1];

            // Check if table exists in database
            if ($this->tableExists($tableName)) {
                $this->saveMigration($filename, $batch);
                $this->info("✓ Migration {$filename} saved (table '{$tableName}' exists)");
                $processed++;
            } else {
                $this->warn("Table '{$tableName}' does not exist in database. Skipping {$filename}");
            }
        }

        $this->info("\nProcess completed! {$processed} migrations saved to migrations table.");
        return Command::SUCCESS;
    }

    private function getLastBatch(): int
    {
        return (int) DB::table('migrations')->max('batch') ?? 0;
    }

    private function migrationExists(string $filename): bool
    {
        return DB::table('migrations')->where('migration', $filename)->exists();
    }

    private function tableExists(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }

    private function saveMigration(string $filename, int $batch): void
    {
        DB::table('migrations')->insert([
            'migration' => pathinfo($filename, PATHINFO_FILENAME),
            'batch' => $batch
        ]);
    }
}
