# OMPS coding rules

Audience: developers working on OMPS. These rules are what keep one code base running unchanged on
PHP 7.2 – 8.x and MariaDB 10.1+ / MySQL 5.5 – 8.0 (design doc §01). `php omps compat:check` enforces
most of them; the rest are checked in review.

## PHP

- No syntax newer than PHP 7.2: no arrow functions `fn()`, typed properties, `??=`, `match`,
  named arguments, `?->`, constructor promotion, enums, attributes (other than as comments).
- No functions removed or deprecated in PHP 8.x: `each`, `create_function`, `money_format`,
  `__autoload`, `$str{0}`, `utf8_encode/decode`, `strftime`; no PHP 8-only helpers such as
  `str_contains`.
- Always `===` / `!==`. Cast values read from the database or the request (`(int)`, `(string)`,
  `int_or_null()`, `str_or_empty()`): PHP 8.1 returns integers from MySQL where 7.2 returns strings.
- Never pass `null` to string functions; never add undeclared properties to objects.
- A typed parameter with a `null` default must be written `?Type $x = null` (`Type $x = null` is deprecated in PHP 8.4).
- Do not test GD / OpenSSL results with `is_resource()`; compare with `!== false`.
- `isset()` cannot be used on a class-constant array element in PHP 7.2; use `array_key_exists()`.
- Keywords are fine as method names (`Router::match`), never as global function or class names.
- Development and testing run with `E_ALL`, and every notice, warning or deprecation is thrown as an
  exception (`app/Core/ErrorHandler.php`). Production logs them instead.

## SQL

- Use the query builder (`DB::table(...)`) or bound parameters; never concatenate input into SQL.
- Only syntax shared by MariaDB 10.1 and MySQL 8: no JSON columns (use `LONGTEXT`), no CHECK, no CTE,
  no window functions, no sequences. `rank` and similar reserved words are not used as names.
- Indexed text columns are at most `VARCHAR(191)` in utf8mb4; codes and keys use `CHARACTER SET ascii`.
- Every query that shows data has an `ORDER BY`.
- Each connection fixes charset, PDO error mode and a strict `sql_mode` (`app/Database/DB.php`), so
  10.1 and 10.2+ behave the same. Validate length and type in the service before writing.

## Text and languages

- Every text shown to users is a key in `resources/languages/<code>/*.json` (PHP `__()`, JS `t()`),
  present in every language. Store codes, not words; translate when displaying.
- No browser-native date pickers or file buttons (their text follows the browser language); use
  `OMPS.dateInput()` / the `date` field type.
- `php omps i18n:check` must pass: same keys in every pack, every used key exists, no hard-coded Chinese.

## Security

- Permissions are checked on the server for every route (`perm` option) and again in services for
  data scope; hiding a button is never the protection. Out-of-scope reads answer 404 and are audited.
- All output in views goes through `e()`; pages build HTML in JS only with `OMPS.esc()`.
- No inline scripts (the Content-Security-Policy forbids them); page data goes in
  `<script type="application/json">`.
- State-changing requests need the CSRF token header (`OMPS.api()` sends it).

## Before every commit

```
php omps compat:check
php omps i18n:check
php omps test
```

Run them with PHP 7.2.3 and with PHP 8.x.
