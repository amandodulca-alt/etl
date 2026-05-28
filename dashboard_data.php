<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $pdo = new PDO(
        "mysql:host=127.0.0.1;dbname=company_dw;charset=utf8",
        "root",
        "",
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    echo json_encode(["error" => "Database connection failed: " . $e->getMessage()]);
    exit;
}

$view = $_GET['view'] ?? 'annual';
$dateFromRaw = $_GET['date_from'] ?? null;
$dateToRaw = $_GET['date_to'] ?? null;
$country = $_GET['country'] ?? 'all';

$dateFrom = $dateFromRaw;
$dateTo = $dateToRaw;

$dateWhere = "";
$dateParams = [];
if ($dateFrom && $dateTo) {
    $dateWhere = " AND date_key BETWEEN :date_from AND :date_to ";
    $dateParams = [':date_from' => $dateFrom, ':date_to' => $dateTo];
}

//Country for both customer
$countryWhere = "";
$countryParams = [];
if ($country && $country !== 'all') {
    $countryWhere = " AND country = :country ";
    $countryParams = [':country' => $country];
}

// Determine Period Expression based on View
switch ($view) {
    case 'quarterly':
        $periodExpr = "CONCAT(YEAR(date_key), ' Q', QUARTER(date_key))";
        break;
    case 'semi':
        $periodExpr = "CONCAT(YEAR(date_key), ' H', IF(MONTH(date_key) <= 6, 1, 2))";
        break;
    case 'annual':
    default:
        $periodExpr = "CAST(YEAR(date_key) AS CHAR)";
        break;
}

$result = [
    'stock_health' => [],
    'city_sales' => [],
    'productline_sales' => [],
    'product_sales' => [],
    'office_sales' => [],
    'meta' => ['countries' => [], 'date_min' => '', 'date_max' => '']
];

function query($pdo, $sql, $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

//table — stock health is never date/country filtered (warehouse-wide inventory snapshot)
$sql = "SELECT product_key, product_name, product_line,
               quantity_in_stock, buy_price, msrp,
               total_ordered, remaining_stock, remaining_value, `status`
        FROM fact_stock_health
        ORDER BY remaining_stock DESC";
$result['stock_health'] = query($pdo, $sql);
//2 bar chart
$sql = "SELECT city, country, 
            $periodExpr AS period,
            SUM(total_revenue) AS revenue,
            SUM(order_count) AS orders
        FROM fact_city_sales
        WHERE 1=1 $dateWhere $countryWhere
        GROUP BY city, country, period
        ORDER BY revenue DESC 
        LIMIT 15";
$result['city_sales'] = query($pdo, $sql, array_merge($dateParams, $countryParams));

//dougnut productlines
$sql = "SELECT product_line, 
            $periodExpr AS period,
            SUM(total_revenue) AS revenue,
            SUM(units_sold)    AS units
        FROM fact_productline_sales
        WHERE 1=1 $dateWhere
        GROUP BY product_line, period
        ORDER BY revenue DESC";
$result['productline_sales'] = query($pdo, $sql, $dateParams);

// 4 bar chart?
$sql = "SELECT product_name, product_line, 
            $periodExpr AS period,
            SUM(total_revenue) AS revenue,
            SUM(units_sold)    AS units
        FROM fact_product_sales
        WHERE 1=1 $dateWhere
        GROUP BY product_name, product_line, period
        ORDER BY revenue DESC
        LIMIT 8";
$result['product_sales'] = query($pdo, $sql, $dateParams);

//5 bar chart?
$sql = "SELECT office_key, city, country,
            $periodExpr AS period,
            SUM(total_revenue)  AS revenue,
            SUM(order_count)    AS orders,
            SUM(customer_count) AS customers
        FROM fact_office_sales
        WHERE 1=1 $dateWhere $countryWhere
        GROUP BY office_key, city, country, period
        ORDER BY revenue DESC";
$result['office_sales'] = query($pdo, $sql, array_merge($dateParams, $countryParams));


$result['meta']['countries'] = array_column(
    query($pdo, "SELECT DISTINCT country FROM dim_customer ORDER BY country"),
    'country'
);

// Always read the full unfiltered date range from the dimension table
$minMax = query($pdo, "SELECT MIN(date_key) AS min_d, MAX(date_key) AS max_d FROM dim_order_details");
$result['meta']['date_min'] = $minMax[0]['min_d'] ?? "";
$result['meta']['date_max'] = $minMax[0]['max_d'] ?? "";

echo json_encode($result);