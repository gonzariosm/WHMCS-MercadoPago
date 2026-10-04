<?php
// Unit tests for the amount helpers. They need no WHMCS install:
//   docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/amounts_test.php
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require_once __DIR__ . "/../gateways/MercadoPago_Lib/mercadopago_config.php";

$failures = 0;
function check($description, $expected, $actual)
{
    global $failures;
    if ($expected === $actual) {
        echo "ok   - " . $description . "\n";
        return;
    }
    $failures++;
    echo "FAIL - " . $description . ": expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
}

// Preference amount (unit_price sent to MercadoPago).
check("CLP decimal amount is rounded up to an integer", 27297, MercadopagoConfig::getPreferenceAmount("27296.67", "CLP", "normal"));
check("CLP decimal amount is rounded down to an integer", 5454, MercadopagoConfig::getPreferenceAmount("5454.16", "CLP", "normal"));
check("CLP integer amount is unchanged", 58310, MercadopagoConfig::getPreferenceAmount("58310.00", "CLP", "normal"));
check("CLP half unit rounds up", 62354, MercadopagoConfig::getPreferenceAmount("62353.50", "CLP", "normal"));
check("CLP currency code is case-insensitive", 5454, MercadopagoConfig::getPreferenceAmount("5454.16", "clp", "normal"));
check("CLP is rounded in noverifica mode", 5454, MercadopagoConfig::getPreferenceAmount("5454.16", "CLP", "noverifica"));
check("USD keeps decimals in normal mode", 12.5, MercadopagoConfig::getPreferenceAmount("12.50", "USD", "normal"));
check("truncado mode drops the fraction", 485, MercadopagoConfig::getPreferenceAmount("485.58", "USD", "truncado"));
check("redondeado mode: 651.50 => 652", 652, MercadopagoConfig::getPreferenceAmount("651.50", "USD", "redondeado"));
check("redondeado mode: 651.29 => 651", 651, MercadopagoConfig::getPreferenceAmount("651.29", "USD", "redondeado"));
check("redondeado mode: 651.49 => 651 (no 0.49 threshold)", 651, MercadopagoConfig::getPreferenceAmount("651.49", "USD", "redondeado"));
check("CLP amount is encoded as a JSON integer", '{"unit_price":27297}', json_encode(array("unit_price" => MercadopagoConfig::getPreferenceAmount("27296.67", "CLP", "normal"))));

// Amount recorded in WHMCS for an approved payment.
check("CLP rounded-up payment settles the exact balance", 27296.67, MercadopagoConfig::getAmountToRecord(27297, "27296.67", "CLP", "normal"));
check("CLP rounded-down payment settles the exact balance", 5454.16, MercadopagoConfig::getAmountToRecord(5454, "5454.16", "CLP", "normal"));
check("CLP exact payment is recorded as paid", 58310.0, MercadopagoConfig::getAmountToRecord(58310, "58310.00", "CLP", "normal"));
check("CLP partial payment is recorded as paid", 20000.0, MercadopagoConfig::getAmountToRecord(20000, "27296.67", "CLP", "normal"));
check("USD payment is never adjusted in normal mode", 12.0, MercadopagoConfig::getAmountToRecord(12, "12.50", "USD", "normal"));
check("truncado mode settles a sub-unit difference", 485.58, MercadopagoConfig::getAmountToRecord(485, "485.58", "USD", "truncado"));
check("truncado mode records an underpayment as paid", 400.0, MercadopagoConfig::getAmountToRecord(400, "485.58", "USD", "truncado"));
check("redondeado mode settles a sub-unit difference", 651.5, MercadopagoConfig::getAmountToRecord(652, "651.50", "USD", "redondeado"));
check("noverifica mode always records the balance", 485.58, MercadopagoConfig::getAmountToRecord(10, "485.58", "USD", "noverifica"));

echo $failures === 0 ? "\nAll tests passed\n" : "\n" . $failures . " test(s) failed\n";
exit($failures === 0 ? 0 : 1);
