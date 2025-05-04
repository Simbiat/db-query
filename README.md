# DB Query

This is a robust `PDO` wrapper with some potentially useful features:
- You can send both string (single query) and array (set of queries), and both will be processed. In case an array has any SELECT-like queries, you will be notified because their output may not get processed properly.
- Attempts to retry in case of deadlock. You can set the number of retries and time to sleep before each try using appropriate settings.
- Binding sugar using [DB-Binder](https://github.com/Simbiat/db-binder).
- Return "flavors" that allow you to get various types of returns directly instead of writing another call.
- Statistics for all queries ran through the class 

## How to use

###### *Please note that I am using MySQL as the main DB engine in my projects, thus I may miss some peculiarities of other engines. Please let me know of them, so that they can be incorporated.*

### General use

#### Before you query

This is a static class, so technically does not _require_ initiation, but you may want to do that regardless before your first query, to update settings and establish connection.

```php
new \Simbiat\Database\Query(?\PDO $dbh = null, ?int $maxRunTime = null, ?int $maxTries = null, ?int $sleep = null, bool $transaction = true, bool $debug = false);
```

- `$dbh` - `PDO` object to use for database connection. If not provided, the class expects the existence of `\Simbiat\Database\Pool` ([DB-Pool](https://github.com/Simbiat/db-pool)) to use that instead. If `DB-Pool` is not used it's enough to provide the object once, as it will persist in all following calls if `null` is passed to constructor.
- `$maxRunTime` - Maximum time (in seconds) for the query (for `set_time_limit`). Will persist in next calls, if `null` is passed to constructor.
- `$maxTries` - Number of times to retry in case of a deadlock. Will persist in next calls, if `null` is passed to constructor.
- `$sleep` - Time (in seconds) to wait between retries in a case of deadlock. Will persist in next calls, if `null` is passed to constructor.
- `$transaction` - Flag whether to use `TRANSACTION` mode. `true` by default. Will be set to `false` if single `SELECT` is sent. Resets to `true` on every following call.
- `$debug` - Debug mode. In case of errors will output some extra details to help debug what went wrong. Resets to `false` on every following call.

If required, you can change them directly like

```php
\Simbiat\Database\Query::$sleep = 10;
```

since all the settings are public static ones.

#### Running a query

To run a query, use one of the below commands depending on whether you need to change some setting (`dbh` in the example) or not:

```php
new \Simbiat\Database\Query($dbh)::query(string|array $queries, array $bindings = [], int $fetch_mode = \PDO::FETCH_ASSOC, int|string|object|null|callable $fetch_argument = null, array $constructorArgs = [], #[ExpectedValues(self::flavors)] string $return = 'bool');

\Simbiat\Database\Query::query(string|array $queries, array $bindings = [], int $fetch_mode = \PDO::FETCH_ASSOC, int|string|object|null|callable $fetch_argument = null, array $constructorArgs = [], #[ExpectedValues(self::flavors)] string $return = 'bool');
```

- `$queries` - Query or queries to run. Either a string or an array. String will be split, so you can send multiple queries in one go, but for such a use case an array is advisable. The array can be sent either as `[0 => 'query1', 1 => 'query2']` or `[0 => [0 => 'query1', 1 => $bindings1], 1 => [0 => 'query2', 1 => $bindings2]]` where `$binding1` and `$binding2` are optional per query bindings as per [DB-Binder's](https://github.com/Simbiat/db-binder) logic. Note that in the case of per-query bindings, the query needs to always be the first element, and the bindings array — the second one. Alternatively, use an associative array with `query` and `bindings` keys respectively.
- `$bindings` - Global bindings that need to be applied to all queries as per [DB-Binder's](https://github.com/Simbiat/db-binder) logic. Note, that for merging of the arrays `+` operator is used instead of `array_merge`, and global bindings are added to "local" ones, which means that in case of duplicate keys the "local" ones will take precedence.
- `$fetch_mode` - `FETCH` mode used by `SELECT` queries. Needs to be respective `\PDO::FETCH_*` variable.
- `$fetch_argument` - Optional argument for various `FETCH` modes, like column number for `\PDO::FETCH_COLUMN`, callable for `\PDO::FETCH_FUNC`.
- `$constructorArgs` - `ConstructorArgs` for `fetchAll` PDO function. Used only for `\PDO::FETCH_CLASS` mode.
- `$return` - Hint to change the type ("flavor") of return on success. The default is `bool`, refer below.

### Flavors

- `bool` - Default flavor. Will return `true` on success. In case of an error, an exception will be thrown. Works with any type of query and any number of them.
- `all` - Returns results of a `SELECT` as array (or whatever the return type is for respective fetch mode). Works only with single `SELECT` queries.
- `row` - Same as `all`, but enforces `LIMIT 1` (if no `LIMIT` is already set) and returns only the first row. Works only with single `SELECT` queries.
- `pair` - Enforces `\PDO::FETCH_KEY_PAIR` and returns a respective key-pair set as an array. Works only with single `SELECT` queries.
- `column` - Enforces `\PDO::FETCH_COLUMN` and returns respective column as an array. Requires `$fetch_argument` to be an integer, but if none is provided (`null`) will return first column. Works only with single `SELECT` queries.
- `unique` - Enforces `\PDO::FETCH_UNIQUE` and returns unique results only as an array. Works only with single `SELECT` queries.
- `value` Same as `column`, but will return only the first value from the column, even if there were multiple results. Useful if you need only value of on field. Works only with single `SELECT` queries.
- `count` - Same as `value`, but will explicitly convert the result to integer. Using with `COUNT()` is recommended, but technically can be any column.  Works only with single `SELECT` queries.
- `check` - Returns `true` if something was selected. Anything at all, as long as the result _set_ is not empty. Works only with single `SELECT` queries.
- `increment` - Works only with single `INSERT` queries and expects that the table will have an `AUTO_INCREMENT` column.
- `affected` - Returns number of affected rows. Works with any type of query, but `INSERT`, `UPDATE` and `DELETE` would make the most sense here. Any number of queries can be used, the number will be the sum of rows affected by each one of them.

If required, you can access the results of queries separately using respective public static properties:
- `$lastResult` - results of last `SELECT`.
- `$lastAffected` - number of rows affected by the last set of queries.
- `$lastId` - ID of last inserted row when dealing with `AUTO_INCREMENT`.

### Useful functions

The class also has a few helpers that may be useful separately.

#### isSelect

```php
\Simbiat\Database\Query::isSelect(string $query, bool $throw = true);
```

Checks if a provided query is one of `SELECT`, `SHOW`, `HANDLER`, `ANALYZE`, `CHECK`, `DESCRIBE`, `DESC`, `EXPLAIN` or `HELP`. Handles `WITH` and CTEs, as well. Can throw an exception, if `$throw` is `true`.

#### isInsert

```php
\Simbiat\Database\Query::isInsert(string $query, bool $throw = true);
```

Checks if a provided query is an `INSERT`. Can throw an exception, if `$throw` is `true`.

#### stringToQueries

```php
\Simbiat\Database\Query::stringToQueries(string $string);
```

Splits a string into an array of queries. Uses regexp from [StackOverflow](https://stackoverflow.com/questions/24423260/split-sql-statements-in-php-on-semicolons-but-not-inside-quotes).

### Statistics

The class also collects some statistics that can be accessed at any time. The simplest one is

```php
\Simbiat\Database\Query::$queries
```

Which will be the number of queries ran so far. Every query from arrays will be counted.  
The other one is

```php
\Simbiat\Database\Query::$timings
```

which will return an array like this:

```php
[
    0 => [
        'query' => 'query1',
        'time' => [
            0 => 1,
            1 => 2,
        ],
    ],
    1 => [
        'query' => 'query2',
        'time' => [
            0 => 1,
            1 => 2,
        ],
    ],
]
```

This array will contain execution timings of each time a query was run, grouped by query (before binding, of course).