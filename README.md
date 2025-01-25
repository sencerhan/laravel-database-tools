# Laravel DB Tools

Laravel veritabanı tablolarından migration ve seeder dosyaları oluşturmak için kullanışlı araçlar.

## Kurulum

Composer kullanarak paketi projenize ekleyin:

```bash
composer require sencerhan/laravel-db-tools
```

## Kullanım

### Migration Oluşturma

Veritabanındaki tüm tablolar için migration dosyaları oluşturmak için:

```bash
php artisan migrations:from-database
```

Belirli tablolar için migration dosyaları oluşturmak için:

```bash
php artisan migrations:from-database --table=users --table=posts
```

### Seeder Oluşturma

Veritabanındaki tüm tablolar için seeder dosyaları oluşturmak için:

```bash
php artisan seeders:from-database
```

Belirli tablolar için seeder dosyaları oluşturmak için:

```bash
php artisan seeders:from-database --table=users --table=posts
```

## Lisans

MIT lisansı altında lisanslanmıştır. Daha fazla bilgi için [LICENSE](LICENSE) dosyasına bakın. 