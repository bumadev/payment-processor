<?php

namespace App\UseCases\Gateway;

use App\Models\Account;
use App\Models\LedgerEntrie;
use App\Models\Payment;
use App\Models\PaymentIntent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StripePaymentGateway {

    private const STRIPE_CONNECT_TIMEOUT = 15;
    private const STRIPE_TIMEOUT = 30;

    public function __construct() {
        $curl = new \Stripe\HttpClient\CurlClient();
        $curl->setConnectTimeout(self::STRIPE_CONNECT_TIMEOUT);
        $curl->setTimeout(self::STRIPE_TIMEOUT);

        \Stripe\ApiRequestor::setHttpClient($curl);
    }

    private function getPaymentIntent($idPaymentIntent) {
        return PaymentIntent::select(
                'payment_intents.*',
                'payments.payment_reference',
                'payments.merchant_id'
            )
            ->join('payments', 'payment_intents.payment_id', '=', 'payments.id')
            ->where('payment_intents.id', $idPaymentIntent)
            ->first();
    }

    public function processPayment($idPaymentIntent) {
        // Obtenemos el intento de pago junto con la referencia pública del pago asociado.
        $paymentIntent = $this->getPaymentIntent($idPaymentIntent);

        if (!$paymentIntent) {
            throw new \Exception('Payment intent not found.');
        }

        // Optimización: evita la llamada a Stripe en el caso obvio; el UPDATE de abajo es la protección real contra condiciones de carrera
        if ($paymentIntent->payment_attempt_status !== 'pending') {
            return PaymentIntent::find($idPaymentIntent);
        }

        // Pasamos el intent a "processing" justo antes de llamar a la pasarela, fuera de cualquier transacción de BD.
        $updateStatusPaymentIntent = PaymentIntent::where('id', $idPaymentIntent)
            ->where('payment_attempt_status', 'pending')
            ->update(['payment_attempt_status' => 'processing']);

        // Usamos el update como candado para no procesar el mismo intento dos veces.
        if ($updateStatusPaymentIntent === 0) {
            // Otro proceso ya tomó este intento (ya no está en pending), no hacemos nada.
            // Retornamos el intento actualizado, no se confia en el $paymentIntent anterior.
            return PaymentIntent::find($idPaymentIntent);
        }

        // Creamos el pago en Stripe.
        $this->createPaymentStripe($paymentIntent);

        return PaymentIntent::find($idPaymentIntent);
    }

    public function createPaymentStripe(PaymentIntent $paymentIntent, bool $isReconciliation = false) {
        $stripe = new \Stripe\StripeClient(config('services.stripe.secret'));

        // Conversión segura a centavos: decimal(15,2) -> entero, sin arrastrar error de punto flotante.
        $amountCents = (int) round(((float) $paymentIntent->payment_amount) * 100);

        try {
            $paymentIntentGateway = $stripe->paymentIntents->create([
                'amount' => $amountCents,
                'currency' => $paymentIntent->payment_currency,
                'payment_method' => 'pm_card_visa', // token de tarjeta de PRUEBA (ver abajo)
                'confirm' => true,             // confirma el cobro en la misma llamada
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ],
                'metadata' => [
                    'payment_reference' => $paymentIntent->payment_reference,
                    'payment_intent_id' => $paymentIntent->id,
                ],
            ], [
                // Idempotency key propia hacia Stripe: si esta llamada se reintenta (ej. timeout de PHP),
                // Stripe devuelve el mismo resultado en vez de generar un segundo cargo.
                'idempotency_key' => 'payment_intent_' . $paymentIntent->id,
            ]);

            $this->resolveGatewayStatus($paymentIntent, $paymentIntentGateway);
        } catch (\Stripe\Exception\CardException $e) {
            // Rechazo real de la tarjeta: Stripe respondió, sabemos con certeza que no se cobró.
            $this->resolveFailed(
                $paymentIntent, $e->getError()->code ?? null, 
                $e->getError()->message ?? null, 
                $e->getError()->payment_intent->id ?? null
            );
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            // Timeout / error de red: no sabemos si Stripe procesó el cobro del otro lado.
            // Queda bloqueado a propósito hasta que la reconciliación consulte a Stripe con este mismo ID.
            $this->resolveUnknown($paymentIntent, 'api_connection_error', $e->getMessage());
        } catch (\Stripe\Exception\IdempotencyException $e) {
            // Stripe lanza esto en dos casos muy distintos:
            // 1) La request original con esta misma idempotency_key todavía está en vuelo — transitorio,
            //    se resuelve solo si reintentamos más tarde.
            // 2) Reusamos la misma idempotency_key con un payload distinto al que se registró la primera
            //    vez (ej. cambiamos algo en el create() entre el intento original y un reintento) — esto
            //    NUNCA se resuelve reintentando, porque Stripe siempre va a rechazar ese mismo payload.
            // No podemos distinguir cuál es cuál desde acá, así que seguimos bloqueando para
            // reconciliación (cubre el caso 1), pero si esto se repite DESDE la propia reconciliación,
            // es una señal fuerte de que es el caso 2 (un bug nuestro, no algo transitorio de Stripe).
            if ($isReconciliation) {
                Log::error("IdempotencyException en un reintento de reconciliación para payment_intent #{$paymentIntent->id}: {$e->getMessage()}. Si esto se repite, probablemente el payload del create() ya no coincide con el registrado para esta idempotency_key y nunca se va a resolver reintentando.");
            }

            $this->resolveUnknown($paymentIntent, 'idempotency_conflict', $e->getMessage());
        } catch (\Stripe\Exception\ApiErrorException $e) {
            if (($e->getHttpStatus() ?? 0) >= 500) {
                // Error del lado de Stripe (5xx): a diferencia de un 4xx, no sabemos si el cobro
                // se procesó antes de que su servidor fallara al responder. Misma ambigüedad que
                // ApiConnectionException/IdempotencyException: queda bloqueado para reconciliación,
                // no liberamos el candado asumiendo que no se cobró.
                $this->resolveUnknown($paymentIntent, 'stripe_server_error', $e->getMessage());
            } else {
                // 4xx: error de Stripe que nunca llegó a intentar el cobro (auth, rate limit,
                // request inválido). Stripe respondió, así que sabemos con certeza que no se cobró.
                $this->resolveFailed(
                    $paymentIntent, $e->getError()->code ?? 'stripe_api_error',
                    $e->getMessage()
                );
            }
        }
    }

    public function resolvePaymentStripe($idPaymentIntent) {
        $paymentIntent = $this->getPaymentIntent($idPaymentIntent);
        
        if (!$paymentIntent) {
            throw new \Exception('Payment intent not found.');
        }

        // Creamos el pago en Stripe. Es la reconciliación reintentando, no el flujo original.
        $this->createPaymentStripe($paymentIntent, isReconciliation: true);

        return $paymentIntent;
    }

    public function retrievePaymentStripe($idPaymentIntent) {
        $paymentIntent = $this->getPaymentIntent($idPaymentIntent);

        if (!$paymentIntent) {
            throw new \Exception('Payment intent not found.');
        }

        $stripe = new \Stripe\StripeClient(config('services.stripe.secret'));

        // Sin excepciones propias acá: si retrieve() falla (timeout, error de red, etc.), no
        // tenemos información nueva, así que no tocamos nada en nuestra BD. Dejamos que la
        // excepción se propague al llamador para que decida (loguear y reintentar en la próxima
        // corrida de reconciliación).
        $paymentIntentGateway = $stripe->paymentIntents->retrieve($paymentIntent->payment_gateway_transaction_id);

        $this->resolveGatewayStatus($paymentIntent, $paymentIntentGateway);
    }

    private function resolveGatewayStatus(PaymentIntent $paymentIntent, $paymentIntentGateway): void {
        if ($paymentIntentGateway->status === 'succeeded') {
            try {
                $this->resolveSucceeded($paymentIntent, $paymentIntentGateway->id);
            } catch (\Throwable $e) {
                // Stripe SÍ confirmó el cobro (no hay ambigüedad de su lado), pero no pudimos
                // persistirlo en nuestra BD (ledger, cuentas faltantes, error de conexión, etc.).
                // No podemos dejarlo invisible: queda "unknown" con el transaction id de Stripe
                // para que la auditoría/reconciliación lo revise y complete manualmente.
                $this->resolveUnknown(
                    $paymentIntent,
                    'post_success_persist_failed',
                    "Stripe confirmed payment intent {$paymentIntentGateway->id} as succeeded, but persisting the result failed: {$e->getMessage()}",
                    $paymentIntentGateway->id
                );
            }

            return;
        }

        // Stripe respondió sin lanzar excepción, pero con un status distinto de 'succeeded'.
        // OJO: esto NO siempre significa "no se cobró" — hay estados no terminales donde el
        // cobro sigue en curso y podría resolverse como succeeded más tarde sin que nosotros
        // hagamos nada. Decisión explícita por cada valor documentado de PaymentIntent.status:
        match ($paymentIntentGateway->status) {
            // Stripe confirma que el cobro NO se realizó: seguro liberar el candado.
            'requires_payment_method', 'canceled', 'requires_confirmation' => $this->resolveFailed(
                $paymentIntent,
                $paymentIntentGateway->last_payment_error->code ?? null,
                $paymentIntentGateway->last_payment_error->message
                    ?? "Payment not completed, status: {$paymentIntentGateway->status}"
            ),

            // Estado NO terminal: el cobro puede seguir resolviéndose (3DS pendiente, método
            // asíncrono en proceso, o autorizado esperando captura). Misma ambigüedad que
            // ApiConnectionException, solo que aquí Stripe sí respondió. Liberar el candado
            // aquí abriría la puerta a un doble cobro si esto termina en succeeded después.
            'requires_action', 'processing', 'requires_capture' => $this->resolveUnknown(
                $paymentIntent,
                'non_terminal_status',
                "Payment intent not yet resolved, status: {$paymentIntentGateway->status}"
            ),

            // Cualquier status no documentado/futuro: por seguridad, ambiguo, nunca fallo.
            default => $this->resolveUnknown(
                $paymentIntent,
                'unexpected_status',
                "Unexpected payment intent status: {$paymentIntentGateway->status}"
            ),
        };
    }

    private function resolveSucceeded(PaymentIntent $paymentIntent, string $paymentGatewayTransactionId): void {
        DB::transaction(function () use ($paymentIntent, $paymentGatewayTransactionId) {
            // Solo transicionamos si el intent sigue en un estado "en el aire" (processing/unknown).
            // Si otro proceso ya lo resolvió primero, este UPDATE afecta 0 filas y no pisamos nada.
            $claimed = PaymentIntent::where('id', $paymentIntent->id)
                ->whereIn('payment_attempt_status', ['processing', 'unknown'])
                ->update([
                    'payment_attempt_status' => 'succeeded',
                    'payment_gateway_transaction_id' => $paymentGatewayTransactionId,
                    'failure_code' => null,
                    'failure_message' => null,
                ]);

            if ($claimed === 0) {
                return;
            }

            // Terminal: se escribe succeeded en payments.payment_status. active_intent_id se deja
            // como está (queda como referencia de cuál intent lo logró) y nunca vuelve a liberarse.
            Payment::where('id', $paymentIntent->payment_id)->update([
                'payment_status' => 'succeeded',
            ]);

            // Asiento de doble entrada: débito a 'cash_pending' (dinero recibido) y crédito a la
            // 'merchant_balance' del merchant correspondiente (lo que le debemos), balanceados por
            // el mismo monto. operation_id (el PaymentIntent de Stripe) es la clave de idempotencia
            // real: el UNIQUE KEY (movement_type, operation_id, account_id) evita que este mismo
            // cargo de Stripe se registre dos veces en la misma cuenta.
            $cashPendingAccountId = Account::where('type', 'cash_pending')->value('id');
            $merchantBalanceAccountId = Account::where('type', 'merchant_balance')
                ->where('merchant_id', $paymentIntent->merchant_id)
                ->value('id');

            if (!$cashPendingAccountId || !$merchantBalanceAccountId) {
                throw new \Exception('Missing ledger accounts for merchant_id ' . $paymentIntent->merchant_id);
            }

            $source = 'stripe:' . $paymentGatewayTransactionId;

            $debit = new LedgerEntrie();
            $debit->account_id = $cashPendingAccountId;
            $debit->payment_id = $paymentIntent->payment_id;
            $debit->operation_id = $paymentGatewayTransactionId;
            $debit->type = 'debit';
            $debit->movement_type = 'payment';
            $debit->amount = $paymentIntent->payment_amount;
            $debit->source = $source;
            $debit->save();

            $credit = new LedgerEntrie();
            $credit->account_id = $merchantBalanceAccountId;
            $credit->payment_id = $paymentIntent->payment_id;
            $credit->operation_id = $paymentGatewayTransactionId;
            $credit->type = 'credit';
            $credit->movement_type = 'payment';
            $credit->amount = $paymentIntent->payment_amount;
            $credit->source = $source;
            $credit->save();
        });
    }

    private function resolveFailed(PaymentIntent $paymentIntent, ?string $failureCode, ?string $failureMessage, $paymentGatewayTransactionId = null): void {
        DB::transaction(function () use ($paymentIntent, $failureCode, $failureMessage, $paymentGatewayTransactionId) {
            // Solo transicionamos si el intent sigue en un estado "en el aire" (processing/unknown).
            // Si otro proceso ya lo resolvió primero, este UPDATE afecta 0 filas y no pisamos nada.
            $updateParameters = [
                'payment_attempt_status' => 'failed',
                'failure_code' => $failureCode,
                'failure_message' => $failureMessage,
            ];

            if ($paymentGatewayTransactionId) {
                // Actualizamos solo si paymentGatewayTransactionId esta definido.
                $updateParameters['payment_gateway_transaction_id'] = $paymentGatewayTransactionId;
            }

            $claimed = PaymentIntent::where('id', $paymentIntent->id)
                ->whereIn('payment_attempt_status', ['processing', 'unknown'])
                ->update($updateParameters);

            if ($claimed === 0) {
                // Si el estado es diferente de 'failed' logueamos para auditar.
                $paymentIntent = PaymentIntent::find($paymentIntent->id);
                if ($paymentIntent->payment_attempt_status !== 'failed') {
                    Log::error("Failed to resolve payment intent: {$paymentIntent->id}");
                }
                return;
            }

            // NOTA IMPORTANTE:
            // payments.payment_status se queda en 'pending' (nunca se escribe 'failed' ahí).
            // Liberamos el candado para permitir un nuevo intent.
            // Super importante validar que el active_intent_id del payment siga apuntando a este mismo intent.
            Payment::where('id', $paymentIntent->payment_id)
                ->where('active_intent_id', $paymentIntent->id)
                ->update([
                    'active_intent_id' => null,
                ]);
        });
    }

    private function resolveUnknown(PaymentIntent $paymentIntent, ?string $failureCode, ?string $failureMessage, ?string $paymentGatewayTransactionId = null): void {
        DB::transaction(function () use ($paymentIntent, $failureCode, $failureMessage, $paymentGatewayTransactionId) {
            $attributes = [
                'payment_attempt_status' => 'unknown',
                'failure_code' => $failureCode,
                'failure_message' => $failureMessage,
            ];

            // Solo se conoce cuando Stripe sí llegó a confirmar el cobro antes de que fallara la
            // persistencia (caso post-succeeded). En un ApiConnectionException nunca hubo respuesta,
            // así que se queda tal como estaba.
            if ($paymentGatewayTransactionId !== null) {
                $attributes['payment_gateway_transaction_id'] = $paymentGatewayTransactionId;
            }

            // Solo transicionamos si el intent sigue en un estado "en el aire" (processing/unknown).
            // Si otro proceso ya lo resolvió primero, este UPDATE afecta 0 filas y no pisamos nada.
            $claimed = PaymentIntent::where('id', $paymentIntent->id)
                ->whereIn('payment_attempt_status', ['processing', 'unknown'])
                ->update($attributes);

            if ($claimed === 0) {
                // Si el estado es diferente de 'unknown' logueamos para auditar.
                $paymentIntent = PaymentIntent::find($paymentIntent->id);
                if ($paymentIntent->payment_attempt_status !== 'unknown') {
                    Log::error("Failed to resolve payment intent: {$paymentIntent->id}");
                }
                return;
            }

            // NOTA IMPORTANTE:
            // payments.payment_status y active_intent_id NO se tocan: el candado se queda ocupado
            // a propósito. Solo la reconciliación (consultar a Stripe con este mismo ID/idempotency
            // key) debe decidir si esto termina en 'succeeded' o se libera como 'failed'.
            // Esto con la finalidad de evitar un doble cobro en caso de que Stripe haya procesado el pago.
        });
    }
}
