<?php
use Illuminate\Database\Capsule\Manager as Capsule;
require_once(__DIR__ . "/idioma.php");
class MercadopagoConfig
{
    // MercadoPago rejects decimal unit prices in these currencies ("unit_price must be a integer").
    const ZERO_DECIMAL_CURRENCIES = array("CLP");
    const HTTP_CONNECT_TIMEOUT = 10;
    const HTTP_TIMEOUT = 20;
    public $nombreModulo;
    public $modulo;
    public $lastApiError = "";
    public $lastApiHttpCode = 0;
    public function __construct($nombreModulo = "mercadopago",$modulo = "mercadopago")
    {
        $this->nombreModulo = $nombreModulo;
        $this->modulo = $modulo;
    }
    static function isZeroDecimalCurrency($currency)
    {
        return in_array(strtoupper((string) $currency), self::ZERO_DECIMAL_CURRENCIES, true);
    }
    // Amount sent to MercadoPago as the preference unit_price.
    static function getPreferenceAmount($amount, $currency, $mode)
    {
        $amount = (float) $amount;
        switch ($mode) {
            case "truncado":
                return (int) $amount;
            case "redondeado":
                return (int) round($amount);
        }
        if (self::isZeroDecimalCurrency($currency)) {
            return (int) round($amount);
        }
        return $amount;
    }
    // Amount recorded in WHMCS for an approved payment. Differences below one unit caused by
    // rounding the preference amount settle the invoice balance exactly (no residual balance or credit).
    static function getAmountToRecord($paidAmount, $invoiceBalance, $currency, $mode)
    {
        $paidAmount = (float) $paidAmount;
        $invoiceBalance = (float) $invoiceBalance;
        if ($mode == "noverifica") {
            return $invoiceBalance;
        }
        $amountWasRounded = in_array($mode, array("truncado", "redondeado"), true) || self::isZeroDecimalCurrency($currency);
        if ($amountWasRounded && abs($paidAmount - $invoiceBalance) < 1) {
            return $invoiceBalance;
        }
        return $paidAmount;
    }
    // Gateway log entry describing the difference between the amount charged by MercadoPago and the
    // amount recorded in WHMCS, or null when they match.
    static function getAdjustmentLog($invoiceId, $transactionId, $chargedAmount, $recordedAmount)
    {
        $adjustment = round((float) $recordedAmount - (float) $chargedAmount, 2);
        if ($adjustment == 0) {
            return null;
        }
        return array("invoiceid" => $invoiceId, "transaction" => $transactionId, "charged_in_mercadopago" => (float) $chargedAmount, "recorded_in_whmcs" => (float) $recordedAmount, "adjustment" => $adjustment);
    }
    // Performs a MercadoPago API request. Returns the decoded body, or null on failure (see $lastApiError).
    function requestMercadopago($url, $accessToken, $body = null)
    {
        $this->lastApiError = "";
        $this->lastApiHttpCode = 0;
        $headers = array("Authorization: Bearer " . $accessToken);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::HTTP_CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::HTTP_TIMEOUT);
        if ($body !== null) {
            $json = json_encode($body);
            $headers[] = "Content-Type: application/json";
            $headers[] = "Content-Length: " . strlen($json);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->lastApiHttpCode = $httpCode;
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($result === false) {
            $this->lastApiError = "cURL error: " . $curlError;
            return null;
        }
        $response = json_decode($result, true);
        if ($httpCode < 200 || $httpCode >= 300 || !is_array($response)) {
            $this->lastApiError = "HTTP " . $httpCode . ": " . substr((string) $result, 0, 1000);
            return null;
        }
        return $response;
    }
    function crearTablaCustomTransacciones()
    {
        $nombreTabla = "bapp_mercadopago";
        if (!WHMCS\Database\Capsule::schema()->hasTable($nombreTabla)) {
            try {
                WHMCS\Database\Capsule::schema()->create($nombreTabla, function ($table) {
                    $table->increments("id");
                    $table->string("transaccion")->unique();
                    $table->dateTime("momento");
                    $table->string("gateway");
                });
                return true;
            } catch(\Exception $ex) {
                throw new Exception("No se pudo crear la tabla de transacciones: " . $ex->getMessage());
            }
        }
        return false;
    }
    function eliminarTablaCustomTransacciones()
    {
        $nombreTabla = "bapp_mercadopago";
        if (WHMCS\Database\Capsule::schema()->hasTable($nombreTabla)) {
            try {
                WHMCS\Database\Capsule::schema()->dropIfExists($nombreTabla);
                return true;
            } catch(\Exception $ex) {
                throw new Exception("No se pudo eliminar la tabla de transacciones: " . $ex->getMessage());
            }
        }
        return false;
    }
    function checkIdioma()
    {
        $resultado = Capsule::table("tbladdonmodules")->where("module", "=", "mercadopago")->where("setting", "=", "idioma")->value("value");
        if (empty($resultado)) {
            $resultado = "ar";
        }
        return $resultado;
    }    
    function getMPLogo()
    {
        return "\r\n    <img src='data:image/png;base64,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'>\r\n    ";
    }      
    // Silverhost: one Mercado Pago-branded button (brand mark + label) instead of the loose logo
    // above a theme-coloured button. Styles are scoped to .mp-pay-btn and marked !important so the
    // client theme's generic payment button rules don't repaint it.
    function getMPButton($enlace, $texto)
    {
        $mark = "<svg viewBox='0 0 24 24' width='30' height='30' aria-hidden='true' focusable='false'><path fill='currentColor' d='M11.115 16.479a.93.927 0 0 1-.939-.886c-.002-.042-.006-.155-.103-.155-.04 0-.074.023-.113.059-.112.103-.254.206-.46.206a.816.814 0 0 1-.305-.066c-.535-.214-.542-.578-.521-.725.006-.038.007-.08-.02-.11l-.032-.03h-.034c-.027 0-.055.012-.093.039a.788.786 0 0 1-.454.16.7.699 0 0 1-.253-.05c-.708-.27-.65-.928-.617-1.126.005-.041-.005-.072-.03-.092l-.05-.04-.047.043a.728.726 0 0 1-.505.203.73.728 0 0 1-.732-.725c0-.4.328-.722.732-.722.364 0 .675.27.721.63l.026.195.11-.165c.01-.018.307-.46.852-.46.102 0 .21.016.316.05.434.13.508.52.519.68.008.094.075.1.09.1.037 0 .064-.024.083-.045a.746.744 0 0 1 .54-.225c.128 0 .263.03.402.09.69.293.379 1.158.374 1.167-.058.144-.061.207-.005.244l.027.013h.02c.03 0 .07-.014.134-.035.093-.032.235-.08.367-.08a.944.942 0 0 1 .94.93.936.934 0 0 1-.94.928zm7.302-4.171c-1.138-.98-3.768-3.24-4.481-3.77-.406-.302-.685-.462-.928-.533a1.559 1.554 0 0 0-.456-.07c-.182 0-.376.032-.58.095-.46.145-.918.505-1.362.854l-.023.018c-.414.324-.84.66-1.164.73a1.986 1.98 0 0 1-.43.049c-.362 0-.687-.104-.81-.258-.02-.025-.007-.066.04-.125l.008-.008 1-1.067c.783-.774 1.525-1.506 3.23-1.545h.085c1.062 0 2.12.469 2.24.524a7.03 7.03 0 0 0 3.056.724c1.076 0 2.188-.263 3.354-.795a9.135 9.11 0 0 0-.405-.317c-1.025.44-2.003.66-2.946.66-.962 0-1.925-.229-2.858-.68-.05-.022-1.22-.567-2.44-.57-.032 0-.065 0-.096.002-1.434.033-2.24.536-2.782.976-.528.013-.982.138-1.388.25-.361.1-.673.186-.979.185-.125 0-.35-.01-.37-.012-.35-.01-2.115-.437-3.518-.962-.143.1-.28.203-.415.31 1.466.593 3.25 1.053 3.812 1.089.157.01.323.027.491.027.372 0 .744-.103 1.104-.203.213-.059.446-.123.692-.17l-.196.194-1.017 1.087c-.08.08-.254.294-.14.557a.705.703 0 0 0 .268.292c.243.162.677.27 1.08.271.152 0 .297-.015.43-.044.427-.095.874-.448 1.349-.82.377-.296.913-.672 1.323-.782a1.494 1.49 0 0 1 .37-.05.611.61 0 0 1 .095.005c.27.034.533.125 1.003.472.835.62 4.531 3.815 4.566 3.846.002.002.238.203.22.537-.007.186-.11.352-.294.466a.902.9 0 0 1-.484.15.804.802 0 0 1-.428-.124c-.014-.01-1.28-1.157-1.746-1.543-.074-.06-.146-.115-.22-.115a.122.122 0 0 0-.096.045c-.073.09.01.212.105.294l1.48 1.47c.002 0 .184.17.204.395.012.244-.106.447-.35.606a.957.955 0 0 1-.526.171.766.764 0 0 1-.42-.127l-.214-.206a21.035 20.978 0 0 0-1.08-1.009c-.072-.058-.148-.112-.221-.112a.127.127 0 0 0-.094.038c-.033.037-.056.103.028.212a.698.696 0 0 0 .075.083l1.078 1.198c.01.01.222.26.024.511l-.038.048a1.18 1.178 0 0 1-.1.096c-.184.15-.43.164-.527.164a.8.798 0 0 1-.147-.012c-.106-.018-.178-.048-.212-.089l-.013-.013c-.06-.06-.602-.609-1.054-.98-.059-.05-.133-.11-.21-.11a.128.128 0 0 0-.096.042c-.09.096.044.24.1.293l.92 1.003a.204.204 0 0 1-.033.062c-.033.044-.144.155-.479.196a.91.907 0 0 1-.122.007c-.345 0-.712-.164-.902-.264a1.343 1.34 0 0 0 .13-.576 1.368 1.365 0 0 0-1.42-1.357c.024-.342-.025-.99-.697-1.274a1.455 1.452 0 0 0-.575-.125c-.146 0-.287.025-.42.075a1.153 1.15 0 0 0-.671-.564 1.52 1.515 0 0 0-.494-.085c-.28 0-.537.08-.767.242a1.168 1.165 0 0 0-.903-.43 1.173 1.17 0 0 0-.82.335c-.287-.217-1.425-.93-4.467-1.613a17.39 17.344 0 0 1-.692-.189 4.822 4.82 0 0 0-.077.494l.67.157c3.108.682 4.136 1.391 4.309 1.525a1.145 1.142 0 0 0-.09.442 1.16 1.158 0 0 0 1.378 1.132c.096.467.406.821.879 1.003a1.165 1.162 0 0 0 .415.08c.09 0 .179-.012.266-.034.086.22.282.493.722.668a1.233 1.23 0 0 0 .457.094c.122 0 .241-.022.355-.063a1.373 1.37 0 0 0 1.269.841c.37.002.726-.147.985-.41.221.121.688.341 1.163.341.06 0 .118-.002.175-.01.47-.059.689-.24.789-.382a.571.57 0 0 0 .048-.078c.11.032.234.058.373.058.255 0 .501-.086.75-.265.244-.174.418-.424.444-.637v-.01c.083.017.167.026.251.026.265 0 .527-.082.773-.242.48-.31.562-.715.554-.98a1.28 1.279 0 0 0 .978-.194 1.04 1.04 0 0 0 .502-.808 1.088 1.085 0 0 0-.16-.653c.804-.342 2.636-1.003 4.795-1.483a4.734 4.721 0 0 0-.067-.492 27.742 27.667 0 0 0-5.049 1.62zm5.123-.763c0 4.027-5.166 7.293-11.537 7.293-6.372 0-11.538-3.266-11.538-7.293 0-4.028 5.165-7.293 11.539-7.293 6.371 0 11.537 3.265 11.537 7.293zm.46.004c0-4.272-5.374-7.755-12-7.755S.002 7.277.002 11.55L0 12.004c0 4.533 4.695 8.203 11.999 8.203 7.347 0 12-3.67 12-8.204z'/></svg>";
        return "<style>"
            . "a.mp-pay-btn{display:inline-flex!important;align-items:center;justify-content:center;gap:10px;min-height:48px;padding:9px 22px 9px 16px!important;"
            . "background:#009EE3!important;border:1px solid #009EE3!important;border-radius:10px!important;color:#fff!important;"
            . "font-weight:600!important;font-size:1rem!important;line-height:1.2!important;text-decoration:none!important;box-shadow:none;transition:background-color .2s,box-shadow .2s}"
            . "a.mp-pay-btn:hover{background:#0087C2!important;border-color:#0087C2!important;box-shadow:0 6px 16px rgba(0,158,227,.28)}"
            . "a.mp-pay-btn:focus-visible{outline:3px solid #8AD4F5;outline-offset:2px}"
            . "a.mp-pay-btn svg{flex:none;display:block}"
            . "</style>"
            . "<a href='" . htmlspecialchars($enlace, ENT_QUOTES) . "' class='btn mp-pay-btn' aria-label='" . htmlspecialchars(strip_tags($texto), ENT_QUOTES) . " con Mercado Pago'>"
            . $mark . "<span>" . $texto . "</span></a>";
    }
    function getPreferenciaPago($accesstoken, $datos_mp, $prueba = false)
    {
        // Optional payer fields that getLinkPago does not collect.
        $datos_mp += array_fill_keys(array("comprador_telefono_codigodearea", "comprador_telefono_numero", "comprador_domicilio_codigopostal", "comprador_domicilio_calle", "comprador_domicilio_numero", "comprador_documento_numero", "comprador_documento_tipo"), "");
        $data = array("additional_info" => "", "auto_return" => $datos_mp["retorno"], "back_urls" => array("failure" => $datos_mp["url_fallo"], "pending" => $datos_mp["url_pendiente"], "success" => $datos_mp["url_exito"]), "binary_mode" => true, "merchant_account_id" => $datos_mp["merchant_account_id"], "processing_modes" => array($datos_mp["processing"]), "processing_mode" => $datos_mp["processing"], "external_reference" => $datos_mp["referencia"], "items" => array(array("id" => "", "currency_id" => $datos_mp["item_moneda"], "title" => $datos_mp["item_titulo"], "picture_url" => $datos_mp["item_imagen"], "description" => $datos_mp["item_descripcion"], "category_id" => "services", "quantity" => 1, "unit_price" => $datos_mp["item_precio"])), "notification_url" => $datos_mp["notification_url"], "payer" => array("phone" => array("area_code" => $datos_mp["comprador_telefono_codigodearea"], "number" => $datos_mp["comprador_telefono_numero"]), "address" => array("zip_code" => $datos_mp["comprador_domicilio_codigopostal"], "street_name" => $datos_mp["comprador_domicilio_calle"], "street_number" => $datos_mp["comprador_domicilio_numero"]), "identification" => array("number" => $datos_mp["comprador_documento_numero"], "type" => $datos_mp["comprador_documento_tipo"]), "email" => $datos_mp["comprador_email"], "name" => $datos_mp["comprador_nombre"], "surname" => $datos_mp["comprador_apellido"]), "payment_methods" => array("excluded_payment_types" => $datos_mp["exclusiones"]));
        return $this->requestMercadopago("https://api.mercadopago.com/checkout/preferences", $accesstoken, $data);
    }
    function getConfigModulo()
    {
        $modulo = $this->modulo;
        $nombre = $this->nombreModulo;
        $idioma = $this->checkIdioma();
        $configHeader = array("bh_xxx" => array("FriendlyName" => traduccion($idioma, "mpconfig_2"), "Description" => "<br><br><b><a href='https://github.com/fedealvz/WHMCS-MercadoPago' target='_blank'>https://github.com/fedealvz/WHMCS-MercadoPago</a></b><br><br><br>"), "FriendlyName" => array("Type" => "System", "Value" => $nombre), "bh_Access_Token" => array("FriendlyName" => "<b>Access Token</b>:", "Type" => "text", "Size" => "150", "Description" => "&nbsp;<a href='#' onclick='\$(\"#Client_id\").modal(\"show\");' class='btn btn-warning btn-xs'>" . traduccion($idioma, "mpconfig_4") . "</a><br>\r\n            " . traduccion($idioma, "mpconfig_5") . ":\r\n            <br><a href='https://www.mercadopago.com/mla/account/credentials' target='_blank' class='btn btn-info btn-xs'>Argentina</a>\r\n            &nbsp;<a href='https://www.mercadopago.com/mlb/account/credentials' target='_blank' class='btn btn-info btn-xs'>Brasil</a>\r\n            &nbsp;<a href='https://www.mercadopago.com/mlc/account/credentials' target='_blank' class='btn btn-info btn-xs'>Chile</a>\r\n            &nbsp;<a href='https://www.mercadopago.com/mco/account/credentials' target='_blank' class='btn btn-info btn-xs'>Colombia</a>\r\n            &nbsp;<a href='https://www.mercadopago.com/mlm/account/credentials' target='_blank' class='btn btn-info btn-xs'>México</a>\r\n            &nbsp;<a href='https://www.mercadopago.com/mpe/account/credentials' target='_blank' class='btn btn-info btn-xs'>Perú</a>\r\n            &nbsp;<a href='https://www.mercadopago.com/mlu/account/credentials' target='_blank' class='btn btn-info btn-xs'>Uruguay</a>\r\n            &nbsp;<a href='https://www.mercadopago.com/mlv/account/credentials' target='_blank' class='btn btn-info btn-xs'>Venezuela</a>\r\n\r\n\r\n\r\n            <div class='modal fade' id='Client_id' tabindex='-1' role='dialog' aria-labelledby='DeactivateGatewayLabel' aria-hidden='true'>\r\n                <div class='modal-dialog'>\r\n                    <div class='modal-content panel panel-primary'>\r\n                        <div id='modalDeactivateGatewayHeading' class='modal-header panel-heading'>\r\n                            <button type='button' class='close' data-dismiss='modal'> <span aria-hidden='true'>&times;</span> <span class='sr-only'>" . traduccion($idioma, "mpconfig_6") . "</span> </button>\r\n                            <h4 class='modal-title' id='DeactivateGatewayLabel'>" . traduccion($idioma, "mpconfig_4") . ": Access Token</h4>\r\n                        </div>\r\n                        <div id='modalDeactivateGatewayBody' class='modal-body panel-body'>\r\n                            <p>" . traduccion($idioma, "mpconfig_7") . "\r\n                            <br><span style='color:red'>" . traduccion($idioma, "mpconfig_8") . "</span></p>\r\n                        </div>\r\n                        <div id='modalDeactivateGatewayFooter' class='modal-footer panel-footer'>\r\n                            <button type='button' id='DeactivateGateway-Cancelar' class='btn btn-default' data-dismiss='modal'> " . traduccion($idioma, "mpconfig_6") . " </button>\r\n                        </div>\r\n                    </div>\r\n                </div>\r\n            </div>\t\r\n            "), "merchant_account_id" => array("FriendlyName" => "Merchant Account ID:", "Type" => "text", "Size" => "100", "Description" => "<br>" . traduccion($idioma, "mpconfig_67")), "processing" => array("FriendlyName" => "Processing Mode:", "Type" => "dropdown", "Options" => array("aggregator" => "Aggregator (Default)", "gateway" => "Gateway"), "Description" => traduccion($idioma, "mpconfig_68")), "useradmin" => array("FriendlyName" => "UserName:", "Type" => "text", "Size" => "100", "Description" => "<br>" . traduccion($idioma, "mpconfig_72")), "bh_success" => array("FriendlyName" => traduccion($idioma, "mpconfig_9") . ":", "Type" => "text", "Size" => "100", "Description" => "<br>" . traduccion($idioma, "mpconfig_12")), "bh_pending" => array("FriendlyName" => traduccion($idioma, "mpconfig_10") . ":", "Type" => "text", "Size" => "100", "Description" => "<br>" . traduccion($idioma, "mpconfig_12")), "bh_failure" => array("FriendlyName" => traduccion($idioma, "mpconfig_11") . ":", "Type" => "text", "Size" => "100", "Description" => "<br>" . traduccion($idioma, "mpconfig_12")), "bh_titulo" => array("FriendlyName" => "<span style='color:red'>" . traduccion($idioma, "mpconfig_13") . "</span>:", "Type" => "text", "Size" => "50", "Value" => "Factura", "Description" => " " . traduccion($idioma, "mpconfig_14")), "bh_texto" => array("FriendlyName" => "<span style='color:red'>" . traduccion($idioma, "mpconfig_15") . "</span>:", "Type" => "text", "Value" => traduccion($idioma, "mpconfig_16")), "color" => array("FriendlyName" => traduccion($idioma, "mpconfig_17") . ":", "Type" => "dropdown", "Options" => array("primary" => traduccion($idioma, "mpconfig_18"), "secondary" => traduccion($idioma, "mpconfig_19"), "success" => traduccion($idioma, "mpconfig_20"), "danger" => traduccion($idioma, "mpconfig_21"), "warning" => traduccion($idioma, "mpconfig_22"), "info" => traduccion($idioma, "mpconfig_23"), "light" => traduccion($idioma, "mpconfig_24"), "dark" => traduccion($idioma, "mpconfig_25"), "link" => traduccion($idioma, "mpconfig_26")), "Description" => traduccion($idioma, "mpconfig_27")), "bh_nota" => array("FriendlyName" => traduccion($idioma, "mpconfig_28") . ":", "Type" => "text", "Size" => "100", "Description" => "<br>" . traduccion($idioma, "mpconfig_29")), "bh_credit_card" => array("FriendlyName" => traduccion($idioma, "mpconfig_30") . ":", "Type" => "yesno", "Description" => traduccion($idioma, "mpconfig_31")), "bh_ticket" => array("FriendlyName" => traduccion($idioma, "mpconfig_32") . ":", "Type" => "yesno", "Description" => traduccion($idioma, "mpconfig_33")), "bh_atm" => array("FriendlyName" => traduccion($idioma, "mpconfig_34") . ":", "Type" => "yesno", "Description" => traduccion($idioma, "mpconfig_35")), "bh_debito" => array("FriendlyName" => traduccion($idioma, "mpconfig_36") . ":", "Type" => "yesno", "Description" => traduccion($idioma, "mpconfig_37")), "bh_prepaga" => array("FriendlyName" => traduccion($idioma, "mpconfig_38") . ":", "Type" => "yesno", "Description" => traduccion($idioma, "mpconfig_39")), "bh_banco" => array("FriendlyName" => traduccion($idioma, "mpconfig_40") . ":", "Type" => "yesno", "Description" => traduccion($idioma, "mpconfig_41")), "bh_comportamiento" => array("FriendlyName" => traduccion($idioma, "mpconfig_42") . ":", "Type" => "dropdown", "Options" => array("normal" => traduccion($idioma, "mpconfig_43"), "noverifica" => traduccion($idioma, "mpconfig_44"), "truncado" => traduccion($idioma, "mpconfig_45"), "redondeado" => traduccion($idioma, "mpconfig_46")), "Description" => traduccion($idioma, "mpconfig_47") . "<br>\r\n            <b>" . traduccion($idioma, "mpconfig_43") . "</b>: " . traduccion($idioma, "mpconfig_48") . "\r\n            <br><b>" . traduccion($idioma, "mpconfig_44") . "</b>: " . traduccion($idioma, "mpconfig_49") . "\r\n            <br><b>" . traduccion($idioma, "mpconfig_45") . "</b>: " . traduccion($idioma, "mpconfig_50") . "\r\n            <br><b>" . traduccion($idioma, "mpconfig_46") . "</b>: " . traduccion($idioma, "mpconfig_51")), "bh_error_mp" => array("FriendlyName" => traduccion($idioma, "mpconfig_52") . ":", "Type" => "yesno", "Description" => traduccion($idioma, "mpconfig_53")), "prueba" => array("FriendlyName" => traduccion($idioma, "mpconfig_54") . ":", "Type" => "yesno", "Description" => traduccion($idioma, "mpconfig_55")), "email" => array("FriendlyName" => traduccion($idioma, "mpconfig_70") . ":", "Type" => "text", "Size" => "100", "Description" => "<br>" . traduccion($idioma, "mpconfig_71")), "bh_modocolaprocesamiento" => array("FriendlyName" => traduccion($idioma, "mpconfig_77") . ":", "Type" => "yesno", "Description" => traduccion($idioma, "mpconfig_78")));
        return $configHeader;
    }
    
    function getLinkPago($params)
    {
        $companyname = $params["companyname"];
        $systemurl = $params["systemurl"];
        $bh_texto = $params["bh_texto"];
        $bh_nota = $params["bh_nota"];
        $color = $params["color"];
        $nota = "";
        if (!empty($bh_nota)) {
            $nota = "<p><div class='small-text'>" . $bh_nota . "</div></p>";
        }
        $bh_credit_card = $params["bh_credit_card"];
        $bh_ticket = $params["bh_ticket"];
        $bh_atm = $params["bh_atm"];
        $bh_debito = $params["bh_debito"];
        $bh_prepaga = $params["bh_prepaga"];
        $bh_banco = $params["bh_banco"];
        $mediosno = array();
        if ($bh_credit_card == "on") {
            $mediosno[] = array("id" => "credit_card");
        }
        if ($bh_ticket == "on") {
            $mediosno[] = array("id" => "ticket");
        }
        if ($bh_banco == "on") {
            $mediosno[] = array("id" => "bank_transfer");
        }
        if ($bh_atm == "on") {
            $mediosno[] = array("id" => "atm");
        }
        if ($bh_debito == "on") {
            $mediosno[] = array("id" => "debit_card");
        }
        if ($bh_prepaga == "on") {
            $mediosno[] = array("id" => "prepaid_card");
        }
        $datos_mp["exclusiones"] = $mediosno;
        if ($params["prueba"] == "on") {
            $mododeprueba = true;
        } else {
            $mododeprueba = false;
        }
        $importe = self::getPreferenceAmount($params["amount"], $params["currency"], $params["bh_comportamiento"]);
        $datos_mp["item_precio"] = $importe;
        $accesstoken = $params["bh_Access_Token"];
        $datos_mp["retorno"] = "all";
        $bh_success = $params["bh_success"];
        $bh_pending = $params["bh_pending"];
        $bh_failure = $params["bh_failure"];
        $bh_error_mp = $params["bh_error_mp"];
        if (empty($bh_success)) {
            $bh_success = $systemurl . "viewinvoice.php?id=" . $params["invoiceid"];
        }
        if (empty($bh_pending)) {
            $bh_pending = $systemurl . "viewinvoice.php?id=" . $params["invoiceid"];
        }
        if (empty($bh_failure)) {
            $bh_failure = $systemurl . "viewinvoice.php?id=" . $params["invoiceid"];
        }
        $datos_mp["merchant_account_id"] = $params["merchant_account_id"];
        $datos_mp["processing"] = $params["processing"];
        $datos_mp["url_exito"] = $bh_success;
        $datos_mp["url_fallo"] = $bh_failure;
        $datos_mp["url_pendiente"] = $bh_pending;
        $datos_mp["notification_url"] = $systemurl . "modules/gateways/callback/" . $params["paymentmethod"] . "_ipn.php?source_news=webhooks";
        $datos_mp["referencia"] = $params["invoiceid"];
        $datos_mp["item_titulo"] = $companyname . " - " . $params["bh_titulo"] . " Nro. " . $params["invoiceid"];
        $datos_mp["item_imagen"] = "";
        $datos_mp["item_descripcion"] = $params["description"];
        $datos_mp["item_moneda"] = $params["currency"];
        $datos_mp["comprador_nombre"] = $params["clientdetails"]["firstname"];
        $datos_mp["comprador_apellido"] = $params["clientdetails"]["lastname"];
        $datos_mp["comprador_email"] = $params["clientdetails"]["email"];
        if (empty($accesstoken)) {
            $code = "Datos inválidos de Mercadopago";
        } else {
            $respuesta = $this->getPreferenciaPago($accesstoken, $datos_mp, $mododeprueba);
            $enlace = "";
            if (is_array($respuesta)) {
                $enlace = $mododeprueba ? ($respuesta["sandbox_init_point"] ?? "") : ($respuesta["init_point"] ?? "");
            }
            if ($params["bh_error_mp"] == "on") {
                $detalle = is_array($respuesta) ? print_r($respuesta, true) : $this->lastApiError;
                $code = "Respuesta Mercadopago<br><br><pre>" . htmlspecialchars($detalle) . "</pre>";
            } elseif (empty($enlace)) {
                $error = $this->lastApiError ?: "Response without init_point: " . substr(json_encode($respuesta), 0, 1000);
                logTransaction($params["name"], array("invoiceid" => $params["invoiceid"], "amount" => $params["amount"], "unit_price" => $importe, "currency" => $params["currency"], "error" => $error), "Preference creation failed");
                $code = "<div class='alert alert-warning'>" . traduccion($this->checkIdioma(), "mpconfig_79") . "</div>";
            } else {
                $code = "<script src='https://www.mercadopago.com/v2/security.js' view='item'></script>" . $this->getMPButton($enlace, $bh_texto) . $nota;
            }
        }
        return $code;
    }
    function mercadopagoIPN($gatewayOBJ)
    {
        $gatewayModule = $this->modulo;
        $informe = json_decode(file_get_contents("php://input"), true);
        $informe_cobro = $informe["data"]["id"] ?? "";
        $informe_action = $informe["action"] ?? "";
        $informe_id = $informe["id"] ?? "";
        $informe_type = $informe["type"] ?? "";
        $email = $gatewayOBJ["email"];
        $modoProcesamientoPorColas = $gatewayOBJ["bh_modocolaprocesamiento"] == "on";
        $adminUsername = "";
        $verificamail = false;
        $admin = $gatewayOBJ["useradmin"];
        if (!empty($admin)) {
            $adminUsername = $gatewayOBJ["useradmin"];
        }
        if (!empty($email)) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $verificamail = true;
            } else {
                $verificamail = false;
            }
        }
        if ($verificamail) {
            mail($email, $informe_id . " - Start", print_r($informe, true));
        }
        if (empty($informe_cobro)) {
            // Not a payment notification: nothing to process.
            return "200";
        }
        $command = "GetTransactions";
        $postData = array("transid" => $informe_cobro);
        $arr_transacciones = localAPI($command, $postData, $adminUsername);
        $yaEncolada = Capsule::table("bapp_mercadopago")->where("transaccion", "=", $informe_cobro)->exists();
        if ($arr_transacciones["totalresults"] == 0 && !$yaEncolada) {
            Capsule::table("bapp_mercadopago")->insert(array("transaccion" => $informe_cobro, "momento" => date("Y-m-d H:i:s"), "gateway" => $gatewayModule));
        }
        if (!$modoProcesamientoPorColas)
        {
            if (!$this->callbackMercadopago($informe_cobro)) {
                // The payment could not be fetched; a non-2xx status makes MercadoPago retry the notification.
                return "500 Internal Server Error";
            }
        }        
        $retorno = "200";
        return $retorno;
    }
    function getPaymentMercadopago($transaccion,$accessToken)
    {        
        return $this->requestMercadopago("https://api.mercadopago.com/v1/payments/" . rawurlencode($transaccion), $accessToken);
    }
    function callbackMercadopago($idtrans = "")
    {
        if (!empty($idtrans)) {
            $resultado = Capsule::table("bapp_mercadopago")->where("transaccion", "=", $idtrans)->first();
        } else {
            $resultado = Capsule::table("bapp_mercadopago")->first();
        }
        $mp_id = $resultado->id ?? null;
        $mp_transaccion = $resultado->transaccion ?? null;
        $mp_momento = $resultado->momento ?? null;
        $mp_gateway = $resultado->gateway ?? null;
        $adminUsername = "";
        $verificamail = false;
        $conversionlog = "";
        if (!empty($mp_id)) {
            $log = "ID: " . $mp_id . "<br>Tran: " . $mp_transaccion . "<br>Time: " . $mp_momento . "<br>Gat: " . $mp_gateway;
            $GATEWAY = getGatewayVariables($mp_gateway);
            $admin = $GATEWAY["useradmin"];
            if (!empty($admin)) {
                $adminUsername = $GATEWAY["useradmin"];
            }
            $command = "GetTransactions";
            $postData = array("transid" => $mp_transaccion);
            $arr_transacciones = localAPI($command, $postData, $adminUsername);
            if ($arr_transacciones["totalresults"] == 0) {
                $email = $GATEWAY["email"];
                if (!empty($email)) {
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $verificamail = true;
                    } else {
                        $verificamail = false;
                    }
                }
                if ($verificamail) {
                    mail($email, "IPN Trans: " . $mp_transaccion, $log);
                }
                $datosdelpago = $this->getPaymentMercadopago($mp_transaccion,$GATEWAY["bh_Access_Token"]);
                if ($verificamail) {
                    mail($email, "Search Trans. " . $mp_transaccion, print_r($datosdelpago, true));
                }
                if (empty($datosdelpago["status"])) {
                    logTransaction($GATEWAY["name"], array("transaction" => $mp_transaccion, "error" => $this->lastApiError), "Payment lookup failed");
                    $httpCode = $this->lastApiHttpCode;
                    if ($httpCode >= 400 && $httpCode < 500 && $httpCode != 429) {
                        // Permanent error (e.g. unknown payment): drop it so it does not block the queue.
                        Capsule::table("bapp_mercadopago")->where("id", "=", $mp_id)->delete();
                        return true;
                    }
                    // Transient error: keep the queue row so the payment is retried instead of being dropped.
                    return false;
                }
                $status = $datosdelpago["status"];
                $idioma = $this->checkIdioma();
                if ($status == "approved") {
                    $nrofactura = $datosdelpago["external_reference"];
                    if (!empty($nrofactura)) {
                        $valorAbonado = $datosdelpago["transaction_amount"];
                        $moneda_de_cobro = $datosdelpago["currency_id"];
                        $comision = $valorAbonado - $datosdelpago["transaction_details"]["net_received_amount"];
                        $command = "GetInvoice";
                        $postData = array("invoiceid" => $nrofactura);
                        $arr_datos_factura = localAPI($command, $postData, $adminUsername);
                        $datos_factura = print_r($arr_datos_factura, true);
                        $usuario_id = $arr_datos_factura["userid"];
                        $balance = $arr_datos_factura["balance"];
                        if ($GATEWAY["bh_comportamiento"] != "normal") {
                            $montoCobrado = $valorAbonado;
                            $importe_pagado = self::getAmountToRecord($valorAbonado, $balance, $moneda_de_cobro, $GATEWAY["bh_comportamiento"]);
                        } else {
                            $importe_pagado = $datosdelpago["transaction_amount"];
                            $command = "GetClientsDetails";
                            $postData = array("clientid" => $usuario_id);
                            $arr_datos_cliente = localAPI($command, $postData, $adminUsername);
                            $moneda_code_usuario = $arr_datos_cliente["currency_code"];
                            if ($moneda_de_cobro != $moneda_code_usuario) {
                                $command = "GetCurrencies";
                                $postData = array();
                                $arr_listademonedas = localAPI($command, $postData, $adminUsername);
                                $monedero = $arr_listademonedas["currencies"]["currency"];
                                foreach ($monedero as $datosmoneda) {
                                    $moneda_code = $datosmoneda["code"];
                                    $monedasporcode[$moneda_code] = $datosmoneda["rate"];
                                }
                                $tasadeconversion = $monedasporcode[$moneda_code_usuario];
                                $ximporte_pagado = round($importe_pagado * $tasadeconversion, 2);
                                $xcomision = round($comision * $tasadeconversion, 2);
                                $importe_pagado = $ximporte_pagado;
                                $comision = $xcomision;
                                $conversionlog = traduccion($idioma, "mpconfig_73") . ": " . $moneda_code_usuario . "\r\n                            " . traduccion($idioma, "mpconfig_74") . ": " . $importe_pagado . "\r\n                            " . traduccion($idioma, "mpconfig_75") . ": " . $comision;
                            }
                            $montoCobrado = $importe_pagado;
                            $importe_pagado = self::getAmountToRecord($importe_pagado, $balance, $moneda_code_usuario, "normal");
                        }
                        $ajuste = self::getAdjustmentLog($nrofactura, $mp_transaccion, $montoCobrado, $importe_pagado);
                        if ($ajuste !== null) {
                            logTransaction($GATEWAY["name"], $ajuste, "Rounding adjustment [" . $nrofactura . "]");
                        }
                        $command = "AddInvoicePayment";
                        $postData = array("gateway" => $GATEWAY["paymentmethod"], "invoiceid" => $nrofactura, "transid" => $mp_transaccion, "amount" => $importe_pagado, "fees" => $comision);
                        $results = localAPI($command, $postData, $adminUsername);
                        $texto_log = "\r\n                    " . traduccion($idioma, "mpconfig_56") . ": " . $nrofactura . "\r\n                    " . traduccion($idioma, "mpconfig_57") . ": " . $GATEWAY["name"] . "\r\n                    " . traduccion($idioma, "mpconfig_58") . ": " . $mp_transaccion . "\r\n                    " . traduccion($idioma, "mpconfig_60") . ": " . $datosdelpago["authorization_code"] . "\r\n                    " . traduccion($idioma, "mpconfig_61") . ": " . $datosdelpago["date_approved"] . "\r\n                    " . traduccion($idioma, "mpconfig_62") . ": " . $datosdelpago["payment_type_id"] . " - " . $datosdelpago["payment_method_id"] . "\r\n                    " . traduccion($idioma, "mpconfig_63") . ": " . $datosdelpago["currency_id"] . "\r\n                    " . traduccion($idioma, "mpconfig_64") . ": " . $datosdelpago["transaction_amount"] . "\r\n                    " . traduccion($idioma, "mpconfig_65") . ": " . $datosdelpago["transaction_details"]["net_received_amount"] . "\r\n                    " . $conversionlog;
                        logTransaction($GATEWAY["name"], $texto_log, traduccion($idioma, "mpconfig_66") . " [" . $nrofactura . "]");
                        //DETALLE DE LA API DE MP
                        logTransaction($GATEWAY["name"], "API Payment MP: " .  var_export($datosdelpago, true), "Detalle del pago aprobado.");
                        $resultado = Capsule::table("tbltransaction_history")->where("transaction_id", "=", $mp_transaccion)->delete();
                    }
                    $resultado = Capsule::table("bapp_mercadopago")->where("id", "=", $mp_id)->delete();
                } else {
                    if ($status == "pending") {
                        $invoiceId = $datosdelpago["external_reference"];
                        $texto_log = "\r\n                    " . traduccion($idioma, "mpconfig_56") . ": " . $datosdelpago["external_reference"] . "\r\n                    " . traduccion($idioma, "mpconfig_57") . ": " . $GATEWAY["name"] . "\r\n                    " . traduccion($idioma, "mpconfig_58") . ": " . $mp_transaccion . "\r\n                    " . traduccion($idioma, "mpconfig_62") . ": " . $datosdelpago["payment_type_id"] . " - " . $datosdelpago["payment_method_id"];
                        logTransaction($GATEWAY["name"], $texto_log, traduccion($idioma, "mpconfig_76") . " [" . $datosdelpago["external_reference"] . "]");
                        //DETALLE DE LA API DE MP
                        logTransaction($GATEWAY["name"], "API Payment MP: " .  var_export($datosdelpago, true), "Detalle del pago aprobado.");
                        Capsule::table("tbltransaction_history")->insert(array("invoice_id" => $datosdelpago["external_reference"], "gateway" => $GATEWAY["name"], "updated_at" => date("Y-m-d H:i:s"), "transaction_id" => $mp_transaccion, "remote_status" => traduccion($idioma, "mpconfig_76"), "description" => $datosdelpago["payment_type_id"] . " - " . $datosdelpago["payment_method_id"]));
                    }
                    $resultado = Capsule::table("bapp_mercadopago")->where("id", "=", $mp_id)->delete();
                }
            }
            else
            {
                //BORRAR PORQUE YA FUE INGRESADA LA TRANSACCION
                $resultado = Capsule::table("bapp_mercadopago")->where("id", "=", $mp_id)->delete();
            }
        }
        return true;
    }
    function procesarTodosRegistrosCallback($limiteRegistros = 10)
    {
        $resultado = Capsule::table("bapp_mercadopago")->limit($limiteRegistros)->get();
        foreach ($resultado as $registro) {
            $this->callbackMercadopago($registro->transaccion);
        }
    }
}
?>