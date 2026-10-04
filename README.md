<div align="center"><img alt="WHMCS-MercadoPago" src="https://marketplace.whmcs.com/product/6720/images/icon200-33f47d0aa2c9182c7307756fa9b0276f.png"></div>

# WHMCS-MercadoPago

Módulo de gateway de pago de WHMCS para integrar Mercado Pago

[![Twitter](https://img.shields.io/twitter/url?style=social&url=https%3A%2F%2Fgithub.com%2Ffedealvz%2FWHMCS-MercadoPago)](https://twitter.com/intent/tweet?text=M%C3%B3dulo%20de%20MercadoPago%20para%20WHMCS%20Open-Source%20%40fedealvz%20%F0%9F%91%89%F0%9F%8F%BC&url=https://github.com/fedealvz/WHMCS-MercadoPago)
[![WHMCS Marketplace](https://img.shields.io/badge/WHMCS-Marketplace-blue)](https://marketplace.whmcs.com/product/6720-mercadopago-gateway)
[![GitHub issues](https://img.shields.io/github/issues/fedealvz/WHMCS-MercadoPago)](https://github.com/fedealvz/WHMCS-MercadoPago/issues)
![GitHub forks](https://img.shields.io/github/forks/fedealvz/WHMCS-MercadoPago?style=social)
![GitHub stars](https://img.shields.io/github/stars/fedealvz/WHMCS-MercadoPago?style=social)
![GitHub watchers](https://img.shields.io/github/watchers/fedealvz/WHMCS-MercadoPago?style=social)

## Fork notes (SilverHost)

This fork tracks `fedealvz/WHMCS-MercadoPago` `main` and adds fixes needed for Chilean pesos (CLP)
and for reliability. Addon version: `17.3-silverhost.1`.

### Changes

- **Zero-decimal currencies (CLP):** the preference `unit_price` is always sent as a rounded integer.
  MercadoPago rejects decimal CLP amounts (`unit_price must be a integer`), which made prorated
  upgrade invoices (e.g. `27296.67`) unpayable. The list lives in `MercadopagoConfig::ZERO_DECIMAL_CURRENCIES`.
- **Payment reconciliation:** when the amount was rounded (zero-decimal currency, or the `truncado` /
  `redondeado` modes) and the paid amount is within one unit of the invoice balance, the exact balance is
  recorded so the invoice is marked Paid without a residual balance or credit. Larger differences are
  recorded as actually paid (previously `truncado` / `redondeado` accepted any underpayment).
- **`redondeado` mode** uses standard rounding instead of a `0.49` threshold.
- **Error handling:** if MercadoPago does not return an `init_point`, the error is written to the
  gateway log and the client sees a message instead of a dead payment button.
- **HTTP:** the access token is sent as an `Authorization: Bearer` header (no longer in the URL), with
  10s connect / 20s total timeouts.
- **Webhook:** non-payment notifications are ignored, duplicate notifications no longer try to queue
  the same payment twice, and a transient failure fetching the payment returns HTTP 500 so MercadoPago
  retries (permanent 4xx errors are logged and dropped).
- **Fixes:** failure/pending return URLs were swapped; PHP 8 undefined variable/key warnings;
  `Mostrar errores de MercadoPago` output is HTML-escaped.
- Only one gateway instance is kept: `mercadopago_1` (the `mercadopago_2..9` copies were removed).

### Tests

The tests run in a PHP 8.3 container and need no WHMCS install (WHMCS functions are stubbed and the
MercadoPago API is mocked):

```sh
tests/run.sh
```

### Deployment

Copy `gateways/*` to `<whmcs>/modules/gateways/` and `addons/mercadopago/*` to
`<whmcs>/modules/addons/mercadopago/`. Back up the existing files first. When upgrading an install that
has the `mercadopago_2..9` gateways but does not use them, delete those files from
`modules/gateways/` and `modules/gateways/callback/`.

The `AfterCronJob` queue processing (`procesarTodosRegistrosCallback`) is registered by the
`mercadopago` addon, so it only runs while that addon is activated.

## Características

- Soporte para múltiples cuentas de Mercado Pago en diferentes países
- Configuración de cada cuenta por separado en el apartado de gateways / pasarelas de pago de WHMCS
- Soporte para múltiples monedas
- Soporte para varios idiomas
- Opciones para seleccionar los métodos de pago permitidos en Mercado Pago
- Implementación de Callback (IPN) en tiempo real o en queue
- Compatible con WHMCS versión 8
- [Changelog](https://github.com/fedealvz/WHMCS-MercadoPago/releases)

## Aviso Legal

Este módulo es una remake que está basada en el módulo discontinuado de Bayresapp v17 (junio de 2021). He intentado mantenerlo lo más similar posible para que quienes lo usaban puedan seguir haciéndolo con este nuevo módulo.

Este módulo es libre y de código abierto. No cuenta con soporte comercial, versión paga ni opciones de donación.

Este módulo no pertenece ni es gestionado por Mercado Pago, y Mercado Pago no tiene ninguna relación de recomendación, control, revisión, patrocinio, aprobación, administración, garantía o aval con respecto a este módulo.

## Soporte Comunitario

Para reportar errores, sugerir mejoras o plantear dudas, utiliza la sección de Issues o Pull Requests en el repositorio original de GitHub. Eres libre de hacer un fork y modificar el módulo sin restricciones.

## Instalación

Sube o reemplaza los archivos en los directorios correspondientes de WHMCS (addon module y gateway). Si estás reemplazando el módulo de Bayresapp, todas las configuraciones y tablas de la base de datos se mantendrán intactas.

Si apareciera un error en la página principal del admin de WHMCS referido a un widget preexistente de Bayresapp, elimina dicho widget para resolverlo.

## ¡Apoya el Proyecto!

⭐️ *¿Te ha resultado útil este módulo?* Ayúdame a mejorarlo dejando una estrella en GitHub y una reseña en el [Marketplace de WHMCS](https://marketplace.whmcs.com/product/6720-mercadopago-gateway#reviews). Esto contribuye a que más personas conozcan y confíen en esta herramienta. ¡Gracias por tu apoyo!

## Autor

👨🏼‍💻️ [Federico Álvarez](https://federicoalvarez.net)
