# Laravel Schema Generator

Laravel Schema Generator is a Laravel package that generates migration files using a concise **schema DSL (Domain Specific Language)**.  
Based on the DSL and your existing database structure, it determines whether to generate:

- Create table migration  
- Add columns migration  
- Modify columns migration (DBAL-powered)  
- Drop columns migration  
- Composite indexes and unique indexes  

The generator compares your **DSL schema** against the **actual database schema**, making migration generation predictable and automated.

---

## Requirements

| Component | Supported Versions |
|----------|--------------------|
| PHP      | 8.1+               |
| Laravel  | 9, 10, 11, 12      |
| Doctrine DBAL | Optional — Required only for modify-column support |
| Database | MySQL recommended for modify operations |

> SQLite does not support `->change()`. Modify-column migrations should be executed on MySQL/PostgreSQL.

---

## Installation

```bash
composer require laraib15/laravel-schema-generator --dev
```

Laravel automatically registers the service provider.

The generator command becomes available:

```bash
php artisan generate:crud
```

If you want modify-column (`->change()`) support, install DBAL in your Laravel app:

```bash
composer require doctrine/dbal
```

---

## Important Notes About Modify-Column Support

Modify-column detection relies on Doctrine DBAL to inspect the **actual database schema**.  
Because of this:

### 1. Migrations must be executed before DBAL can detect column types
If a migration has **not yet been run**, the table or column will not exist in the database.

In that case, the generator cannot detect the existing column type and will treat it as a **new column**, producing:

```
xxxx_add_columns_to_table.php
```

instead of:

```
xxxx_modify_columns_in_table.php
```

### 2. Modify operations require:
- Doctrine DBAL installed  
- The table/column already existing in the database  

Example:

```bash
php artisan generate:crud "orders:id:uuid:primary,name:string"
php artisan migrate

php artisan generate:crud "orders:name:text"
```

If you skip `php artisan migrate`, DBAL cannot detect existing columns.

### 3. SQLite cannot run modify migrations  
Even with DBAL installed, SQLite does not support `->change()`.  
Use MySQL/PostgreSQL for production modify support.

---

# Schema DSL Overview

The DSL (Domain-Specific Language) format looks like this:

```
table:column:type:option1:option2=value,another_column:type:option,...
```

- Each column definition is separated by commas  
- Each option modifies column behavior  

---

# Supported Column Types

```
bigIncrements, bigInteger, binary, boolean,
char, date, datetime, dateTimeTz,
decimal, double, enum, float,
foreignId, geometry, increments, integer,
json, jsonb, longText, mediumText,
morphs, uuidMorphs, smallInteger, tinyInteger,
text, string, set, timestamp, timestampTz,
time, softDeletes, timestamps,
uuid, year
```

---

# Column Options

| Option      | Example                          | Meaning                       |
|-------------|----------------------------------|-------------------------------|
| nullable    | `name:string:nullable`           | Adds `->nullable()`           |
| default     | `status:string:default=pending`  | Sets a default value          |
| length      | `code:string:20`                 | Defines string length         |
| comment     | `id:uuid:comment=Primary key`    | Adds column comment           |
| unique      | `email:string:unique`            | Adds unique index             |
| index       | `category:string:index`          | Adds index                    |
| unsigned    | `age:integer:unsigned`           | Unsigned integer              |
| drop        | `status:string:drop`             | Drops a column                |

---

# Foreign Keys

### Define a foreign key

```
user_id:foreignId:constrained=users:onDelete=cascade
```

Generates:

```php
$table->foreignId('user_id')
      ->constrained('users')
      ->onDelete('cascade');
```

### Drop a foreign key

```
user_id:foreignId:drop
```

Generates:

```php
$table->dropForeign(['user_id']);
$table->dropColumn('user_id');
```

---

# Composite Indexes

### Unique Index

```
unique:user_id|ordered_at
```

### Index

```
index:category|status
```

---

# Usage Examples

## 1. Create a New Table

```bash
php artisan generate:crud "
orders:
  id:uuid:primary,
  user_id:foreignId:constrained=users:onDelete=cascade,
  status:enum:values=pending|processing|completed:default=pending,
  amount:decimal:precision=10:scale=2,
  timestamps
"
```

Generates:

```
xxxx_create_orders_table.php
```

---

## 2. Add New Columns

```bash
php artisan generate:crud "orders:tracking_code:string:nullable"
```

Generates:

```
xxxx_add_columns_to_orders_table.php
```

---

## 3. Modify Column Type (DBAL)

```bash
php artisan generate:crud "orders:status:text"
```

Generates:

```
xxxx_modify_columns_in_orders_table.php
```

Contains:

```php
$table->text('status')->change();
```

---

## 4. Drop Columns

```bash
php artisan generate:crud "orders:tracking_code:string:drop"
```

Generates:

```
xxxx_drop_columns_from_orders_table.php
```

---

# Auto-Diff Logic

The generator compares:

```
< DSL schema >   <-->   < DB schema >
```

Then decides:

| Condition | Output |
|----------|--------|
| Table does not exist | Create migration |
| Column exists in DSL but not DB | Add column migration |
| Column type or nullability differs | Modify migration |
| Column removed from DSL | Drop migration |
| No differences | No migration created |

---

# Testing

## SQLite Tests (Default)

```bash
vendor/bin/phpunit
```

## MySQL Integration Tests

Enable MySQL tests:

```bash
CRUD_MYSQL_TEST_ENABLED=true vendor/bin/phpunit --group=mysql
```

Tests cover:

- Modify-column detection  
- Doctrine DBAL introspection  
- Foreign key dropping  
- Combined modify + add sequences  

---

# License

MIT License  
https://github.com/laraib15
