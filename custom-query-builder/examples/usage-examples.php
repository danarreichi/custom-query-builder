<?php
/**
 * CustomQueryBuilder Usage Examples
 * 
 * This file demonstrates common patterns and can be used for testing.
 * Copy/paste relevant sections into your controllers or models.
 */

defined('BASEPATH') OR exit('No direct script access allowed');

class Example_queries extends CI_Controller 
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Basic eager loading example
     */
    public function basic_eager_loading()
    {
        // Get users with their orders
        $users = $this->db->select(['id', 'name', 'email'])
            ->with_many('orders', 'user_id', 'id', function($query) {
                $query->where('status', 'completed')
                      ->order_by('created_at', 'DESC')
                      ->limit(5);
            })
            ->where('users.status', 1)
            ->get('users');

        foreach ($users->result() as $user) {
            echo "User: {$user->name}\n";
            if (isset($user->orders)) {
                echo "Orders: " . count($user->orders) . "\n";
                foreach ($user->orders as $order) {
                    echo "  - Order #{$order->order_number}: {$order->total}\n";
                }
            }
        }
    }

    /**
     * Aggregation example
     */
    public function aggregations()
    {
        $users = $this->db->select(['id', 'name'])
            ->with_count('orders', 'user_id', 'id')
            ->with_sum(['orders' => 'total_spent'], 'user_id', 'id', 'amount')
            ->with_avg(['orders' => 'avg_order'], 'user_id', 'id', 'amount')
            ->where('users.status', 1)
            ->get('users');

        foreach ($users->result() as $user) {
            echo "User: {$user->name}\n";
            echo "Total Orders: {$user->orders_count}\n";
            echo "Total Spent: Rp " . number_format($user->total_spent ?? 0) . "\n";
            echo "Average Order: Rp " . number_format($user->avg_order ?? 0) . "\n";
            echo "---\n";
        }
    }

    /**
     * Calculation example with multiple aggregates
     */
    public function calculations()
    {
        $products = $this->db->select(['product.id', 'product.name'])
            ->with_calculation(['sales' => 'revenue'], 
                'product_id', 
                'id', 
                'SUM(price * quantity)'
            )
            ->with_calculation(['sales' => 'profit'], 
                'product_id', 
                'id', 
                'SUM((price - cost) * quantity)'
            )
            ->with_calculation(['sales' => 'profit_margin'], 
                'product_id', 
                'id',
                '((SUM((price - cost) * quantity)) / SUM(price * quantity)) * 100'
            )
            ->where('product.status', 1)
            ->get('product');

        foreach ($products->result() as $product) {
            echo "Product: {$product->name}\n";
            echo "Revenue: Rp " . number_format($product->revenue ?? 0) . "\n";
            echo "Profit: Rp " . number_format($product->profit ?? 0) . "\n";
            echo "Margin: " . number_format($product->profit_margin ?? 0, 2) . "%\n";
            echo "---\n";
        }
    }

    /**
     * WHERE aggregate filtering
     */
    public function filter_by_aggregates()
    {
        // Get high-value customers (>10 orders OR >Rp 5,000,000)
        $vip_customers = $this->db->select(['member.id', 'member.name'])
            ->with_count(['orders' => 'order_count'], 'member_id', 'id')
            ->with_sum(['orders' => 'lifetime_value'], 'member_id', 'id', 'total_amount')
            ->where_aggregate('order_count >', 10)
            ->or_where_aggregate('lifetime_value >', 5000000)
            ->where('member.status', 1)
            ->get('member');

        echo "VIP Customers:\n";
        foreach ($vip_customers->result() as $customer) {
            echo "- {$customer->name} (Orders: {$customer->order_count}, ";
            echo "Lifetime Value: Rp " . number_format($customer->lifetime_value) . ")\n";
        }
    }

    /**
     * WHERE EXISTS example
     */
    public function where_exists_example()
    {
        // Get users who have placed orders
        $users_with_orders = $this->db->select(['id', 'name'])
            ->where_exists_relation('orders', 'user_id', 'id', function($query) {
                $query->where('status', 'completed');
            })
            ->get('users');

        echo "Users with completed orders:\n";
        foreach ($users_with_orders->result() as $user) {
            echo "- {$user->name}\n";
        }

        // Get users without any orders
        $users_without_orders = $this->db->select(['id', 'name'])
            ->where_not_exists_relation('orders', 'user_id', 'id')
            ->get('users');

        echo "\nUsers without orders:\n";
        foreach ($users_without_orders->result() as $user) {
            echo "- {$user->name}\n";
        }
    }

    /**
     * Pagination with total count
     */
    public function pagination_example($page = 1, $per_page = 20)
    {
        $offset = ($page - 1) * $per_page;

        $result = $this->db->select(['product.*'])
            ->with_count('sales', 'product_id', 'id')
            ->with_sum(['sales' => 'revenue'], 'product_id', 'id', 'total')
            ->where('product.status', 1)
            ->calc_rows()
            ->get('product', $per_page, $offset);

        $products = $result->result();
        $total = $result->found_rows();
        $total_pages = ceil($total / $per_page);

        echo "Page {$page} of {$total_pages} (Total: {$total} products)\n";
        foreach ($products as $product) {
            echo "- {$product->name} (Sales: {$product->sales_count})\n";
        }
    }

    /**
     * Chunking large datasets
     */
    public function chunk_example()
    {
        echo "Processing users in chunks...\n";
        
        $total_processed = $this->db->where('status', 1)
            ->chunk(100, function($users) {
                foreach ($users as $user) {
                    // Process each user
                    // Example: send email, update record, etc.
                    echo "Processing user: {$user->name}\n";
                }
            }, 'users');

        echo "\nTotal users processed: {$total_processed}\n";
    }

    /**
     * Conditional query building
     */
    public function conditional_query($filters = [])
    {
        $query = $this->db->select(['product.*'])
            ->from('product');

        // Apply filters conditionally
        $query->when(isset($filters['category']), function($q) use ($filters) {
                $q->where('category_id', $filters['category']);
            })
            ->when(isset($filters['min_price']), function($q) use ($filters) {
                $q->where('price >=', $filters['min_price']);
            })
            ->when(isset($filters['max_price']), function($q) use ($filters) {
                $q->where('price <=', $filters['max_price']);
            })
            ->when(isset($filters['search']), function($q) use ($filters) {
                $q->search($filters['search'], ['name', 'description']);
            })
            ->when(isset($filters['in_stock']), function($q) {
                $q->where_exists_relation('stock', 'product_id', 'id', function($sq) {
                    $sq->where('quantity >', 0);
                });
            });

        $products = $query->where('product.status', 1)->get();
        return $products->result();
    }

    /**
     * Multi-tenant product query with domain/outlet filtering
     */
    public function multi_tenant_product_query()
    {
        $app_domain = $this->config->item('app_domain');
        $app_outlet = $this->config->item('app_outlet');

        $query = $this->db->select(['product.*'])
            ->from('product');

        // CRITICAL: Domain filtering for tenant isolation
        if ($app_domain != '') {
            $query->where('domain_name', $app_domain)
                  ->where('product_domain.status', 1)
                  ->where('domain.status', 1)
                  ->join('product_domain', 'product.idproduct=product_domain.idproduct')
                  ->join('domain', 'product_domain.iddomain=domain.iddomain');
        }

        // CRITICAL: Outlet filtering for tenant isolation
        if (strlen($app_outlet) > 0) {
            $query->where('product_outlet.idoutlet', $app_outlet)
                  ->where('product_outlet.status', 1)
                  ->join('product_outlet', 'product.idproduct=product_outlet.idproduct');
        }

        // Load relationships
        $products = $query->with_one('category', 'idcategory', 'idcategory')
            ->with_many('product_image', 'idproduct', 'idproduct', function($q) {
                $q->where('status', 1)->order_by('sort_order', 'ASC');
            })
            ->with_sum(['stock' => 'available_stock'], 'idproduct', 'idproduct', 'stock_qty')
            ->where('product.status', 1)
            ->where('product.is_online', 1)
            ->get();

        return $products->result();
    }

    /**
     * Nested relationships example
     */
    public function nested_relationships()
    {
        $users = $this->db->select(['id', 'name'])
            ->with_many('orders', 'user_id', 'id', function($query) {
                // Nested: Load order items for each order
                $query->select(['id', 'order_number', 'total', 'created_at'])
                      ->with_many('order_items', 'order_id', 'id', function($q) {
                          $q->select(['id', 'product_id', 'quantity', 'price'])
                            ->with_one('product', 'id', 'product_id');
                      })
                      ->where('status', 'completed')
                      ->order_by('created_at', 'DESC')
                      ->limit(3);
            })
            ->where('users.status', 1)
            ->limit(5)
            ->get('users');

        foreach ($users->result() as $user) {
            echo "User: {$user->name}\n";
            if (isset($user->orders)) {
                foreach ($user->orders as $order) {
                    echo "  Order #{$order->order_number} ({$order->created_at})\n";
                    if (isset($order->order_items)) {
                        foreach ($order->order_items as $item) {
                            $product_name = $item->product->name ?? 'Unknown';
                            echo "    - {$product_name}: {$item->quantity} x Rp " . 
                                 number_format($item->price) . "\n";
                        }
                    }
                }
            }
            echo "---\n";
        }
    }

    /**
     * Complex filtering with grouped conditions
     */
    public function complex_filtering()
    {
        $products = $this->db->select(['product.*'])
            ->from('product')
            ->where('status', 1)
            // Group: (category = 'electronics' OR category = 'computers')
            ->group(function($query) {
                $query->where('category', 'electronics')
                      ->or_where('category', 'computers');
            })
            // AND price between 1000 and 10000
            ->where_between('price', [1000, 10000])
            // AND has stock
            ->where_exists_relation('stock', 'product_id', 'id', function($q) {
                $q->where('quantity >', 0);
            })
            ->order_by('name', 'ASC')
            ->get();

        return $products->result();
    }

    /**
     * Transaction-safe bulk operations
     */
    public function transaction_example()
    {
        $this->db->transaction(function() {
            // Get products that need price update
            $products = $this->db->where('category_id', 5)
                ->where('status', 1)
                ->get('products');

            foreach ($products->result() as $product) {
                $new_price = $product->price * 1.1; // 10% increase

                $this->db->where('id', $product->id)
                    ->update('products', [
                        'price' => $new_price,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            }

            echo "Updated " . $products->num_rows() . " products\n";
        });
    }

    /**
     * Search example
     */
    public function search_example($keyword)
    {
        $products = $this->db->select(['product.*'])
            ->search($keyword, ['product_name', 'product_description', 'product_sku'])
            ->with_one('category', 'idcategory', 'idcategory')
            ->where('product.status', 1)
            ->order_by('product_name', 'ASC')
            ->get('product');

        echo "Search results for: {$keyword}\n";
        foreach ($products->result() as $product) {
            $category = $product->category->category_name ?? 'Uncategorized';
            echo "- {$product->product_name} ({$category})\n";
        }
    }
}
