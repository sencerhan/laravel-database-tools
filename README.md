# Laravel DB Tools

Useful tools for creating migration and seeder files from Laravel database tables.

## Installation

Add the package to your project using Composer:

```bash
composer require sencerhan/laravel-db-tools
```

For Laravel 8 and above, the ServiceProvider will be automatically registered.

## Usage

### Creating Migrations

To create migration files for all tables in the database:

```bash
php artisan migrations:from-database
```

To create migration files for specific tables:

```bash
php artisan migrations:from-database --tables=users,posts,comments
```

To create migration files excluding specific tables:

```bash
php artisan migrations:from-database --without_tables=users,posts,comments
```

### Creating Seeders

To create seeder files for all tables in the database:

```bash
php artisan seeders:from-database
```

To create seeder files for specific tables:

```bash
php artisan seeders:from-database --table=users --table=posts
```

To create seeder files for specific tables (alternative method):

```bash
php artisan seeders:from-database --tables=users,posts,comments
```

### Checking and Saving Migrations

To check all migration files and save them to migrations table if tables exist:

```bash
php artisan migrations:check-and-save
```

This command will:
1. Check all migration files in migrations directory
2. For each migration file, check if the corresponding table exists in database
3. If table exists and migration is not already in migrations table, save it
4. Skip migrations that are already in migrations table or whose tables don't exist

This is useful when you have generated migrations from an existing database and want to mark them as migrated.

## Laravel Version Compatibility

This package is compatible with the following Laravel versions:
- Laravel 8.x
- Laravel 9.x
- Laravel 10.x
- Laravel 11.x

## License

Licensed under the MIT license. See the [LICENSE](LICENSE) file for more information.