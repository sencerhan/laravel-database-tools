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

    // ... Diğer tüm metodlar ve özellikler buraya gelecek ...
    // Önceki dosyanızdaki tüm içeriği buraya kopyalayın
} 