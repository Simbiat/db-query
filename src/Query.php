<?php
declare(strict_types = 1);

namespace Simbiat\Database;

use function count;
use function in_array;
use function is_string;

/**
 * Base class for various subclasses doing various database operations
 */
abstract class Query
{
    /**
     * @var mixed Result of the last query
     */
    public static mixed $lastResult = null;
    /**
     * @var int Number of last affected rows (inserted, deleted, updated)
     */
    public static int $lastAffected = 0;
    /**
     * @var null|string|false ID of the last INSERT
     */
    public static null|string|false $lastId = null;
    
    /**
     * @param \PDO|null $dbh        PDO object to use for database connection. If not provided, the class expects the existence of `\Simbiat\Database\Pool` to use that instead.
     * @param int|null  $maxRunTime Maximum time (in seconds) for the query (for `set_time_limit`)
     * @param int|null  $maxTries   Number of times to retry in case of deadlock
     * @param int|null  $sleep      Time (in seconds) to wait between retries in case of deadlock
     * @param bool      $debug      Debug mode
     */
    public function __construct(?\PDO $dbh = null, ?int $maxRunTime = null, ?int $maxTries = null, ?int $sleep = null, bool $debug = false)
    {
        Common::setDbh($dbh);
        #Update settings. All of them except for Debug Mode should change only if we explicitly pass new values. Debug mode should be reset on every call
        if ($maxRunTime !== null) {
            Common::setMaxRunTime($maxRunTime);
        }
        if ($maxTries !== null) {
            Common::setMaxTries($maxTries);
        }
        if ($sleep !== null) {
            Common::setSleep($sleep);
        }
        Common::setDebug($debug);
    }
    
    /**
     * Run SQL query
     *
     * @param string|array $queries        - query/queries to run
     * @param array        $bindings       - global bindings that need to be applied to all queries
     * @param int          $fetch_style    - FETCH type used by SELECT queries. Applicable only if 1 query is sent
     * @param mixed|null   $fetch_argument - fetch mode for PDO
     * @param array        $ctor_args      - constructorArgs for fetchAll PDO function
     * @param bool         $transaction    - flag whether to use TRANSACTION mode. TRUE by default to allow more consistency
     *
     * @return bool
     */
    public static function query(string|array $queries, array $bindings = [], int $fetch_style = \PDO::FETCH_ASSOC, int|string|object|null $fetch_argument = NULL, array $ctor_args = [], bool $transaction = true): bool
    {
        #Reset lastID
        self::$lastId = null;
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
        foreach ($queries as $key => $query) {
            #Ensure integer keys
            if (is_string($query)) {
                $query = [0 => $query, 1 => []];
            }
            $queries[$key] = array_values($query);
            #Check if the query is a string
            if (!is_string($queries[$key][0])) {
                #Exit earlier for speed
                throw new \UnexpectedValueException('Query #'.$key.' is not a string.');
            }
            if (preg_match('/^\s*$/', $queries[$key][0]) === 1) {
                throw new \UnexpectedValueException('Query #'.$key.' is an empty string.');
            }
            #Merge bindings. Suppressing inspection, since we always have an array due to explicit conversion on a previous step
            /** @noinspection UnsupportedStringOffsetOperationsInspection */
            $queries[$key][1] = array_merge($queries[$key][1] ?? [], $bindings);
        }
        #Remove any SELECT queries and comments if more than 1 query is sent
        if (count($queries) > 1) {
            foreach ($queries as $key => $query) {
                #Check if the query is SELECT
                if (self::isSelect($query[0], false)) {
                    unset($queries[$key]);
                    continue;
                }
                #Check if the query is a comment
                if (preg_match('/^\s*(--|#|\/\*).*$/', $query[0]) === 1) {
                    unset($queries[$key]);
                }
            }
        }
        #Check if the array of queries is empty
        if (empty($queries)) {
            throw new \UnexpectedValueException('No queries were provided to `query()` function or all of them were identified as SELECT-like statements.');
        }
        #Flag for SELECT, used as a sort of "cache" instead of counting values every time
        $select = false;
        #If we have just 1 query, which is a `SELECT` - disable transaction
        if ((count($queries) === 1) && self::isSelect($queries[0][0], false)) {
            $select = true;
            $transaction = false;
        }
        #Reset the number of affected rows and reset it before run
        self::$lastAffected = 0;
        #Set counter for tries
        $try = 0;
        do {
            #Suppressing because we want to standardize error handling for this part
            /** @noinspection BadExceptionsProcessingInspection */
            try {
                #Indicate actual try
                $try++;
                #Initiate transaction if we are using it
                if ($transaction) {
                    Common::$dbh->beginTransaction();
                }
                #Loop through queries
                foreach ($queries as $key => $query) {
                    #Reset variables
                    $sql = null;
                    $currentBindings = null;
                    $currentKey = $key;
                    #Prepare bindings if any
                    if (!empty($query[1])) {
                        $currentBindings = $query[1];
                        Bind::unpackIN($query[0], $currentBindings);
                    }
                    #Prepare the query
                    if (Common::$dbh->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
                        #Force the buffered query for MySQL
                        $sql = Common::$dbh->prepare($query[0], [\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]);
                    } else {
                        $sql = Common::$dbh->prepare($query[0]);
                    }
                    #Bind values, if any
                    if (!empty($query[1])) {
                        Bind::bindMultiple($sql, $currentBindings);
                    }
                    #Increasing time limit for potentially long operations (like optimize)
                    set_time_limit(Common::$maxRunTime);
                    #Increase the number of queries
                    Common::$queries++;
                    #Execute the query
                    $start = hrtime(true);
                    $sql->execute();
                    Common::addTiming($query[0], hrtime(true) - $start);
                    #If debug is enabled dump PDO details
                    if (Common::$debug) {
                        $sql->debugDumpParams();
                        ob_flush();
                        flush();
                    }
                    if ($select) {
                        #Adjust fetching mode
                        if ($fetch_argument === 'row') {
                            self::$lastResult = $sql->fetchAll($fetch_style);
                            if (isset(self::$lastResult[0])) {
                                self::$lastResult = self::$lastResult[0];
                            }
                        } elseif (in_array($fetch_style, [\PDO::FETCH_COLUMN, \PDO::FETCH_FUNC, \PDO::FETCH_INTO], true)) {
                            self::$lastResult = $sql->fetchAll($fetch_style, $fetch_argument);
                        } elseif ($fetch_style === \PDO::FETCH_CLASS) {
                            self::$lastResult = $sql->fetchAll($fetch_style, $fetch_argument, $ctor_args);
                        } else {
                            self::$lastResult = $sql->fetchAll($fetch_style);
                        }
                    } else {
                        #Increase the counter of affected rows (inserted, deleted, updated)
                        self::$lastAffected += $sql->rowCount();
                    }
                    #Explicitely close pointer to release resources
                    $sql->closeCursor();
                    #Remove the query from the bulk, if not using transaction mode, to avoid repeating of commands
                    #Not sure why PHP Storm complains about this line
                    /** @noinspection PhpConditionAlreadyCheckedInspection */
                    if (!$transaction) {
                        unset($queries[$key]);
                    }
                }
                #Try to get the last ID (if we had any inserts with auto increment
                try {
                    self::$lastId = Common::$dbh->lastInsertId();
                } catch (\Throwable) {
                    #Either the function is not supported by the driver or it requires a sequence name.
                    #Since this class is meant to be universal, I do not see a good way to support sequence name at the time of writing.
                    self::$lastId = false;
                }
                #Initiate a transaction if we are using it
                if ($transaction && Common::$dbh->inTransaction()) {
                    Common::$dbh->commit();
                }
                return true;
            } catch (\Throwable $e) {
                $errMessage = $e->getMessage().$e->getTraceAsString();
                #We can get here without `$sql` being set when transaction initialization fails
                /** @noinspection PhpConditionAlreadyCheckedInspection */
                if (isset($sql) && Common::$debug) {
                    $sql->debugDumpParams();
                    echo $errMessage;
                    ob_flush();
                    flush();
                }
                #Check if it's a deadlock. Unbuffered queries are not deadlock, but practice showed that in some cases this error is thrown when there is a lock on resources, and not really an issue with (un)buffered queries. Retrying may help in those cases.
                #We can get here without `$sql` being set when transaction initiation fails
                /** @noinspection PhpConditionAlreadyCheckedInspection */
                if (isset($sql) && ($sql->errorCode() === '40001' || preg_match('/(deadlock|try restarting transaction|Cannot execute queries while other unbuffered queries are active)/mi', $errMessage) === 1)) {
                    $deadlock = true;
                } else {
                    $deadlock = false;
                    #Set error message
                    if (isset($currentKey)) {
                        try {
                            $errMessage = 'Failed to run query `'.$queries[$currentKey][0].'`'.(!empty($currentBindings) ? ' with following bindings: '.json_encode($currentBindings, JSON_THROW_ON_ERROR) : '');
                        } catch (\JsonException) {
                            $errMessage = 'Failed to run query `'.$queries[$currentKey][0].'`'.(!empty($currentBindings) ? ' with following bindings: `Failed to JSON Encode bindings`' : '');
                        }
                    } else {
                        $errMessage = 'Failed to start or end transaction';
                    }
                }
                #We can get here without `$sql` being set when transaction initialization fails
                /** @noinspection PhpConditionAlreadyCheckedInspection */
                if (isset($sql)) {
                    #Ensure the pointer is closed
                    try {
                        $sql->closeCursor();
                    } catch (\Throwable) {
                        #Do nothing, most likely fails due to non-existent cursor.
                    }
                }
                if (Common::$dbh->inTransaction()) {
                    Common::$dbh->rollBack();
                    if (!$deadlock) {
                        throw new \RuntimeException($errMessage, 0, $e);
                    }
                }
                #If deadlock - sleep and then retry
                if ($deadlock) {
                    sleep(Common::$sleep);
                    continue;
                }
                throw new \RuntimeException($errMessage, 0, $e);
            }
        } while ($try <= Common::$maxTries);
        throw new \RuntimeException('Deadlock encountered for set maximum of '.Common::$maxTries.' tries.');
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
            && preg_match('/\A\s*('.implode('|', Common::selects).')/mui', $query) !== 1
            && preg_match('/^\s*(\(\s*)*('.implode('|', Common::selects).')/mui', $query) !== 1
        ) {
            if ($throw) {
                throw new \UnexpectedValueException('Query is not one of '.implode(', ', Common::selects).'.');
            }
            return false;
        }
        return true;
    }
    
    /**
     * Helper function to allow splitting a string into an array of queries. Made public because it may be useful outside this class' functions.
     * Regexp was taken from https://stackoverflow.com/questions/24423260/split-sql-statements-in-php-on-semicolons-but-not-inside-quotes
     *
     * @param string $string
     *
     * @return array
     */
    public static function stringToQueries(string $string): array
    {
        $queries = preg_split('~\([^)]*\)(*SKIP)(*FAIL)|(?<=;)(?! *$)~', $string);
        $filtered = [];
        foreach ($queries as $query) {
            #Trim first
            $query = preg_replace('/^(\s*)(.*)(\s*)$/u', '$2', $query);
            #Skip empty lines (can happen if there are empty ones before and after a query
            if (preg_match('/^\s*$/', $query) === 0) {
                $filtered[] = $query;
            }
        }
        return $filtered;
    }
}