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

| Component | Version |
|----------|---------|
| PHP      | 8.1+    |
| Laravel  | 10 or 11 |
| Doctrine DBAL | Required for modify-column support |
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

> Modify operations require MySQL/PostgreSQL.

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

Rules:

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

```bash
CRUD_MYSQL_TEST_ENABLED=true vendor/bin/phpunit --group=mysql
```

---

# License

MIT License  
https://github.com/laraib15
