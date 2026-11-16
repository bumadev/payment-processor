<?php

namespace App\UseCases\Payment;

use App\Exceptions\Payment\IdempotencyAmountMismatchException;
use App\Models\Payment;
use App\UseCases\Gateway\StripePaymentGateway;
use App\UseCases\Payment\SavePaymentIntent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SavePayment {

    private $intent;
    private $gateway;

    public function __construct() {
        $this->intent = new SavePaymentIntent();
        $this->gateway = new StripePaymentGateway();
    }

    public function execute($request) {
        try {
            $payment = DB::transaction(function () use ($request) {
                $model = new Payment();
                $model->merchant_id = $request->merchant_id;
                $model->idempotency_key = $request->idempotency_key;
                $model->payment_reference = 'pay_' . Str::ulid();
                $model->payment_status = "pending";
                $model->active_intent_id = null;
                $model->save();

                return $model;
            });

            return $this->processIntentAttempt($request, $payment);
        } catch (\Illuminate\Database\QueryException $e) {
            // 1062 = MySQL "Duplicate entry" (violación de unique key).
            $isDuplicate = ($e->errorInfo[1] ?? null) === 1062;
            $isIdempotencyKeyDuplicate = $isDuplicate
                && str_contains($e->getMessage(), 'unique_merchant_id_idempotency_key');

            // Si el duplicado es por idempotency_key, devolvemos el pago ya existente.
            if ($isIdempotencyKeyDuplicate) {
                $payment = Payment::where('merchant_id', $request->merchant_id)
                    ->where('idempotency_key', $request->idempotency_key)
                    ->first();

                if ($payment->payment_status === 'succeeded') {
                    return [
                        'success' => true,
                        'payment_id' => $payment->id,
                    ];
                }

                // Intentamos procesar el intento de pago nuevamente.
                return $this->processIntentAttempt($request, $payment);
            }

            // Otro tipo de error de BD (otro constraint, FK, etc.), lo propagamos.
            throw new \Exception('Failed to save payment: ' . $e->getMessage(), 0, $e);
        } catch (IdempotencyAmountMismatchException $e) {
            // Se lanza desde processIntentAttempt() en el camino feliz (línea 35 de este método,
            // dentro de este mismo try). Se deja pasar tal cual, nunca envuelta en un error genérico.
            throw $e;
        } catch (\Exception $e) {
            throw new \Exception('Failed to save payment: ' . $e->getMessage(), 0, $e);
        }
    }

    private function processIntentAttempt($request, Payment $payment) {
        $request->payment_id = $payment->id;

        // IdempotencyAmountMismatchException se deja pasar tal cual — es información útil para
        // el cliente de la API, no debe convertirse en un "Failed to create payment intent: ..."
        // genérico. Cualquier otra excepción de $this->intent->execute() ya viene envuelta.
        $intentResult = $this->intent->execute($request);

        if (!$intentResult['success']) {
            return [
                'success' => true,
                'payment_id' => $payment->id,
            ];
        }

        // Procesamos el pago a través del gateway (Stripe).
        $this->gateway->processPayment($intentResult['intent']['id']);

        return [
            'success' => true,
            'payment_id' => $payment->id,
        ];
    }
}