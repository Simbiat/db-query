<?php
declare(strict_types = 1);

namespace Simbiat\Database;

/**
 * Useful semantic wrappers to SELECT from databases
 */
final class Select extends Query
{
    /**
     * Return full results as a multidimensional array (associative by default).
     *
     * @param string $query     Query to run
     * @param array  $bindings  List of bindings
     * @param int    $fetchMode Fetch mode
     *
     * @return array
     */
    public static function selectAll(string $query, array $bindings = [], int $fetchMode = \PDO::FETCH_ASSOC): array
    {
        try {
            if (self::isSelect($query) && self::query($query, $bindings, $fetchMode)) {
                return self::$lastResult;
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to select rows with `'.$e->getMessage().'`', 0, $e);
        }
        return [];
    }
    
    /**
     * Returns only 1 row from SELECT (essentially LIMIT 1).
     *
     * @param string $query     Query to run
     * @param array  $bindings  List of bindings
     * @param int    $fetchMode Fetch mode
     *
     * @return array
     */
    public static function selectRow(string $query, array $bindings = [], int $fetchMode = \PDO::FETCH_ASSOC): array
    {
        try {
            if (self::isSelect($query)) {
                #Check if the query has a limit (any limit)
                if (preg_match('/\s*LIMIT\s+(\d+\s*,\s*)?\d+\s*;?\s*$/ui', $query) !== 1) {
                    #If it does not - add it. But first we need to remove the final semicolon if any
                    #Need to do this, because otherwise I get 2 matches, which results in 2 entries added in further preg_replace
                    #No idea how to circumvent that.
                    #Also, add LIMIT to the end of the query.
                    $query = preg_replace(['/(;?\s*\z)/mui', '/\z/mui'], ['', ' LIMIT 0, 1;'], $query);
                }
                if (self::query($query, $bindings, $fetchMode, 'row')) {
                    return self::$lastResult;
                }
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to select row with `'.$e->getMessage().'`', 0, $e);
        }
        return [];
    }
    
    /**
     * Returns column (first by default) even if the original SELECT requests for more. Change the 3rd parameter accordingly to use another column as a key (starting from 0).
     * @param string $query    Query to run
     * @param array  $bindings List of bindings
     * @param int    $column   Number of the column to select
     *
     * @return array
     */
    public static function selectColumn(string $query, array $bindings = [], int $column = 0): array
    {
        try {
            if (self::isSelect($query) && self::query($query, $bindings, \PDO::FETCH_COLUMN, $column)) {
                return self::$lastResult;
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to select column with `'.$e->getMessage().'`', 0, $e);
        }
        return [];
    }
    
    /**
     * Returns a value directly, instead of an array containing that value. Useful for getting specific settings from DB. No return typing, since it may vary, so be careful with that.
     * @param string $query    Query to run
     * @param array  $bindings List of bindings
     * @param int    $column   Number of the column to select
     *
     * @return mixed|null
     */
    public static function selectValue(string $query, array $bindings = [], int $column = 0): mixed
    {
        try {
            if (self::isSelect($query) && self::query($query, $bindings, \PDO::FETCH_COLUMN, $column)) {
                #We always need to take the 1st element, since our goal is to return only 1 value
                return (self::$lastResult[0] ?? NULL);
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to select value with `'.$e->getMessage().'`', 0, $e);
        }
        return NULL;
    }
    
    /**
     * Returns key->value pair(s) based on 2 columns. The first column (by default) is used as a key. Change the 3rd parameter accordingly to use another column as a key (starting from 0).
     * @param string $query    Query to run
     * @param array  $bindings List of bindings
     * @param int    $column   Number of the column to select
     *
     * @return array
     */
    public static function selectPair(string $query, array $bindings = [], int $column = 0): array
    {
        try {
            if (self::isSelect($query) && self::query($query, $bindings, \PDO::FETCH_KEY_PAIR, $column)) {
                return self::$lastResult;
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to select pairs with `'.$e->getMessage().'`', 0, $e);
        }
        return [];
    }
    
    /**
     * Returns unique values from a column (first by default). Change the 3rd parameter accordingly to use another column as a key (starting from 0).
     * @param string $query    Query to run
     * @param array  $bindings List of bindings
     * @param int    $column   Number of the column to select
     *
     * @return array
     */
    public static function selectUnique(string $query, array $bindings = [], int $column = 0): array
    {
        try {
            if (self::isSelect($query) && self::query($query, $bindings, \PDO::FETCH_COLUMN | \PDO::FETCH_UNIQUE, $column)) {
                return self::$lastResult;
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to select unique rows with `'.$e->getMessage().'`', 0, $e);
        }
        return [];
    }
    
    /**
     * Returns count value from SELECT.
     * @param string $query    `SELECT COUNT()` query. It can have other columns, but they will be ignored.
     * @param array  $bindings List of bindings.
     *
     * @return int
     */
    public static function count(string $query, array $bindings = []): int
    {
        if (preg_match('/^\s*SELECT COUNT/mi', $query) === 1) {
            try {
                if (self::query($query, $bindings, \PDO::FETCH_COLUMN, 0)) {
                    if (empty(self::$lastResult)) {
                        return 0;
                    }
                    return (int)self::$lastResult[0];
                }
                return 0;
            } catch (\Throwable $e) {
                throw new \RuntimeException('Failed to count rows with `'.$e->getMessage().'`', 0, $e);
            }
        } else {
            throw new \UnexpectedValueException('Query is not SELECT COUNT.');
        }
    }
    
    /**
     * Returns a boolean value indicating if anything matching SELECT exists.
     * @param string $query     Query to run
     * @param array  $bindings  List of bindings
     * @param int    $fetchMode Fetch mode
     *
     * @return bool
     */
    public static function check(string $query, array $bindings = [], int $fetchMode = \PDO::FETCH_ASSOC): bool
    {
        try {
            if (self::isSelect($query) && self::query($query, $bindings, $fetchMode)) {
                return !empty(self::$lastResult);
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to check if value exists with `'.$e->getMessage().'`', 0, $e);
        }
        return false;
    }
}