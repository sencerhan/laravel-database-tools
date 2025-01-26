<?php

namespace Sencerhan\LaravelDbTools\Commands;

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

    protected $currentIndexes = null;
    protected $currentTable = null;

    public function handle()
    {
        $this->info("\nMigration oluşturma işlemi başlatılıyor...");

        try {
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

    // ... Diğer tüm metodları da buraya ekleyin ...
} 