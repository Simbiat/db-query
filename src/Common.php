<?php
declare(strict_types = 1);

namespace Simbiat\Database;

/**
 * Class storing common things for other classes SQLs
 */
class Common
{
    /**
     * @var array List of functions that may return rows
     */
    public const array selects = [
        'SELECT', 'SHOW', 'HANDLER', 'ANALYZE', 'CHECK', 'DESCRIBE', 'DESC', 'EXPLAIN', 'HELP'
    ];
    /**
     * @var null|\PDO PDO object to run queries against
     */
    public static ?\PDO $dbh = null;
    /**
     * @var bool Debug mode
     */
    public static bool $debug = false;
    /**
     * @var int Maximum time (in seconds) for the query (for `set_time_limit`)
     */
    public static int $maxRunTime = 3600;
    /**
     * @var int Number of times to retry in case of deadlock
     */
    public static int $maxTries = 5;
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
     * Add timing statistics
     *
     * @param string    $query Query to register
     * @param float|int $time  Time spent on the last query run
     *
     * @return void
     */
    public static function addTiming(string $query, float|int $time): void
    {
        #Check if this query has been registered already
        $key = array_search($query, array_column(self::$timings, 'query'), true);
        if ($key === false) {
            #Not registered yet, so add it
            self::$timings[] = [
                'query' => $query,
                'time' => [$time],
            ];
        } else {
            #Registered, so add to the list of times
            self::$timings[$key]['time'][] = $time;
        }
    }
    
    /**
     * Set the PDO object to run queries against
     * @param \PDO|null $dbh
     *
     * @return void
     */
    public static function setDbh(?\PDO $dbh = null): void
    {
        if ($dbh === null) {
            if (method_exists(Pool::class, 'openConnection')) {
                self::$dbh = Pool::openConnection();
            } else {
                throw new \RuntimeException('Pool class not loaded and no PDO object provided.');
            }
        } else {
            self::$dbh = $dbh;
        }
    }
    
    /**
     * Enable or disable debug mode
     * @param bool $debug
     *
     * @return void
     */
    public static function setDebug(bool $debug): void
    {
        self::$debug = $debug;
    }
    
    /**
     * Set the maximum time (in seconds) for the query (for `set_time_limit`)
     * @param int $maxRunTime
     *
     * @return void
     */
    public static function setMaxRunTime(int $maxRunTime): void
    {
        if ($maxRunTime < 1) {
            $maxRunTime = 1;
        }
        self::$maxRunTime = $maxRunTime;
    }
    
    /**
     * Set the number of times to retry in case of deadlock
     * @param int $maxTries
     *
     * @return void
     */
    public static function setMaxTries(int $maxTries): void
    {
        if ($maxTries < 1) {
            $maxTries = 1;
        }
        self::$maxTries = $maxTries;
    }
    
    /**
     * Set the time (in seconds) to wait between retries in case of deadlock
     * @param int $sleep
     *
     * @return void
     */
    public static function setSleep(int $sleep): void
    {
        if ($sleep < 1) {
            $sleep = 1;
        }
        self::$sleep = $sleep;
    }
}