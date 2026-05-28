<?php
$src = new PDO(
    "mysql:host=127.0.0.1;dbname=company_db;charset=utf8",
    "root",
    "",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$dw = new PDO(
    "mysql:host=127.0.0.1;dbname=company_dw;charset=utf8",
    "root",
    "",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function monthName(int $m): string
{
    return date('F', mktime(0, 0, 0, $m, 1));
}


//etl from db to dim
//date dim from orderdate and match it with paymentdate
$dates = $src->query("SELECT DISTINCT orderDate AS d FROM orders UNION SELECT DISTINCT paymentDate FROM payments")->fetchAll(PDO::FETCH_COLUMN);

$ins = $dw->prepare("INSERT IGNORE INTO dim_date (date_key, year, quarter, half_year, month, month_name, quarter_label, half_label)VALUES (?,?,?,?,?,?,?,?)");
foreach ($dates as $d) {
    $y = (int) date('Y', strtotime($d)); //year
    $m = (int) date('n', strtotime($d)); //month
    $q = (int) ceil($m / 3); //quarter
    $h = $m <= 6 ? 1 : 2; //half
    $ins->execute([$d, $y, $q, $h, $m, monthName($m), "Q$q $y", "H$h $y"]);
}

//product
$dw->exec("TRUNCATE TABLE dim_product");
$rows = $src->query("SELECT productCode, productName, productLine, quantityInStock, buyPrice, MSRP FROM products")->fetchAll(PDO::FETCH_ASSOC);

$ins = $dw->prepare("INSERT INTO dim_product (product_key, product_name, product_line, quantity_in_stock, buy_price, msrp) VALUES (?,?,?,?,?,?)");
foreach ($rows as $r) {
    $ins->execute([$r['productCode'], $r['productName'], $r['productLine'], $r['quantityInStock'], $r['buyPrice'], $r['MSRP']]);
}

//custoemer, check office code by matching salesrep and employee then later matchn it with sales later for 5th fact
$dw->exec("TRUNCATE TABLE dim_customer");
$rows = $src->query(
    "SELECT c.customerNumber, c.customerName, c.city, c.country, e.officeCode FROM customers c LEFT JOIN employees e ON e.employeeNumber = c.salesRepEmployeeNumber"
)->fetchAll(PDO::FETCH_ASSOC);

$ins = $dw->prepare("INSERT INTO dim_customer (customer_key, customer_name, city, country, office_key) VALUES (?,?,?,?,?)");
foreach ($rows as $r) {
    $ins->execute([$r['customerNumber'], $r['customerName'], $r['city'], $r['country'], $r['officeCode']]);
}

//office
$dw->exec("TRUNCATE TABLE dim_office");
$rows = $src->query("SELECT officeCode, city, country FROM offices")->fetchAll(PDO::FETCH_ASSOC);

$ins = $dw->prepare("INSERT INTO dim_office (office_key, city, country) VALUES (?,?,?)");
foreach ($rows as $r) {
    $ins->execute([$r['officeCode'], $r['city'], $r['country']]);
}

//orderdetails
$dw->exec("TRUNCATE TABLE dim_order_details");
$rows = $src->query("SELECT o.orderNumber, o.customerNumber, o.orderDate, od.productCode, od.quantityOrdered, od.priceEach FROM orders o JOIN orderdetails od ON o.orderNumber = od.orderNumber"
)->fetchAll(PDO::FETCH_ASSOC);

$ins = $dw->prepare("INSERT INTO dim_order_details (order_number, customer_key, date_key, product_key, quantity_ordered, price_each) VALUES (?,?,?,?,?,?)");
foreach ($rows as $sr) {
    $ins->execute([$sr['orderNumber'], $sr['customerNumber'], $sr['orderDate'], $sr['productCode'], $sr['quantityOrdered'], $sr['priceEach']]);
}

unset($src);

//from dim to fact
//stock fact. (stock - order) * price. 0 - oos, <20% low stock, <50% watch out, else healthy
$dw->exec("TRUNCATE TABLE fact_stock_health");
$dw->exec("INSERT INTO fact_stock_health (product_key, product_name, product_line, quantity_in_stock, buy_price, msrp, total_ordered, remaining_stock, remaining_value, status)
    SELECT
        p.product_key,
        p.product_name,
        p.product_line,
        p.quantity_in_stock,
        p.buy_price,
        p.msrp,
        COALESCE(SUM(s.quantity_ordered), 0) AS total_ordered,
        p.quantity_in_stock - COALESCE(SUM(s.quantity_ordered), 0) AS remaining_stock,
        (p.quantity_in_stock - COALESCE(SUM(s.quantity_ordered), 0)) * p.buy_price AS remaining_value,
        CASE
            WHEN (p.quantity_in_stock - COALESCE(SUM(s.quantity_ordered),0)) <= 0
                THEN 'Out of Stock'
            WHEN (p.quantity_in_stock - COALESCE(SUM(s.quantity_ordered),0)) / p.quantity_in_stock < 0.20
                THEN 'Low Stock'
            WHEN (p.quantity_in_stock - COALESCE(SUM(s.quantity_ordered),0)) / p.quantity_in_stock < 0.50
                THEN 'Watch out'
            ELSE 'Healthy'
        END AS `status`
    FROM dim_product p
    LEFT JOIN dim_order_details s ON s.product_key = p.product_key
    GROUP BY p.product_key, p.product_name, p.product_line, p.quantity_in_stock, p.buy_price, p.msrp");


//city from customers fact
$dw->exec("TRUNCATE TABLE fact_city_sales");
$dw->exec("INSERT INTO fact_city_sales (date_key, customer_key, city, country, total_revenue, order_count)
    SELECT 
        s.date_key,
        s.customer_key,
        c.city,
        c.country,
        SUM(s.quantity_ordered * s.price_each),
        COUNT(DISTINCT s.order_number)
    FROM dim_order_details s
    JOIN dim_customer c ON c.customer_key = s.customer_key
    GROUP BY s.date_key, s.customer_key, c.city, c.country"
);

//productlines sales
$dw->exec("TRUNCATE TABLE fact_productline_sales");
$dw->exec("INSERT INTO fact_productline_sales (date_key, product_line, total_revenue, units_sold)
    SELECT 
        s.date_key,
        p.product_line,
        SUM(s.quantity_ordered * s.price_each),
        SUM(s.quantity_ordered)
    FROM dim_order_details s
    JOIN dim_product p ON p.product_key = s.product_key
    GROUP BY s.date_key, p.product_line"
);
//product sales fact based on orderdate 
$dw->exec("TRUNCATE TABLE fact_product_sales");
$dw->exec("INSERT INTO fact_product_sales (date_key, product_key, product_name, product_line, total_revenue, units_sold)
    SELECT 
        s.date_key,
        p.product_key,
        p.product_name,
        p.product_line,
        SUM(s.quantity_ordered * s.price_each),
        SUM(s.quantity_ordered)
    FROM dim_order_details s
    JOIN dim_product p ON p.product_key = s.product_key
    GROUP BY s.date_key, p.product_key, p.product_name, p.product_line"
);


//office sales
$dw->exec("TRUNCATE TABLE fact_office_sales");
$dw->exec("INSERT INTO fact_office_sales (date_key, office_key, city, country, total_revenue, order_count, customer_count)
    SELECT 
        s.date_key,
        o.office_key,
        o.city,
        o.country,
        SUM(s.quantity_ordered * s.price_each),
        COUNT(DISTINCT s.order_number),
        COUNT(DISTINCT s.customer_key)
    FROM dim_order_details s
    JOIN dim_customer c ON c.customer_key = s.customer_key
    JOIN dim_office o   ON o.office_key = c.office_key
    GROUP BY s.date_key, o.office_key, o.city, o.country"
);

echo "\nETL Complete.\n";
?>