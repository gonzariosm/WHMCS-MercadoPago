<?php
// Minimal MercadoPago API mock for tests/link_test.php, served with `php -S`.
// It mimics the preferences endpoint, including its rejection of decimal CLP unit prices.
header("Content-Type: application/json");
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$auth = $_SERVER["HTTP_AUTHORIZATION"] ?? "";
if ($auth !== "Bearer TEST-TOKEN" || isset($_GET["access_token"])) {
    http_response_code(401);
    exit(json_encode(array("message" => "invalid access token")));
}
if ($path === "/slow") {
    sleep(30);
    exit("{}");
}
if ($path === "/checkout/preferences") {
    $body = json_decode(file_get_contents("php://input"), true);
    $item = $body["items"][0];
    if ($item["currency_id"] === "CLP" && !is_int($item["unit_price"])) {
        http_response_code(400);
        exit(json_encode(array("message" => "unit_price must be a integer", "error" => "invalid_items")));
    }
    http_response_code(201);
    exit(json_encode(array("id" => "pref-1", "init_point" => "https://www.mercadopago.cl/checkout/v1/redirect?pref_id=pref-1", "sandbox_init_point" => "https://sandbox.mercadopago.cl/checkout/v1/redirect?pref_id=pref-1", "echo" => $body)));
}
if (preg_match("#^/v1/payments/(\\d+)$#", $path, $match)) {
    if ($match[1] === "404") {
        http_response_code(404);
        exit(json_encode(array("message" => "Payment not found")));
    }
    exit(json_encode(array("id" => (int) $match[1], "status" => "approved", "transaction_amount" => 27297)));
}
http_response_code(404);
echo json_encode(array("message" => "not found"));
