<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Chunking Trait
 *
 * chunk()/chunk_by_id() — process large result sets in bounded-size pages
 * instead of loading everything into memory at once.
 *
 * @package CustomQueryBuilder
 * @author  Danar Ardiwinanto
 * @version 1.0.0
 */
trait ChunkingTrait
{
    /**
     * Process large datasets in chunks to avoid memory issues
     * 
     * Example:
     * // Process 1000 records at a time
     * $this->db->chunk(1000, function($users) {
     *     foreach ($users as $user) {
     *         // Process each user
     *         echo $user->name . "\n";
     *     }
     * }, 'users');
     * 
     * // With conditions
     * $this->db->where('status', 'active')
     *          ->chunk(500, function($users, $page) {
     *              echo "Processing page: $page\n";
     *              // Return false to stop processing
     *              if ($page > 10) return false;
     *          }, 'users');
     * 
     * @param int $page_size Number of records per chunk
     * @param callable(array, int): bool|void $callback Callback function to process each chunk
     * @param string $table Table name (optional)
     * @return int Total number of records processed
     * @throws InvalidArgumentException
     */
    public function chunk($page_size, $callback, $table = '')
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Callback must be callable');
        }

        if ($page_size <= 0) {
            throw new InvalidArgumentException('Page size must be greater than 0');
        }

        $offset = 0;
        $page = 1;
        $total_processed = 0;

        do {
            // Clone query untuk setiap chunk
            $chunk_query = clone $this;

            // get() akan otomatis process semua pending operations
            $chunk_result = $chunk_query->get($table, $page_size, $offset);

            $chunk_data = $chunk_result->result();
            $chunk_count = count($chunk_data);

            if ($chunk_count === 0)
                break;

            // Execute callback
            $continue = $callback($chunk_data, $page);

            if ($continue === false)
                break;

            $total_processed += $chunk_count;
            $page++;
            $offset += $page_size;

            // Cleanup untuk mencegah memory leak
            unset($chunk_query, $chunk_result, $chunk_data);

            // Garbage collection setiap 10 page
            if ($page % 10 === 0 && function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            // Stop jika chunk terakhir tidak penuh
            if ($chunk_count < $page_size)
                break;
        } while (true);

        return $total_processed;
    }

    /**
     * Process large datasets in chunks ordered by ID to avoid memory issues and gaps
     * 
     * Example:
     * // Process users ordered by ID
     * $this->db->chunk_by_id(1000, function($users) {
     *     foreach ($users as $user) {
     *         // Process each user
     *         $this->send_email($user->email);
     *     }
     * }, 'id', 'users');
     * 
     * // With conditions - processes only active users
     * $this->db->where('status', 'active')
     *          ->chunk_by_id(500, function($users, $page) {
     *              echo "Processing page: $page\n";
     *              return true; // Continue processing
     *          }, 'id', 'users');
     * 
     * @param int $page_size Number of records per chunk
     * @param callable(array, int): bool|void $callback Callback function to process each chunk
     * @param string $column Column name for ordering (usually ID)
     * @param string $table Table name (optional)
     * @return int Total number of records processed
     * @throws InvalidArgumentException
     */
    public function chunk_by_id($page_size, $callback, $column, $table = '')
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Callback must be callable');
        }

        if (!$column) {
            throw new InvalidArgumentException('Column is required');
        }

        if ($page_size <= 0) {
            throw new InvalidArgumentException('Page size must be greater than 0');
        }

        $last_id = 0;
        $page = 1;
        $total_processed = 0;

        do {
            // Clone query dan tambahkan kondisi untuk ID
            $chunk_query = clone $this;

            if ($last_id > 0) {
                $chunk_query->where($column . ' >', $last_id);
            }

            // Order by ID dan limit
            $chunk_query->order_by($column, 'ASC');

            // get() akan otomatis process semua pending operations
            $chunk_result = $chunk_query->get($table, $page_size);

            $chunk_data = $chunk_result->result();
            $chunk_count = count($chunk_data);

            if ($chunk_count === 0)
                break;

            // Execute callback
            $continue = $callback($chunk_data, $page);

            if ($continue === false)
                break;

            // Get last ID from chunk
            $last_record = end($chunk_data);
            if (isset($last_record->$column)) {
                $last_id = $last_record->$column;
            } else {
                $last_array = (array) $last_record;
                if (isset($last_array[$column])) {
                    $last_id = $last_array[$column];
                } else {
                    throw new InvalidArgumentException("Column '{$column}' not found in result set");
                }
            }

            $total_processed += $chunk_count;
            $page++;

            // Cleanup untuk mencegah memory leak
            unset($chunk_query, $chunk_result, $chunk_data);

            // Garbage collection setiap 10 page
            if ($page % 10 === 0 && function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            // Stop jika chunk terakhir tidak penuh
            if ($chunk_count < $page_size)
                break;
        } while (true);

        return $total_processed;
    }
}
