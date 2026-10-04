<?php
// Integration tests for the payment button (getLinkPago) and the MercadoPago HTTP helper,
// using WHMCS stubs and the API mock in tests/mp_mock_server.php. Run with tests/run.sh.
namespace Illuminate\Database\Capsule {
    class Manager
    {
        public static function table($name)
        {
            return new \FakeQuery();
        }
    }
}

namespace {
    error_reporting(E_ALL);
    set_error_handler(function ($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    class FakeQuery
    {
        public function where(...$args)
        {
            return $this;
        }
        public function value($column)
        {
            return null;
        }
    }

    $transactionLog = array();
    function logTransaction($gateway, $data, $result)
    {
        global $transactionLog;
        $transactionLog[] = array("gateway" => $gateway, "data" => $data, "result" => $result);
    }

    require_once __DIR__ . "/../gateways/MercadoPago_Lib/mercadopago_config.php";

    $mockUrl = getenv("MP_MOCK_URL") ?: "http://127.0.0.1:8099";

    // Routes the hardcoded api.mercadopago.com URLs to the local mock.
    class MockedMercadopagoConfig extends MercadopagoConfig
    {
        public $lastBody;
        function requestMercadopago($url, $accessToken, $body = null)
        {
            global $mockUrl;
            $this->lastBody = $body;
            return parent::requestMercadopago(str_replace("https://api.mercadopago.com", $mockUrl, $url), $accessToken, $body);
        }
    }

    $failures = 0;
    function check($description, $condition, $detail = "")
    {
        global $failures;
        if ($condition) {
            echo "ok   - " . $description . "\n";
            return;
        }
        $failures++;
        echo "FAIL - " . $description . ($detail !== "" ? ": " . $detail : "") . "\n";
    }

    function invoiceParams($amount, $currency = "CLP")
    {
        return array(
            "name" => "MercadoPago", "paymentmethod" => "mercadopago_1", "companyname" => "SilverHost",
            "systemurl" => "https://clientes.example.test/", "invoiceid" => 9116, "amount" => $amount,
            "currency" => $currency, "description" => "Upgrade", "bh_Access_Token" => "TEST-TOKEN",
            "bh_comportamiento" => "normal", "bh_texto" => "Pagar", "bh_titulo" => "Factura", "bh_nota" => "",
            "color" => "primary", "prueba" => "", "bh_error_mp" => "", "processing" => "aggregator",
            "merchant_account_id" => "", "bh_success" => "", "bh_pending" => "https://clientes.example.test/pending",
            "bh_failure" => "https://clientes.example.test/failure", "bh_credit_card" => "", "bh_ticket" => "",
            "bh_atm" => "", "bh_debito" => "", "bh_prepaga" => "", "bh_banco" => "",
            "clientdetails" => array("firstname" => "Ana", "lastname" => "Perez", "email" => "ana@example.test"),
        );
    }

    // Decimal CLP invoices (prorated upgrades) get a working button.
    foreach (array("5454.16", "27296.67", "58310.00") as $amount) {
        $obj = new MockedMercadopagoConfig();
        $html = $obj->getLinkPago(invoiceParams($amount));
        check("CLP " . $amount . " renders a button with the init_point", strpos($html, "href='https://www.mercadopago.cl/checkout/v1/redirect?pref_id=pref-1'") !== false, $html);
        check("CLP " . $amount . " sends an integer unit_price", is_int($obj->lastBody["items"][0]["unit_price"]));
    }

    // Return URLs are no longer swapped.
    $obj = new MockedMercadopagoConfig();
    $obj->getLinkPago(invoiceParams("1000"));
    check("failure back_url is the failure URL", $obj->lastBody["back_urls"]["failure"] === "https://clientes.example.test/failure");
    check("pending back_url is the pending URL", $obj->lastBody["back_urls"]["pending"] === "https://clientes.example.test/pending");
    check("empty success URL defaults to the invoice page", $obj->lastBody["back_urls"]["success"] === "https://clientes.example.test/viewinvoice.php?id=9116");

    // A rejected preference shows a message and is logged instead of rendering a dead button.
    $transactionLog = array();
    $obj = new MockedMercadopagoConfig();
    $params = invoiceParams("12.50", "CLP");
    $params["bh_comportamiento"] = "noverifica";
    $params["bh_Access_Token"] = "WRONG-TOKEN";
    $html = $obj->getLinkPago($params);
    check("API error renders no payment link", strpos($html, "href=") === false, $html);
    check("API error renders the fallback message", strpos($html, "No pudimos iniciar el pago con MercadoPago") !== false, $html);
    check("API error is logged with the HTTP status", count($transactionLog) === 1 && strpos($transactionLog[0]["data"]["error"], "HTTP 401") === 0, print_r($transactionLog, true));
    check("logged error does not contain the access token", strpos(print_r($transactionLog, true), "WRONG-TOKEN") === false);

    // HTTP helper.
    $obj = new MercadopagoConfig();
    $response = $obj->requestMercadopago($mockUrl . "/v1/payments/123", "TEST-TOKEN");
    check("payment lookup uses the Bearer token", is_array($response) && $response["status"] === "approved", $obj->lastApiError);
    $response = $obj->requestMercadopago($mockUrl . "/v1/payments/404", "TEST-TOKEN");
    check("payment lookup 404 returns null with the status code", $response === null && $obj->lastApiHttpCode === 404, $obj->lastApiError);
    $response = $obj->requestMercadopago($mockUrl . "/checkout/preferences", "TEST-TOKEN", array("items" => array(array("currency_id" => "CLP", "unit_price" => 5454.16))));
    check("decimal CLP unit_price is rejected by the mock like the real API", $response === null && strpos($obj->lastApiError, "unit_price must be a integer") !== false, $obj->lastApiError);
    $start = microtime(true);
    $response = $obj->requestMercadopago($mockUrl . "/slow", "TEST-TOKEN");
    $elapsed = microtime(true) - $start;
    check("requests time out after " . MercadopagoConfig::HTTP_TIMEOUT . "s", $response === null && $elapsed < MercadopagoConfig::HTTP_TIMEOUT + 3 && strpos($obj->lastApiError, "cURL error") === 0, round($elapsed, 1) . "s " . $obj->lastApiError);
    $response = $obj->requestMercadopago("http://127.0.0.1:1/unreachable", "TEST-TOKEN");
    check("connection errors return null with a cURL error", $response === null && strpos($obj->lastApiError, "cURL error") === 0, $obj->lastApiError);

    echo $failures === 0 ? "\nAll tests passed\n" : "\n" . $failures . " test(s) failed\n";
    exit($failures === 0 ? 0 : 1);
}
