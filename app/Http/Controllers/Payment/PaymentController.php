<?php

namespace App\Http\Controllers\Payment;

use App\Exceptions\Payment\IdempotencyAmountMismatchException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\SavePaymentRequest;
use App\UseCases\Payment\SavePayment;

class PaymentController extends Controller
{
    public function postSavePayment(SavePaymentRequest $request, SavePayment $useCase)
    {
        try {
            $response = $useCase->execute($request);
            return response()->json([
                'message' => 'Payment processed successfully.',
                'data' => $response,
            ], 201);
        } catch (IdempotencyAmountMismatchException $e) {
            return response()->json([
                'error' => 'A payment was already requested with this same request identifier, but for a different amount or currency. If you meant to retry the same payment, send the original amount again.',
            ], 409);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
