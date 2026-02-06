# Advanced CustomQueryBuilder Patterns

This document contains advanced patterns and real-world examples from the Liteprint codebase.

## Multi-Tenant Product Queries

### Complete Product Query with Domain/Outlet Filtering

```php
public function get_products($filters = [])
{
    $app_domain = $this->config->item('app_domain');
    $app_outlet = $this->config->item('app_outlet');
    
    $query = $this->db->select([
        'product.*',
        'category.category_name',
        'category.category_slug'
    ])
    ->from('product')
    ->join('category', 'product.idcategory = category.idcategory', 'left');
    
    // CRITICAL: Domain filtering (tenant isolation)
    if ($app_domain != '') {
        $query->where('domain_name', $app_domain)
              ->where('product_domain.status', 1)
              ->where('domain.status', 1)
              ->join('product_domain', 'product.idproduct=product_domain.idproduct')
              ->join('domain', 'product_domain.iddomain=domain.iddomain');
    }
    
    // CRITICAL: Outlet filtering (tenant isolation)
    if (strlen($app_outlet) > 0) {
        $query->where('product_outlet.idoutlet', $app_outlet)
              ->where('product_outlet.status', 1)
              ->join('product_outlet', 'product.idproduct=product_outlet.idproduct');
    }
    
    // Additional filters
    if (isset($filters['category'])) {
        $query->where('product.idcategory', $filters['category']);
    }
    
    if (isset($filters['search'])) {
        $query->group(function($q) use ($filters) {
            $q->like('product.product_name', $filters['search'])
              ->or_like('product.product_description', $filters['search']);
        });
    }
    
    // Load relationships
    $query->with_many('product_image', 'idproduct', 'idproduct', function($q) {
        $q->where('status', 1)
          ->order_by('sort_order', 'ASC')
          ->limit(5);
    })
    ->with_count(['transaction_detail' => 'total_sold'], 'idproduct', 'idproduct', function($q) {
        $q->where('status', 1);
    })
    ->where('product.status', 1)
    ->where('product.is_online', 1);
    
    return $query->get();
}
```

### Product Search with Stock Information

```php
public function search_products($keyword, $limit = 20, $offset = 0)
{
    $app_domain = $this->config->item('app_domain');
    $app_outlet = $this->config->item('app_outlet');
    
    $result = $this->db->select([
        'product.idproduct',
        'product.product_name',
        'product.product_slug',
        'product.product_price',
        'product.product_image'
    ])
    ->from('product')
    // Domain/outlet filtering
    ->when($app_domain != '', function($query) use ($app_domain) {
        $query->where('domain_name', $app_domain)
              ->where('product_domain.status', 1)
              ->join('product_domain', 'product.idproduct=product_domain.idproduct')
              ->join('domain', 'product_domain.iddomain=domain.iddomain');
    })
    ->when(strlen($app_outlet) > 0, function($query) use ($app_outlet) {
        $query->where('product_outlet.idoutlet', $app_outlet)
              ->where('product_outlet.status', 1)
              ->join('product_outlet', 'product.idproduct=product_outlet.idproduct');
    })
    // Search functionality
    ->search($keyword, ['product_name', 'product_description', 'product_sku'])
    ->with_sum(['stock' => 'available_stock'], 'idproduct', 'idproduct', 'stock_qty')
    ->with_count(['transaction_detail' => 'sold_count'], 'idproduct', 'idproduct')
    ->where('product.status', 1)
    ->calc_rows()
    ->get('product', $limit, $offset);
    
    return [
        'data' => $result->result(),
        'total' => $result->found_rows(),
        'pages' => ceil($result->found_rows() / $limit)
    ];
}
```

## ERP Transaction Queries

### Transaction List with Job Aggregations

```php
public function get_transaction_list($filters = [])
{
    $query = $this->db->select([
        'transaction.*',
        'member.member_name',
        'member.member_phone',
        'outlet.outlet_name'
    ])
    ->from('transaction')
    ->join('member', 'transaction.idmember = member.idmember', 'left')
    ->join('outlet', 'transaction.idoutlet = outlet.idoutlet', 'left')
    // Load job relationship with nested calculations
    ->with_many('job', 'idtransaction', 'idtransaction', function($query) {
        $query->select([
            'job.idjob',
            'job.job_name',
            'job.job_qty',
            'job.job_total_price_after_discount'
        ])
        ->with_sum(['job_detail' => 'total_finished'], 'idjob', 'idjob', 
            'job_detail_qty_finish'
        )
        ->with_calculation(['job_detail' => 'completion_percentage'], 'idjob', 'idjob',
            '(SUM(job_detail_qty_finish) / job_qty) * 100'
        )
        ->where('job.status', 1);
    })
    // Transaction-level aggregations
    ->with_sum(['job' => 'total_job_value'], 'idtransaction', 'idtransaction', 
        'job_total_price_after_discount'
    )
    ->with_count('job', 'idtransaction', 'idtransaction')
    ->with_calculation(['job' => 'avg_job_value'], 'idtransaction', 'idtransaction',
        'AVG(job_total_price_after_discount)'
    );
    
    // Filters
    if (isset($filters['status'])) {
        $query->where('transaction.transaction_status', $filters['status']);
    }
    
    if (isset($filters['outlet'])) {
        $query->where('transaction.idoutlet', $filters['outlet']);
    }
    
    if (isset($filters['date_from'])) {
        $query->where('DATE(transaction.created) >=', $filters['date_from']);
    }
    
    if (isset($filters['date_to'])) {
        $query->where('DATE(transaction.created) <=', $filters['date_to']);
    }
    
    // Filter by minimum order value
    if (isset($filters['min_value'])) {
        $query->with_sum(['job' => 'filter_total'], 'idtransaction', 'idtransaction', 
            'job_total_price_after_discount'
        )
        ->where_aggregate('filter_total >=', $filters['min_value']);
    }
    
    return $query->where('transaction.status', 1)
                 ->latest('transaction.created')
                 ->get();
}
```

### Production Progress Tracking

```php
public function get_production_progress($idtransaction)
{
    $transaction = $this->db->select([
        'transaction.*',
        'member.member_name',
        'outlet.outlet_name'
    ])
    ->with_many('job', 'idtransaction', 'idtransaction', function($query) {
        $query->select([
            'job.*',
            'product.product_name'
        ])
        ->join('product', 'job.idproduct = product.idproduct', 'left')
        ->with_many('job_detail', 'idjob', 'idjob', function($q) {
            $q->select([
                'job_detail.*',
                'machine.machine_name',
                'user.user_name as operator_name'
            ])
            ->join('machine', 'job_detail.idmachine = machine.idmachine', 'left')
            ->join('user', 'job_detail.iduser = user.iduser', 'left')
            ->where('job_detail.status', 1)
            ->order_by('job_detail.job_detail_sequence', 'ASC');
        })
        ->with_calculation(['job_detail' => 'total_finished'], 'idjob', 'idjob',
            'SUM(job_detail_qty_finish)'
        )
        ->with_calculation(['job_detail' => 'completion_pct'], 'idjob', 'idjob',
            '(SUM(job_detail_qty_finish) / job_qty) * 100'
        )
        ->with_calculation(['transaction_step' => 'production_days'], 'idjob', 'idjob',
            'DATEDIFF(MAX(date), MIN(date))'
        )
        ->where('job.status', 1);
    })
    ->where('transaction.idtransaction', $idtransaction)
    ->where('transaction.status', 1)
    ->first('transaction');
    
    return $transaction;
}
```

## Member/Customer Queries

### Member Statistics Dashboard

```php
public function get_member_statistics($idoutlet)
{
    $members = $this->db->select([
        'member.idmember',
        'member.member_name',
        'member.member_email',
        'member.member_phone',
        'member.created'
    ])
    ->from('member')
    // Transaction statistics
    ->with_count(['transaction' => 'total_orders'], 'idmember', 'idmember', function($q) {
        $q->where('transaction_status !=', 'cancelled');
    })
    ->with_sum(['transaction' => 'total_spent'], 'idmember', 'idmember', 
        'transaction_grand_total', false, function($q) {
            $q->where('transaction_status', 'completed');
        }
    )
    ->with_avg(['transaction' => 'avg_order_value'], 'idmember', 'idmember', 
        'transaction_grand_total', false, function($q) {
            $q->where('transaction_status', 'completed');
        }
    )
    ->with_one('latest_transaction', 'idmember', 'idmember', function($q) {
        $q->select(['idtransaction', 'transaction_number', 'created', 'transaction_grand_total'])
          ->order_by('created', 'DESC');
    })
    ->with_calculation(['transaction' => 'months_since_last'], 'idmember', 'idmember',
        'TIMESTAMPDIFF(MONTH, MAX(created), NOW())'
    )
    // Filter active customers (at least 1 completed order)
    ->where_has('transaction', 'idmember', 'idmember', function($q) {
        $q->where('transaction_status', 'completed');
    }, '>=', 1)
    // High-value customers filter (optional)
    ->with_sum(['transaction' => 'lifetime_value'], 'idmember', 'idmember', 
        'transaction_grand_total'
    )
    ->where_aggregate('lifetime_value >', 1000000)
    ->where('member.idoutlet', $idoutlet)
    ->where('member.status', 1)
    ->latest('member.created')
    ->get();
    
    return $members->result();
}
```

### Customer Segmentation

```php
public function segment_customers($idoutlet)
{
    // VIP Customers (>10 orders OR >Rp 5,000,000)
    $vip = $this->db->select(['member.idmember', 'member.member_name'])
        ->with_count('transaction', 'idmember', 'idmember')
        ->with_sum(['transaction' => 'total'], 'idmember', 'idmember', 'transaction_grand_total')
        ->where_aggregate('transaction_count >', 10)
        ->or_where_aggregate('total >', 5000000)
        ->where('member.idoutlet', $idoutlet)
        ->get('member')
        ->result();
    
    // Inactive Customers (no orders in 6 months)
    $inactive = $this->db->select(['member.idmember', 'member.member_name'])
        ->with_calculation(['transaction' => 'months_inactive'], 'idmember', 'idmember',
            'TIMESTAMPDIFF(MONTH, MAX(created), NOW())'
        )
        ->where_aggregate('months_inactive >', 6)
        ->where('member.idoutlet', $idoutlet)
        ->get('member')
        ->result();
    
    // New Customers (registered in last 30 days)
    $new_customers = $this->db->where('created >=', date('Y-m-d', strtotime('-30 days')))
        ->where('idoutlet', $idoutlet)
        ->get('member')
        ->result();
    
    return [
        'vip' => $vip,
        'inactive' => $inactive,
        'new' => $new_customers
    ];
}
```

## Invoice and Payment Queries

### Invoice with Complete Details

```php
public function get_invoice_details($idinvoice)
{
    $invoice = $this->db->select([
        'invoice.*',
        'member.member_name',
        'member.member_phone',
        'member.member_address',
        'outlet.outlet_name',
        'outlet.outlet_address'
    ])
    ->from('invoice')
    ->join('member', 'invoice.idmember = member.idmember', 'left')
    ->join('outlet', 'invoice.idoutlet = outlet.idoutlet', 'left')
    // Invoice items with product details
    ->with_many('invoice_detail', 'idinvoice', 'idinvoice', function($query) {
        $query->select([
            'invoice_detail.*',
            'product.product_name',
            'product.product_sku'
        ])
        ->join('product', 'invoice_detail.idproduct = product.idproduct', 'left')
        ->with_calculation(['job' => 'total_production_cost'], 'idinvoice_detail', 'idinvoice_detail',
            'SUM(job_production_cost)'
        )
        ->where('invoice_detail.status', 1);
    })
    // Payment history
    ->with_many('payment', 'idinvoice', 'idinvoice', function($query) {
        $query->select([
            'payment.*',
            'payment_method.method_name'
        ])
        ->join('payment_method', 'payment.idpayment_method = payment_method.idpayment_method', 'left')
        ->where('payment.status', 1)
        ->order_by('payment.created', 'ASC');
    })
    // Aggregations
    ->with_sum(['payment' => 'total_paid'], 'idinvoice', 'idinvoice', 'payment_amount')
    ->with_calculation(['invoice_detail' => 'subtotal'], 'idinvoice', 'idinvoice',
        'SUM(invoice_detail_qty * invoice_detail_price)'
    )
    ->with_calculation(['invoice_detail' => 'total_after_discount'], 'idinvoice', 'idinvoice',
        'SUM((invoice_detail_qty * invoice_detail_price) - invoice_detail_discount)'
    )
    ->where('invoice.idinvoice', $idinvoice)
    ->where('invoice.status', 1)
    ->first();
    
    // Calculate remaining balance
    if ($invoice) {
        $invoice->remaining_balance = $invoice->invoice_grand_total - ($invoice->total_paid ?? 0);
    }
    
    return $invoice;
}
```

## Inventory and Stock Queries

### Stock Report with Transaction History

```php
public function get_stock_report($idoutlet, $filters = [])
{
    $query = $this->db->select([
        'product.idproduct',
        'product.product_name',
        'product.product_sku',
        'category.category_name'
    ])
    ->from('product')
    ->join('category', 'product.idcategory = category.idcategory', 'left')
    // Current stock
    ->with_sum(['stock' => 'current_stock'], 'idproduct', 'idproduct', 'stock_qty', false, function($q) use ($idoutlet) {
        $q->where('idoutlet', $idoutlet)
          ->where('status', 1);
    })
    // Stock in (purchases)
    ->with_sum(['stock_in' => 'total_stock_in'], 'idproduct', 'idproduct', 'stock_in_qty', false, function($q) use ($idoutlet, $filters) {
        $q->where('idoutlet', $idoutlet);
        if (isset($filters['date_from'])) {
            $q->where('DATE(created) >=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $q->where('DATE(created) <=', $filters['date_to']);
        }
    })
    // Stock out (sales)
    ->with_sum(['transaction_detail' => 'total_sold'], 'idproduct', 'idproduct', 'transaction_detail_qty', false, function($q) use ($idoutlet, $filters) {
        $q->join('transaction', 'transaction_detail.idtransaction = transaction.idtransaction')
          ->where('transaction.idoutlet', $idoutlet)
          ->where('transaction.transaction_status', 'completed');
        if (isset($filters['date_from'])) {
            $q->where('DATE(transaction.created) >=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $q->where('DATE(transaction.created) <=', $filters['date_to']);
        }
    })
    // Stock value calculation
    ->with_calculation(['stock' => 'stock_value'], 'idproduct', 'idproduct',
        'SUM(stock_qty * stock_price)'
    )
    ->where('product.status', 1);
    
    // Low stock filter
    if (isset($filters['low_stock']) && $filters['low_stock']) {
        $query->with_sum(['stock' => 'check_stock'], 'idproduct', 'idproduct', 'stock_qty')
              ->where_aggregate('check_stock <', 10);
    }
    
    // Out of stock filter
    if (isset($filters['out_of_stock']) && $filters['out_of_stock']) {
        $query->where_doesnt_have('stock', 'idproduct', 'idproduct', function($q) use ($idoutlet) {
            $q->where('idoutlet', $idoutlet)
              ->where('stock_qty >', 0);
        });
    }
    
    return $query->get();
}
```

## Performance Optimization Patterns

### Chunked Export Processing

```php
public function export_transactions($idoutlet, $date_from, $date_to)
{
    $filename = 'transactions_' . date('YmdHis') . '.csv';
    $file = fopen($filename, 'w');
    
    // Write CSV header
    fputcsv($file, ['Transaction #', 'Date', 'Customer', 'Total', 'Status']);
    
    // Process in chunks to avoid memory issues
    $total_processed = $this->db->select([
        'transaction.transaction_number',
        'transaction.created',
        'member.member_name',
        'transaction.transaction_grand_total',
        'transaction.transaction_status'
    ])
    ->from('transaction')
    ->join('member', 'transaction.idmember = member.idmember', 'left')
    ->where('transaction.idoutlet', $idoutlet)
    ->where('DATE(transaction.created) >=', $date_from)
    ->where('DATE(transaction.created) <=', $date_to)
    ->chunk_by_id(500, function($records) use ($file) {
        foreach ($records as $record) {
            fputcsv($file, [
                $record->transaction_number,
                $record->created,
                $record->member_name,
                $record->transaction_grand_total,
                $record->transaction_status
            ]);
        }
    }, 'idtransaction');
    
    fclose($file);
    
    return [
        'filename' => $filename,
        'records' => $total_processed
    ];
}
```

### Batch Update with Chunking

```php
public function update_product_prices($category_id, $increase_percentage)
{
    $updated = 0;
    
    $this->db->where('idcategory', $category_id)
             ->where('status', 1)
             ->chunk(100, function($products) use ($increase_percentage, &$updated) {
                 foreach ($products as $product) {
                     $new_price = $product->product_price * (1 + ($increase_percentage / 100));
                     
                     $this->db->where('idproduct', $product->idproduct)
                              ->update('product', [
                                  'product_price' => $new_price,
                                  'modified' => date('Y-m-d H:i:s')
                              ]);
                     
                     $updated++;
                 }
             }, 'product');
    
    return $updated;
}
```

## Complex Filtering Examples

### Dynamic Report Builder

```php
public function build_dynamic_report($params)
{
    $query = $this->db->select(['transaction.*'])
        ->from('transaction');
    
    // Conditional joins based on needed fields
    $query->when(isset($params['customer_filter']), function($q) {
        $q->join('member', 'transaction.idmember = member.idmember', 'left');
    })
    ->when(isset($params['outlet_filter']), function($q) {
        $q->join('outlet', 'transaction.idoutlet = outlet.idoutlet', 'left');
    });
    
    // Conditional aggregations
    if (isset($params['include_job_count'])) {
        $query->with_count('job', 'idtransaction', 'idtransaction');
    }
    
    if (isset($params['include_total_value'])) {
        $query->with_sum(['job' => 'total_value'], 'idtransaction', 'idtransaction', 
            'job_total_price_after_discount'
        );
    }
    
    if (isset($params['include_production_days'])) {
        $query->with_calculation(['transaction_step' => 'avg_production_days'], 
            'idtransaction', 'idtransaction',
            'AVG(DATEDIFF(transaction_step_end, transaction_step_start))'
        );
    }
    
    // Dynamic filtering
    $query->when(isset($params['status']), function($q) use ($params) {
        $q->where('transaction.transaction_status', $params['status']);
    })
    ->when(isset($params['min_value']), function($q) use ($params) {
        $q->where('transaction.transaction_grand_total >=', $params['min_value']);
    })
    ->when(isset($params['date_range']), function($q) use ($params) {
        $q->where_between('DATE(transaction.created)', $params['date_range']);
    })
    ->when(isset($params['customer_name']), function($q) use ($params) {
        $q->like('member.member_name', $params['customer_name']);
    });
    
    // Conditional aggregation filters
    if (isset($params['min_job_count'])) {
        $query->with_count(['job' => 'job_count_filter'], 'idtransaction', 'idtransaction')
              ->where_aggregate('job_count_filter >=', $params['min_job_count']);
    }
    
    // Sorting
    $query->when(isset($params['sort_by']), function($q) use ($params) {
        $direction = $params['sort_direction'] ?? 'ASC';
        $q->order_by($params['sort_by'], $direction);
    }, function($q) {
        $q->latest('transaction.created');  // Default sort
    });
    
    // Pagination
    if (isset($params['paginate'])) {
        $limit = $params['per_page'] ?? 20;
        $offset = ($params['page'] ?? 0) * $limit;
        
        return $query->calc_rows()
                     ->get('transaction', $limit, $offset);
    }
    
    return $query->get();
}
```

## Testing Patterns

### Query Result Verification

```php
// Test that relationships are loaded
$result = $this->db->with_many('orders', 'user_id', 'id')
    ->first('users');

if (isset($result->orders)) {
    echo "Orders loaded successfully\n";
    echo "Order count: " . count($result->orders) . "\n";
}

// Test aggregations
$result = $this->db->with_count('orders', 'user_id', 'id')
    ->with_sum(['orders' => 'total'], 'user_id', 'id', 'amount')
    ->first('users');

echo "Order count: " . $result->orders_count . "\n";
echo "Total amount: " . $result->total . "\n";

// Verify calc_rows
$result = $this->db->calc_rows()
    ->get('products', 10, 0);

echo "Returned rows: " . $result->num_rows() . "\n";
echo "Total available: " . $result->found_rows() . "\n";
```

This reference covers the most common and advanced patterns used in the Liteprint codebase. Use these as templates for building similar queries in your controllers and models.
