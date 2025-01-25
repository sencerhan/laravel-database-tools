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

    // ... Diğer tüm metodlar ve özellikler buraya gelecek ...
    // Önceki dosyanızdaki tüm içeriği buraya kopyalayın
} 