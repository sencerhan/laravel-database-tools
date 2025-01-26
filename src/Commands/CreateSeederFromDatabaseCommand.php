<?php

namespace Sencerhan\LaravelDbTools\Commands;

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

    // ... Diğer tüm metodları da buraya ekleyin ...
} 