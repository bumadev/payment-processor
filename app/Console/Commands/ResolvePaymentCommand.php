<?php

namespace App\Console\Commands;

use App\Models\PaymentIntent;
use App\UseCases\Gateway\StripePaymentGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ResolvePaymentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:resolve-payment-command';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Se encarga de resolver los pagos pendientes en el sistema.';

    /**
     * Minutos sin actualizarse para considerar un intent "atascado" (unknown/processing/pending).
     * Mismo umbral para los tres: ninguno se toca mientras siga "fresco".
     */
    private int $staleMinutes = 5;
    private int $cutoffTime = 20;
    private array $statusesToResolve = ['unknown', 'processing', 'pending'];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $threshold = now()->subMinutes($this->staleMinutes);

        // Consultamos intentos de pagos pendientes por resolver su estado final.
        $stuckAttempts = PaymentIntent::whereIn('payment_attempt_status', $this->statusesToResolve)
            ->where('updated_at', '<', $threshold)
            ->whereNot(function ($q) {
                $q->whereIn('payment_attempt_status', ['unknown','processing'])
                    ->whereNull('payment_gateway_transaction_id')
                    ->where('created_at', '<', now()->subHours($this->cutoffTime));
            })
            ->orderBy('updated_at', 'ASC')
            ->limit(5)
            ->get();

        $this->info("Encontrados {$stuckAttempts->count()} payment_intents para reconciliar.");

        $gateway = new StripePaymentGateway();

        foreach ($stuckAttempts as $intent) {
            // El propio candado revalida updated_at < $threshold: al reclamar, este mismo UPDATE
            // bumpea updated_at (ON UPDATE CURRENT_TIMESTAMP), así que un segundo proceso con una
            // lista vieja ya no matchea esta condición y afecta 0 filas. Esto es lo único que
            // decide la carrera; no hace falta ningún candado/lease adicional.
            $claimed = PaymentIntent::where('id', $intent->id)
                ->where('payment_attempt_status', $intent->payment_attempt_status)
                ->where('updated_at', '<', $threshold)
                ->update([
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) continue; // Otro proceso ya reclamó este intento.

            try {
                if ($intent->payment_attempt_status === 'pending') {
                    // Los intentos con estado pending se pueden procesar directamente sin riesgo de doble cobro.
                    $gateway->processPayment($intent->id);
                    continue;
                }
                
                if (in_array($intent->payment_attempt_status, ['processing', 'unknown']) && is_null($intent->payment_gateway_transaction_id)) {
                    // Se intenta crear el pago identico en Stripe, si esta procesandose o se cobro
                    // Stripe devuelve el resultado sin cobrar de nuevo. (La idempotency_key es la clave)
                    $gateway->resolvePaymentStripe($intent->id);
                    continue;
                }

                if (in_array($intent->payment_attempt_status, ['processing', 'unknown']) && !is_null($intent->payment_gateway_transaction_id)) {
                    // Se intenta recuperar el pago en Stripe, si esta procesandose o se cobro
                    // Stripe devuelve el resultado sin cobrar de nuevo.
                    $gateway->retrievePaymentStripe($intent->id);
                    continue;
                }
            } catch (\Throwable $e) {
                Log::error("Error reconciliando payment_intent #{$intent->id} (payment_id={$intent->payment_id}, status={$intent->payment_attempt_status}): {$e->getMessage()}", [
                    'payment_intent_id' => $intent->id,
                    'payment_id' => $intent->payment_id,
                    'exception' => $e,
                ]);
            }
        }
    }
}
