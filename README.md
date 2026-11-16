# Procesador de pagos

Motor de procesamiento de pagos sobre Stripe con contabilidad de doble entrada y reconciliación integrada. No es un wrapper delgado del SDK de Stripe: existe porque entre "el cliente pidió un pago" y "el dinero efectivamente se movió" hay una zona gris (timeouts, errores 5xx, respuestas que nunca llegan) donde la mayoría de las integraciones ingenuas terminan cobrando dos veces o perdiendo el rastro de un pago. Este proyecto está diseñado específicamente para no hacer eso.

## El problema real que resuelve

Cuando llamas a una pasarela de pago, hay tres resultados posibles, no dos:

1. **Se cobró.** Fácil.
2. **No se cobró.** También fácil — la pasarela te lo dijo explícitamente (tarjeta rechazada, request inválido).
3. **No sabes.** La conexión se cayó antes de la respuesta, el servidor de la pasarela tiró un 500, o tu propio proceso murió a mitad de la llamada. Aquí es donde la mayoría del código de pagos falla: trata el "no sé" igual que el "no se cobró", libera el candado de reintento, y reintenta — arriesgando un doble cobro real.

Todo el diseño de este proyecto gira alrededor de nunca colapsar el caso 3 en el caso 2.

## El modelo mental: Payment vs PaymentIntent

Dos tablas, dos preguntas distintas:

| | `payments` | `payment_intents` |
|---|---|---|
| Qué representa | La intención durable de cobrar | Un intento concreto de cobro |
| Estados | `pending`, `succeeded` (solo 2) | `pending`, `processing`, `succeeded`, `failed`, `unknown` (5) |
| Por qué | Un pago puede sobrevivir varios intentos fallidos. No existe un estado terminal negativo porque un intento fallido no mata al pago — abre la puerta al siguiente | Cada intento necesita la granularidad completa, incluyendo los estados ambiguos que importan para la reconciliación |

Un `payment` tiene como máximo **un intento activo a la vez**, controlado por `payments.active_intent_id`. Mientras ese campo no sea `NULL`, nadie puede crear un segundo intento para ese pago — es el candado central de todo el sistema.

## Cómo se evita el doble cobro, en una frase

**El candado se libera únicamente cuando hay certeza de que no se cobró; nunca cuando solo hay duda.**

- `CardException`, un 4xx de Stripe → certeza de que no se cobró → `failed`, candado liberado, se puede reintentar.
- Timeout, `ApiConnectionException`, un 5xx de Stripe, `IdempotencyException` → duda real → `unknown`, candado **sigue ocupado**. Nadie puede reintentar hasta que la reconciliación resuelva la duda consultando a Stripe con la misma clave de idempotencia que se usó la primera vez.

## Idempotencia — dos capas, no una

Es fácil confundirlas porque ambas se llaman "idempotency key", pero son mecanismos distintos:

1. **La del cliente** (`payments.idempotency_key`): protege contra que la misma request de tu API se procese dos veces. Si el cliente reintenta con el mismo monto, se le devuelve el pago existente. Si reintenta con un **monto o moneda distintos bajo la misma key**, el sistema lo rechaza explícitamente con `409 Conflict` — reusar una idempotency key para una operación distinta es un error del cliente, no una ambigüedad que el sistema deba adivinar.
2. **La de Stripe** (`'payment_intent_' . $paymentIntent->id`): protege contra que un mismo intento interno genere dos cargos reales si la llamada a Stripe se reintenta (por ejemplo, durante la reconciliación de un `unknown`).

## El ledger: contabilidad de doble entrada, inmutable

Cuando un intento llega a `succeeded`, se escriben **dos** filas en `ledger_entries` en la misma transacción que marca el pago como exitoso — nunca por separado:

- Débito a la cuenta `cash_pending` (dinero recibido).
- Crédito a la cuenta `merchant_balance` del comercio correspondiente (lo que se le debe).

La tabla no tiene `UPDATE` ni `DELETE` a propósito. Un asiento equivocado no se corrige editándolo — se corrige con un asiento nuevo que revierte el error, dejando el historial completo intacto. El `UNIQUE KEY (movement_type, operation_id, account_id)` es la última red de seguridad: ni un bug ni una condición de carrera pueden registrar el mismo cargo de Stripe dos veces en la misma cuenta.

## Reconciliación

Un comando (`php artisan app:resolve-payment-command`) barre periódicamente los intentos atascados — `unknown`, `processing` o `pending` que no se actualizan hace más de unos minutos — y los resuelve:

- `pending` sin tocar nunca Stripe → se reprocesa directo, es seguro porque nunca llegó a intentarse.
- `unknown`/`processing` sin `transaction_id` → se reintenta el mismo `create()` con la misma idempotency key de Stripe.
- `unknown`/`processing` con `transaction_id` → se consulta directo con `retrieve()`, sin generar ningún cargo nuevo.

El propio candado de reconciliación usa la misma técnica que todo lo demás en el proyecto: un `UPDATE ... WHERE` que revalida la condición de "atascado" al reclamar, así que si dos corridas del comando se solapan, solo una de las dos procesa cada intento.

## Estructura del proyecto

```
app/
├── Http/
│   ├── Controllers/Payment/PaymentController.php   # POST /api/payments
│   └── Requests/Payment/SavePaymentRequest.php       # Validación de entrada
├── UseCases/
│   ├── Payment/SavePayment.php                       # Orquesta la creación del pago
│   ├── Payment/SavePaymentIntent.php                 # Crea intentos, valida el candado e idempotencia
│   └── Gateway/StripePaymentGateway.php              # Toda la integración con Stripe
├── Exceptions/Payment/                               # Excepciones de dominio (no genéricas)
├── Console/Commands/ResolvePaymentCommand.php         # Reconciliación
└── Models/                                           # Payment, PaymentIntent, Account, LedgerEntrie, Merchant
```

## Instalación

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configura en `.env`:

```
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

STRIPE_KEY_SECRET=sk_test_...
STRIPE_KEY_PUBLIC=pk_test_...
```

Aplica el esquema (las tablas de dominio no están versionadas como migraciones de Laravel, se gestionan directo en BD; `database/sql/` tiene los ajustes puntuales de schema):

```bash
php artisan migrate
# aplicar los scripts en database/sql/ contra tu base de datos
php artisan db:seed --class=AccountSeeder
```

Levanta el servidor:

```bash
php artisan serve
```

## Probar un pago

```bash
curl -X POST http://127.0.0.1:8000/api/payments \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"merchant_id":1,"idempotency_key":"<uuid-unico>","payment_amount":10.00,"payment_gateway":"stripe"}'
```

En modo test de Stripe, el método de pago usado es `pm_card_visa` (siempre aprueba). Para simular un rechazo durante pruebas, se cambia temporalmente por `pm_card_visa_chargeDeclined`.

## Reconciliación manual

```bash
php artisan app:resolve-payment-command
```

Pensado para correr en un cron cada pocos minutos.

## Qué queda pendiente (honesto, no una lista de excusas)

- La validación de idempotencia (monto/moneda) no cubre el caso en que el pago ya está `succeeded` — hoy ese camino corta antes de validar.
- `ResolvePaymentCommand` procesa en lotes fijos de 5 por corrida vía cron; bajo una ráfaga real de incidentes (una caída de Stripe de varios minutos en hora pico) ese diseño tarda demasiado en drenar el backlog. En un escenario de alto volumen, el reemplazo natural es una cola con múltiples workers en vez de un barrido periódico.
- No hay webhooks de Stripe integrados todavía — toda la reconciliación es por polling activo, no reactiva a eventos.
