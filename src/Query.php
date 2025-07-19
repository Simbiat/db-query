<?php
declare(strict_types = 1);

namespace Simbiat\Database;

use JetBrains\PhpStorm\ExpectedValues;
use function count;
use function in_array;
use function is_string;

/**
 * Base class for various subclasses doing various database operations
 */
class Query
{
    /**
     * @var null|\PDO PDO object to run queries against
     */
    public static ?\PDO $dbh = null;
    /**
     * @var array List of functions that may return rows
     */
    public const array SELECTS = [
        'SELECT', 'SHOW', 'HANDLER', 'ANALYZE', 'CHECK', 'DESCRIBE', 'DESC', 'EXPLAIN', 'HELP', 'REPAIR', 'OPTIMIZE'
    ];
    /**
     * @var int Maximum time (in seconds) for the query (for `set_time_limit`)
     */
    public static int $max_run_time = 3600;
    /**
     * @var int Number of times to retry in case of deadlock
     */
    public static int $max_tries = 5;
    /**
     * @var int Time (in seconds) to wait between retries in case of deadlock
     */
    public static int $sleep = 5;
    /**
     * @var int Number of queries ran. Static for convenience, in case the object gets destroyed, but you still want to get the total number
     */
    public static int $queries = 0;
    /**
     * @var array Timing statistics for each query
     */
    public static array $timings = [];
    /**
     * @var bool Debug mode
     */
    public static bool $debug = false;
    /**
     * @var bool Whether transaction mode is to be used for the current run
     */
    public static bool $transaction = true;
    /**
     * @var mixed Result of the last query
     */
    public static mixed $last_result = null;
    /**
     * @var int Number of last affected rows (inserted, deleted, updated)
     */
    public static int $last_affected = 0;
    /**
     * @var null|string|false ID of the last INSERT
     */
    public static null|string|false $last_id = null;
    /**
     * Internal variable to store \PDOStatement
     * @var \PDOStatement|null
     */
    private static ?\PDOStatement $sql = null;
    /**
     * Stores current key that represents the current query ID
     * @var string|int|null
     */
    private static string|int|null $current_key = null;
    /**
     * Holds bindings for the current query
     * @var array|null
     */
    private static ?array $current_bindings = null;
    /**
     * Flag indicating a deadlock
     * @var bool
     */
    private static bool $deadlock = false;
    /**
     * Flag indicating that we have a single `SELECT` query
     * @var bool
     */
    private static bool $single_select = false;
    /**
     * Supported return flavors
     * @var array
     */
    private const array FLAVORS = ['bool', 'increment', 'affected', 'all', 'column', 'row', 'value', 'pair', 'unique', 'count', 'check'];
    
    /**
     * @param \PDO|null $dbh          PDO object to use for database connection. If not provided, the class expects the existence of `\Simbiat\Database\Pool` to use that instead.
     * @param int|null  $max_run_time Maximum time (in seconds) for the query (for `set_time_limit`)
     * @param int|null  $max_tries    Number of times to retry in case of deadlock
     * @param int|null  $sleep        Time (in seconds) to wait between retries in case of deadlock
     * @param bool      $transaction  Flag whether to use `TRANSACTION` mode. `true` by default.
     * @param bool      $debug        Debug mode
     */
    public function __construct(?\PDO $dbh = null, ?int $max_run_time = null, ?int $max_tries = null, ?int $sleep = null, bool $transaction = true, bool $debug = false)
    {
        if ($dbh === null) {
            if (method_exists(Pool::class, 'openConnection')) {
                self::$dbh = Pool::openConnection();
                if (self::$dbh === null) {
                    throw new \RuntimeException('Pool class loaded but no connection was returned and no PDO object provided.');
                }
            } else {
                throw new \RuntimeException('Pool class not loaded and no PDO object provided.');
            }
        } else {
            self::$dbh = $dbh;
        }
        #Update settings. All of them except for Debug Mode should change only if we explicitly pass new values. Debug mode should be reset on every call
        if ($max_run_time !== null) {
            if ($max_run_time < 1) {
                $max_run_time = 1;
            }
            self::$max_run_time = $max_run_time;
        }
        if ($max_tries !== null) {
            if ($max_tries < 1) {
                $max_tries = 1;
            }
            self::$max_tries = $max_tries;
        }
        if ($sleep !== null) {
            if ($sleep < 1) {
                $sleep = 1;
            }
            self::$sleep = $sleep;
        }
        self::$transaction = $transaction;
        self::$debug = $debug;
    }
    
    /**
     * Run SQL query
     *
     * @param string|array                    $queries          Query/queries to run.
     * @param array                           $bindings         Global bindings that need to be applied to all queries.
     * @param int                             $fetch_mode       `FETCH` mode used by `SELECT` queries.
     * @param int|string|object|null|callable $fetch_argument   Optional argument for various `FETCH` modes.
     * @param array                           $constructor_args `ConstructorArgs` for `fetchAll` PDO function. Used only for `\PDO::FETCH_CLASS` mode.
     * @param string                          $return           Hint to change the type of return on success. The default is `bool`, refer documentation for other values.
     *
     * @return mixed
     */
    public static function query(string|array $queries, array $bindings = [], int $fetch_mode = \PDO::FETCH_ASSOC, int|string|object|null|callable $fetch_argument = null, array $constructor_args = [], #[ExpectedValues(self::FLAVORS)] string $return = 'bool'): mixed
    {
        if (!in_array($return, self::FLAVORS, true)) {
            throw new \UnexpectedValueException('Return flavor `'.$return.'` provided to `query()` function but it is not supported.');
        }
        if (in_array($return, ['column', 'value', 'count'], true)) {
            if (is_int($fetch_argument) || $fetch_argument === null) {
                $fetch_mode = \PDO::FETCH_COLUMN;
            } else {
                throw new \UnexpectedValueException('Return flavor `'.$return.'` provided to `query()` function but `$fetch_argument` is not an integer.');
            }
        }
        if ($return === 'pair') {
            $fetch_mode = \PDO::FETCH_KEY_PAIR;
        }
        if ($return === 'unique') {
            $fetch_mode = \PDO::FETCH_UNIQUE;
        }
        self::preprocess($queries, $bindings, $return);
        #Set counter for tries
        $try = 0;
        do {
            $try++;
            try {
                self::execute($queries, $fetch_mode, $fetch_argument, $constructor_args);
            } catch (\Throwable $exception) {
                $error_message = $exception->getMessage().$exception->getTraceAsString();
                self::except($queries, $error_message, $exception);
                #If deadlock - sleep and then retry
                if (self::$deadlock) {
                    sleep(self::$sleep);
                    continue;
                }
                throw new \RuntimeException($error_message, 0, $exception);
            }
            if ($return === 'increment') {
                return self::$last_id;
            }
            if ($return === 'affected') {
                return self::$last_affected;
            }
            if (in_array($return, ['all', 'column', 'pair', 'unique'])) {
                return self::$last_result;
            }
            if ($return === 'row') {
                return self::$last_result[0] ?? [];
            }
            if ($return === 'value') {
                return (self::$last_result[0] ?? null);
            }
            if ($return === 'count') {
                return (int)(self::$last_result[0] ?? null);
            }
            if ($return === 'check') {
                return !empty(self::$last_result);
            }
            return true;
        } while ($try <= self::$max_tries);
        throw new \RuntimeException('Deadlock encountered for set maximum of '.self::$max_tries.' tries.');
    }
    
    /**
     * Helper to do some preparations of the queries and bindings
     *
     * @param string|array $queries
     * @param array        $bindings
     * @param string       $return
     *
     * @return void
     */
    private static function preprocess(string|array &$queries, array $bindings, string $return): void
    {
        #Check if a query string was sent
        if (is_string($queries)) {
            if (preg_match('/^\s*$/', $queries) === 1) {
                throw new \UnexpectedValueException('Query is an empty string.');
            }
            #Split the string to an array of queries (in case multiple was sent as 1 string)
            $queries = self::stringToQueries($queries);
        }
        #Ensure integer keys
        $queries = array_values($queries);
        #Iterrate over array to merge binding
        foreach ($queries as $key => $array_to_process) {
            #Ensure integer keys
            if (is_array($array_to_process)) {
                $queries[$key] = [0 => $array_to_process['query'] ?? $array_to_process[0] ?? null, 1 => $array_to_process['bindings'] ?? $array_to_process[1] ?? []];
            } else {
                $queries[$key] = [0 => $array_to_process, 1 => []];
            }
            $queries[$key] = array_values(is_array($array_to_process) ? $array_to_process : [0 => $array_to_process, 1 => []]);
            #Check if the query is a string
            if (!is_string($queries[$key][0]) || preg_match('/^\s*$/', $queries[$key][0]) === 1) {
                #Exit earlier for speed
                throw new \UnexpectedValueException('Query #'.$key.' is not a valid string.');
            }
            #Merge bindings. Suppressing inspection, since we always have an array due to explicit conversion on a previous step
            if (empty($queries[$key][1])) {
                /** @noinspection UnsupportedStringOffsetOperationsInspection */
                $queries[$key][1] = $bindings;
            } else {
                /** @noinspection UnsupportedStringOffsetOperationsInspection */
                $queries[$key][1] += $bindings;
            }
        }
        #Remove any SELECT queries and comments if more than 1 query is sent
        if (count($queries) > 1) {
            foreach ($queries as $key => $array_to_process) {
                #Check if the query is `SELECT` or a comment
                if (self::isSelect($array_to_process[0], false) || preg_match('/^\s*(--|#|\/\*).*$/', $array_to_process[0]) === 1) {
                    unset($queries[$key]);
                }
            }
        }
        #Check if the array of queries is empty
        if (empty($queries)) {
            throw new \UnexpectedValueException('No queries were provided to `query()` function or all of them were identified as SELECT-like statements.');
        }
        self::flavorCheck($queries, $return);
        #Reset lastID
        self::$last_id = null;
        #Reset the number of affected rows and reset it before run
        self::$last_affected = 0;
    }
    
    /**
     * Helper to validate return flavor is supported by the current query or list of queries
     * @param array  $queries
     * @param string $return
     *
     * @return void
     */
    private static function flavorCheck(array &$queries, string $return): void
    {
        #Flag for SELECT, used as a sort of "cache" instead of counting values every time
        self::$single_select = false;
        #If we have just 1 query, which is a `SELECT` - disable transaction
        if ((count($queries) === 1)) {
            if (self::isSelect($queries[0][0], false)) {
                self::$single_select = true;
                self::$transaction = false;
                #Add `LIMIT 1` to the query if it's not already there to help reduce the use of resources.
                if ($return === 'row' && preg_match('/\s*LIMIT\s+(\d+\s*,\s*)?\d+\s*;?\s*$/ui', $queries[0][0]) !== 1) {
                    #EA thinks the variable can be a string, but it will never be one at this point.
                    /** @noinspection UnsupportedStringOffsetOperationsInspection */
                    $queries[0][0] = preg_replace(['/(;?\s*\z)/mui', '/\z/mui'], ['', ' LIMIT 0, 1;'], $queries[0][0]);
                }
            } else {
                if (!in_array($return, ['increment', 'bool', 'affected'])) {
                    throw new \UnexpectedValueException('Return flavor `'.$return.'` provided to `query()` function but the query is not a `SELECT`.');
                }
                if ($return === 'increment' && !self::isInsert($queries[0][0], false)) {
                    throw new \UnexpectedValueException('Return flavor `'.$return.'` provided to `query()` function but the query is not an `INSERT`.');
                }
            }
        } elseif ($return !== 'bool' && $return !== 'affected') {
            throw new \UnexpectedValueException('Return flavor `'.$return.'` provided to `query()` function but there are multiple queries provided.');
        }
    }
    
    /**
     * Helper to handle exceptions
     *
     * @param array      $queries
     * @param string     $error_message
     * @param \Throwable $exception
     *
     * @return void
     */
    private static function except(array $queries, string $error_message, \Throwable $exception): void
    {
        if (isset(self::$sql) && self::$debug) {
            self::$sql->debugDumpParams();
            echo $error_message;
            ob_flush();
            flush();
        }
        #Check if it's a deadlock. Unbuffered queries are not deadlock, but practice showed that in some cases this error is thrown when there is a lock on resources, and not really an issue with (un)buffered queries. Retrying may help in those cases.
        if (isset(self::$sql) && (self::$sql->errorCode() === '40001' || preg_match('/(deadlock|try restarting transaction|Cannot execute queries while other unbuffered queries are active)/mi', $error_message) === 1)) {
            self::$deadlock = true;
        } else {
            self::$deadlock = false;
            #Set error message
            if (isset(self::$current_key)) {
                try {
                    $error_message = 'Failed to run query `'.$queries[self::$current_key][0].'`'.(!empty(self::$current_bindings) ? ' with following bindings: '.json_encode(self::$current_bindings, JSON_THROW_ON_ERROR) : '');
                } catch (\JsonException) {
                    $error_message = 'Failed to run query `'.$queries[self::$current_key][0].'`'.(!empty(self::$current_bindings) ? ' with following bindings: `Failed to JSON Encode bindings`' : '');
                }
            } else {
                $error_message = 'Failed to start or end transaction';
            }
        }
        if (isset(self::$sql)) {
            #Ensure the pointer is closed
            try {
                self::$sql->closeCursor();
            } catch (\Throwable) {
                #Do nothing, most likely fails due to non-existent cursor.
            }
        }
        if (self::$dbh->inTransaction()) {
            self::$dbh->rollBack();
            if (!self::$deadlock) {
                throw new \RuntimeException($error_message, 0, $exception);
            }
        }
    }
    
    /**
     * Helper that actually executed the queries
     *
     * @param array                           $queries
     * @param int                             $fetch_mode
     * @param int|string|object|null|callable $fetch_argument
     * @param array                           $constructor_arguments
     *
     * @return void
     *
     */
    private static function execute(array &$queries, int $fetch_mode = \PDO::FETCH_ASSOC, int|string|object|null|callable $fetch_argument = NULL, array $constructor_arguments = []): void
    {
        #Initiate transaction if we are using it
        if (self::$transaction) {
            self::$dbh->beginTransaction();
        }
        #Loop through queries
        foreach ($queries as $key => $query) {
            #Reset variables
            self::$sql = null;
            self::$current_bindings = null;
            self::$current_key = $key;
            #Prepare bindings if any
            if (!empty($query[1])) {
                self::$current_bindings = $query[1];
                Bind::unpackIN($query[0], self::$current_bindings);
            }
            #Prepare the query
            if (self::$dbh->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
                #Force the buffered query for MySQL
                self::$sql = self::$dbh->prepare($query[0], [\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]);
            } else {
                self::$sql = self::$dbh->prepare($query[0]);
            }
            #Bind values, if any
            if (!empty($query[1])) {
                Bind::bindMultiple(self::$sql, self::$current_bindings);
            }
            #Increasing time limit for potentially long operations (like `OPTIMIZE`)
            set_time_limit(self::$max_run_time);
            #Increase the number of queries
            self::$queries++;
            #Execute the query
            $start = hrtime(true);
            self::$sql->execute();
            #Register statistics
            $time = hrtime(true) - $start;
            #Check if this query has been registered already
            $query_to_register = array_search($query[0], array_column(self::$timings, 'query'), true);
            if ($query_to_register === false) {
                #Not registered yet, so add it
                self::$timings[] = [
                    'query' => $query[0],
                    'time' => [$time],
                ];
            } else {
                #Registered, so add to the list of times
                self::$timings[$query_to_register]['time'][] = $time;
            }
            #If debug is enabled dump PDO details
            if (self::$debug) {
                self::$sql->debugDumpParams();
                ob_flush();
                flush();
            }
            /** @noinspection DisconnectedForeachInstructionInspection */
            if (self::$single_select) {
                #Adjust fetching mode
                if (in_array($fetch_mode, [\PDO::FETCH_COLUMN, \PDO::FETCH_FUNC, \PDO::FETCH_INTO, \PDO::FETCH_FUNC, \PDO::FETCH_SERIALIZE], true)) {
                    self::$last_result = self::$sql->fetchAll($fetch_mode, $fetch_argument);
                } elseif (in_array($fetch_mode, [\PDO::FETCH_CLASS, \PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE], true)) {
                    self::$last_result = self::$sql->fetchAll($fetch_mode, $fetch_argument, $constructor_arguments);
                } else {
                    self::$last_result = self::$sql->fetchAll($fetch_mode);
                }
            } else {
                #Increase the counter of affected rows (inserted, deleted, updated)
                self::$last_affected += self::$sql->rowCount();
            }
            #Explicitely close pointer to release resources
            self::$sql->closeCursor();
            #Remove the query from the bulk, if not using transaction mode, to avoid repeating of commands
            if (!self::$transaction) {
                unset($queries[$key]);
            }
        }
        #Try to get the last ID (if we had any inserts with auto increment
        try {
            self::$last_id = self::$dbh->lastInsertId();
        } catch (\Throwable) {
            #Either the function is not supported by the driver or it requires a sequence name.
            #Since this class is meant to be universal, I do not see a good way to support sequence name at the time of writing.
            self::$last_id = false;
        }
        #Initiate a transaction if we are using it
        if (self::$transaction && self::$dbh->inTransaction()) {
            self::$dbh->commit();
        }
    }
    
    /**
     * Helper function to check if a query is a select(able) one
     * @param string $query Query to check
     * @param bool   $throw Throw exception if not `SELECT` and this option is `true`.
     *
     * @return bool
     */
    public static function isSelect(string $query, bool $throw = true): bool
    {
        #First, check that the whole text does not start with any of SELECT-like statements or with `WITH` (CTE)
        if (preg_match('/\A\s*WITH/mui', $query) !== 1
            && preg_match('/\A\s*('.implode('|', self::SELECTS).')/mui', $query) !== 1
            && preg_match('/^\s*(\(\s*)*('.implode('|', self::SELECTS).')/mui', $query) !== 1
        ) {
            if ($throw) {
                throw new \UnexpectedValueException('Query is not one of '.implode(', ', self::SELECTS).'.');
            }
            return false;
        }
        return true;
    }
    
    /**
     * @param string $query
     * @param bool   $throw
     *
     * @return bool
     */
    public static function isInsert(string $query, bool $throw = true): bool
    {
        if (\preg_match('/^\s*INSERT\s+INTO/ui', $query) === 1) {
            return true;
        }
        if ($throw) {
            throw new \UnexpectedValueException('Query is not INSERT.');
        }
        return false;
    }
    
    /**
     * Helper function to allow splitting a string into an array of queries. May not work as expected with complex queries or certain string literals.
     * Regexp was taken from https://stackoverflow.com/questions/24423260/split-sql-statements-in-php-on-semicolons-but-not-inside-quotes and adjusted to handle `;` inside quotes.
     *
     * @param string $string
     *
     * @return array
     */
    public static function stringToQueries(string $string): array
    {
        $queries = \preg_split('/((["\'])(?:\.|(?!\2).)*+\2|\([^()]*\))(*SKIP)(*FAIL)|(?<=;)(?! *$)/u', $string);
        $filtered = [];
        foreach ($queries as $query) {
            #Trim first
            $query = \preg_replace('/^(\s*)(.*)(\s*)$/u', '$2', $query);
            #Skip empty lines (can happen if there are empty ones before and after a query
            if (\preg_match('/^\s*$/', $query) === 0) {
                $filtered[] = $query;
            }
        }
        return $filtered;
    }
}