<?php
declare(strict_types = 1);

namespace Simbiat\Database;

use function count;

/**
 * Useful semantic wrappers to modify stuff in databases
 */
final class Modify extends Query
{
    /**
     * Function useful for inserting into tables with AUTO INCREMENT. If INSERT is successful and `lastId` is supported will return the ID inserted, otherwise it will return false
     * @param string $query    `INSERT` query to process
     * @param array  $bindings List of bindings
     *
     * @return string|false
     */
    public static function insertAI(string $query, array $bindings = []): string|false
    {
        #Check if the query is an `INSERT`
        if (preg_match('/^\s*INSERT\s+INTO/ui', $query) !== 1) {
            throw new \UnexpectedValueException('Query is not INSERT.');
        }
        #Check that we have only 1 query
        $queries = self::stringToQueries($query);
        if (count($queries) > 1) {
            throw new \UnexpectedValueException('String provided seems to contain multiple queries.');
        }
        if (self::query($query, $bindings)) {
            return self::$lastId;
        }
        return false;
    }
}