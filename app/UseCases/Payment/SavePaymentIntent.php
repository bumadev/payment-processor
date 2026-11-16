<?php

namespace App\UseCases\Payment;

use App\Exceptions\Payment\IdempotencyAmountMismatchException;
use App\Models\Payment;
use App\Models\PaymentIntent;
use Illuminate\Support\Facades\DB;

class SavePaymentIntent {

    public function execute($request) {
        try {
            // Validamos que el pago no tenga un intento activo antes de crear uno nuevo.
            $locked = Payment::where('id', $request->payment_id)
                ->whereNotNull('active_intent_id')
                ->exists();

            if ($locked) {
                return [
                    'success' => false,
                ];
            }

            // Si ya existe algún intento de este payment_id con monto/moneda distintos a los de
            // esta request, es un conflicto de idempotencia: la misma idempotency_key del cliente
            // se está reusando para una operación distinta. Nunca debe continuar en silencio.
            $hasMismatch = PaymentIntent::where('payment_id', $request->payment_id)
                ->where(function ($q) use ($request) {
                    $q->where('payment_amount', '!=', $request->payment_amount)
                        ->orWhere('payment_currency', '!=', $request->payment_currency ?? 'USD');
                })
                ->exists();

            if ($hasMismatch) {
                throw new IdempotencyAmountMismatchException(
                    "El payment_id {$request->payment_id} ya tiene un intento con monto/moneda distintos a los de esta request."
                );
            }

            // Iniciamos transacción.
            DB::beginTransaction();
            
            // Creamos el intento una vez validado que el pago no está siendo procesado por otro intento.
            $model = new PaymentIntent();
            $model->payment_id = $request->payment_id;
            $model->payment_attempt_status = "pending";
            $model->payment_amount = $request->payment_amount;
            $model->payment_gateway = $request->payment_gateway;
            $model->payment_currency = "USD";
            $model->payment_gateway_transaction_id = null;
            $model->failure_code = null;
            $model->failure_message = null;
            $model->save();

            // Actualizamos el pago con el intento activo, garantizando un solo intento activo.
            $activeIntent = Payment::where('id', $request->payment_id)
                ->whereNull('active_intent_id')
                ->update([
                    'active_intent_id' => $model->id,
                ]);

            if ($activeIntent === 0) {
                // Forzamos el rollback (del intent recién insertado) lanzando dentro de la transacción.
                DB::rollBack();
                return [
                    'success' => false,
                ];
            }

            // Transacción exitosa, confirmamos los cambios.
            DB::commit();

            return [
                'success' => true,
                'intent' => [
                    'id' => $model->id,
                ],
            ];
        } catch (IdempotencyAmountMismatchException $e) {
            // No es un error de BD ni de transacción (se lanza antes del beginTransaction), se
            // deja pasar tal cual para que el llamador lo distinga de un error genérico.
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new \Exception('Failed to create payment intent: ' . $e->getMessage(), 0, $e);
        }
    }
}