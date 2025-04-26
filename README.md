# db-query
Robust database querying

This is a `PDO` wrapper with some potentially useful features:
- You can send both string (single query) and array (set of queries) and both will be processed. In case of array, it will automatically start a transaction and process each query separately. In case array will have any SELECT-like queries you will be notified, because their output may not get processed properly.
- Attempts to retry in case of deadlock. You can set number of retries and time to sleep before each try using appropriate setters.
- Binding sugar:
    - Instead of `\PDO::PARAM_*` or obscure integers you can send appropriate types as strings, like `'boolean'`
    - Enforced type casting: it's known, that sometimes PDO driver does not cast or casts weirdly. Some cases enforce regular casting in attempt to work around this
    - Limit casting: when sending binding for use with LIMIT you need to remember to cast it as integer, but with this controller - just mark it as `'limit'`
    - Like casting: when sending binding for use with LIKE you need to enclose it in `%` yourself, but with this controller - just mark it as `'like'`
    - Date and time casting: mark a binding as `'date'` or `'time'` and script will attempt to check if it's a datetime value and convert it to an appropriate string for you. Or even get current time, if you do not want to use engine's own functions
- Semantic wrappers: a set of functions for SELECT which clearly state, what they will be returning (row, column, everything)
- Smart result return: if you send a single query, script will attempt to identify what it is and do either `fetch()` or `fetchAll()` or `rowCount()` and setting appropriate result. Drawback is that you need to use `getResult()` afterwards.

## How to use

###### *Please, note, that I am using MySQL as main DB engine in my projects, thus I may miss some peculiarities of other engines. Please, let me know of them, so that they can be incorporated.*

To utilize `Controller` you need to establish connection using [DB-Pool](https://github.com/Simbiat/db-pool) library or by passing a `PDO` object to constructor, and then call either it `query()` function or any of the wrappers. For example, this line will count rows in a table and return only the number of those rows, that is an integer:

```php
(new \Simbiat\Database\Select($dbh))::count('SELECT COUNT(*) FROM `table`');
```

This one will return a boolean, advising if something exists in a table:

```php
(new \Simbiat\Database\Select($dbh))::check('SELECT * FROM `table` WHERE `time`=:value', [':value'=>['', 'time']]);
```

The above example also shows one of possible ways to set bindings. Regular `PDO` allows binding values like `hook, value, type`, but `Controller` expects an array for `value` if you want to send a non-string one or a special value, like the above mentioned `time`. Since We are sending an empty value for `time` `Controller` will take current microtime and convert and bind it in `Y-m-d H:i:s.u` format.