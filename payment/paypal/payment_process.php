<?php
ob_start();
session_start();
require_once('../../admin/inc/config.php');

// Using Exchange-Rate API
// Function to get the exchange rate
function getExchangeRate($from_currency, $to_currency) {
    $apiKey = '4b1de162f04b72796ecfb964'; // API key
    $url = "https://v6.exchangerate-api.com/v6/$apiKey/latest/$from_currency";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return isset($data['conversion_rates'][$to_currency]) ? $data['conversion_rates'][$to_currency] : false;
}

$error_message = '';

// Fetch PayPal email from settings
$statement = $pdo->prepare("SELECT * FROM tbl_settings WHERE id=1");
$statement->execute();
$result = $statement->fetchAll(PDO::FETCH_ASSOC);
$paypal_email = '';
if (isset($result[0]['paypal_email'])) {
    $paypal_email = $result[0]['paypal_email'];
}

$return_url = 'payment_success.php';
$cancel_url = 'payment.php';
$notify_url = 'payment/paypal/verify_process.php';

$item_name = 'Product Item(s)';
$item_amount = isset($_POST['final_total']) ? $_POST['final_total'] : 0;
$item_number = time();

$payment_date = date('Y-m-d H:i:s');

// Get the current exchange rate from INR to USD
$exchange_rate = getExchangeRate('INR', 'USD');

if ($exchange_rate) {
    // Convert the INR amount to USD
    $usd_amount = $item_amount * $exchange_rate;

    // Check if it's a PayPal request or response
    if (!isset($_POST["txn_id"]) && !isset($_POST["txn_type"])) {
        $querystring = '';

        // Append PayPal account to querystring
        $querystring .= "?business=" . urlencode($paypal_email) . "&";

        // Append amount and currency to querystring
        $querystring .= "item_name=" . urlencode($item_name) . "&";
        $querystring .= "amount=" . urlencode($usd_amount) . "&";
        $querystring .= "currency_code=USD&"; // Specify that the amount is in USD
        $querystring .= "item_number=" . urlencode($item_number) . "&";

        // Loop for posted values and append to querystring
        foreach ($_POST as $key => $value) {
            $value = urlencode(stripslashes($value));
            $querystring .= "$key=$value&";
        }

        // Append PayPal return addresses
        $querystring .= "return=" . urlencode($return_url) . "&";
        $querystring .= "cancel_return=" . urlencode($cancel_url) . "&";
        $querystring .= "notify_url=" . urlencode($notify_url);

        // Insert payment information into the database
        $statement = $pdo->prepare("INSERT INTO tbl_payment (
                    customer_id,
                    customer_name,
                    customer_email,
                    payment_date,
                    txnid, 
                    paid_amount,
                    card_number,
                    card_cvv,
                    card_month,
                    card_year,
                    bank_transaction_info,
                    payment_method,
                    payment_status,
                    shipping_status,
                    payment_id
                    ) 
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $sql = $statement->execute(array(
                    isset($_SESSION['customer']['cust_id']) ? $_SESSION['customer']['cust_id'] : '',
                    isset($_SESSION['customer']['cust_name']) ? $_SESSION['customer']['cust_name'] : '',
                    isset($_SESSION['customer']['cust_email']) ? $_SESSION['customer']['cust_email'] : '',
                    $payment_date,
                    '',
                    $item_amount,
                    '',
                    '',
                    '',
                    '',
                    '',
                    'PayPal',
                    'Pending',
                    'Pending',
                    $item_number
                ));

        // Initialize arrays from session variables
        $arr_cart_p_id = isset($_SESSION['cart_p_id']) ? $_SESSION['cart_p_id'] : array();
        $arr_cart_p_name = isset($_SESSION['cart_p_name']) ? $_SESSION['cart_p_name'] : array();
        $arr_cart_size_name = isset($_SESSION['cart_size_name']) ? $_SESSION['cart_size_name'] : array();
        $arr_cart_color_name = isset($_SESSION['cart_color_name']) ? $_SESSION['cart_color_name'] : array();
        $arr_cart_p_qty = isset($_SESSION['cart_p_qty']) ? $_SESSION['cart_p_qty'] : array();
        $arr_cart_p_current_price = isset($_SESSION['cart_p_current_price']) ? $_SESSION['cart_p_current_price'] : array();

        // Fetch products from the database
        $statement = $pdo->prepare("SELECT * FROM tbl_product");
        $statement->execute();
        $result = $statement->fetchAll(PDO::FETCH_ASSOC);
        $arr_p_id = $arr_p_qty = array();
        foreach ($result as $row) {
            $arr_p_id[] = $row['p_id'];
            $arr_p_qty[] = $row['p_qty'];
        }

        // Check if 'color' column exists in tbl_order
        $checkColumn = $pdo->prepare("SHOW COLUMNS FROM tbl_order LIKE 'color'");
        $checkColumn->execute();
        $columnExists = $checkColumn->fetch(PDO::FETCH_ASSOC);

        // Insert order details and update stock
        for ($i = 0; $i < count($arr_cart_p_name); $i++) {
            if ($columnExists) {
                // Column 'color' exists
                $statement = $pdo->prepare("INSERT INTO tbl_order (
                            product_id,
                            product_name,
                            size, 
                            color,
                            quantity, 
                            unit_price, 
                            payment_id
                            ) 
                            VALUES (?,?,?,?,?,?,?)");
                $sql = $statement->execute(array(
                            isset($arr_cart_p_id[$i]) ? $arr_cart_p_id[$i] : '',
                            isset($arr_cart_p_name[$i]) ? $arr_cart_p_name[$i] : '',
                            isset($arr_cart_size_name[$i]) ? $arr_cart_size_name[$i] : '',
                            isset($arr_cart_color_name[$i]) ? $arr_cart_color_name[$i] : '',
                            isset($arr_cart_p_qty[$i]) ? $arr_cart_p_qty[$i] : 0,
                            isset($arr_cart_p_current_price[$i]) ? $arr_cart_p_current_price[$i] : 0,
                            $item_number
                        ));
            } else {
                // Column 'color' does not exist
                $statement = $pdo->prepare("INSERT INTO tbl_order (
                            product_id,
                            product_name,
                            size, 
                            quantity, 
                            unit_price, 
                            payment_id
                            ) 
                            VALUES (?,?,?,?,?,?)");
                $sql = $statement->execute(array(
                            isset($arr_cart_p_id[$i]) ? $arr_cart_p_id[$i] : '',
                            isset($arr_cart_p_name[$i]) ? $arr_cart_p_name[$i] : '',
                            isset($arr_cart_size_name[$i]) ? $arr_cart_size_name[$i] : '',
                            isset($arr_cart_p_qty[$i]) ? $arr_cart_p_qty[$i] : 0,
                            isset($arr_cart_p_current_price[$i]) ? $arr_cart_p_current_price[$i] : 0,
                            $item_number
                        ));
            }

            // Update stock
            for ($j = 0; $j < count($arr_p_id); $j++) {
                if (isset($arr_cart_p_id[$i]) && $arr_p_id[$j] == $arr_cart_p_id[$i]) {
                    $current_qty = $arr_p_qty[$j];
                    $final_quantity = $current_qty - (isset($arr_cart_p_qty[$i]) ? $arr_cart_p_qty[$i] : 0);
                    $statement = $pdo->prepare("UPDATE tbl_product SET p_qty=? WHERE p_id=?");
                    $statement->execute(array($final_quantity, $arr_cart_p_id[$i]));
                    break;
                }
            }
        }

        // Clear session variables
        unset($_SESSION['cart_p_id']);
        unset($_SESSION['cart_size_id']);
        unset($_SESSION['cart_size_name']);
        unset($_SESSION['cart_color_id']);
        unset($_SESSION['cart_color_name']);
        unset($_SESSION['cart_p_qty']);
        unset($_SESSION['cart_p_current_price']);
        unset($_SESSION['cart_p_name']);
        unset($_SESSION['cart_p_featured_photo']);

        if ($sql) {
            // Redirect to PayPal IPN
            header('Location: https://www.paypal.com/cgi-bin/webscr' . $querystring);
            exit();
        }
    } else {
        // Response from PayPal
    
        // Read the post from PayPal system and add 'cmd'
        $req = 'cmd=_notify-validate';
        foreach ($_POST as $key => $value) {
            $value = urlencode(stripslashes($value));
            $value = preg_replace('/(.*[^%^0^D])(%0A)(.*)/i', '${1}%0D%0A${3}', $value); // IPN fix
            $req .= "&$key=$value";
        }
    
        // Assign posted variables to local variables
        $data = [
            'item_name' => $_POST['item_name'] ?? '',
            'item_number' => $_POST['item_number'] ?? '',
            'payment_status' => $_POST['payment_status'] ?? '',
            'payment_amount' => $_POST['mc_gross'] ?? '',
            'payment_currency' => $_POST['mc_currency'] ?? '',
            'txn_id' => $_POST['txn_id'] ?? '',
            'receiver_email' => $_POST['receiver_email'] ?? '',
            'payer_email' => $_POST['payer_email'] ?? '',
        ];
    
        // Check the transaction ID to avoid duplicate entries
        $statement = $pdo->prepare("SELECT * FROM tbl_payment WHERE txnid=?");
        $statement->execute([$data['txn_id']]);
        $txn_exists = $statement->rowCount();
    
        if ($txn_exists) {
            // Transaction ID already exists, avoid duplicate entry
            exit();
        }
    
        // Verify the IPN with PayPal
        $paypal_url = "https://www.paypal.com/cgi-bin/webscr";
        $ch = curl_init($paypal_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
        $response = curl_exec($ch);
        curl_close($ch);
    
        if (strcmp($response, "VERIFIED") == 0) {
            // Check the payment status
            if ($data['payment_status'] == 'Completed') {
                // Insert the payment data into the database
                $statement = $pdo->prepare("UPDATE tbl_payment SET txnid=?, payment_status=?, payment_method=? WHERE payment_id=?");
                $sql = $statement->execute(array(
                    $data['txn_id'],
                    $data['payment_status'],
                    'PayPal',
                    $data['item_number']
                ));
            } elseif ($data['payment_status'] == 'Pending') {
                // Handle pending payments
                $statement = $pdo->prepare("UPDATE tbl_payment SET txnid=?, payment_status=?, payment_method=? WHERE payment_id=?");
                $sql = $statement->execute(array(
                    $data['txn_id'],
                    $data['payment_status'],
                    'PayPal',
                    $data['item_number']
                ));
            } else {
                // Handle other payment statuses if needed
            }
        } else {
            // Invalid IPN response
            // Log for manual investigation
        }
    }
} else {
    $error_message = "Unable to retrieve exchange rate. Please try again later.";
}
?>