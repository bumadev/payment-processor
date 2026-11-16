-- Agrega operation_id: el id del PaymentIntent de Stripe que generó el movimiento
-- (ej. $paymentIntentGateway->id). Sirve como clave de idempotencia real del lado
-- de la pasarela, en vez de depender de payment_id (que es nuestro id interno).
--
-- Evita asientos duplicados en ledger_entries: no puede existir más de una fila
-- con la misma combinación (movement_type, operation_id, account_id).
--
-- Ej: la cuenta 'cash_pending' solo puede recibir UN débito movement_type='payment'
-- por cada operation_id de Stripe, y la 'merchant_balance' del merchant solo UN
-- crédito por el mismo operation_id. Protege contra reintentos/race conditions que
-- intenten escribir el mismo asiento dos veces para el mismo cargo de Stripe.
--
-- NOTA: operation_id es NULL para movimientos sin operación de pasarela asociada
-- (fee/refund/adjustment internos). MySQL no considera NULL = NULL en un UNIQUE
-- KEY, así que esos casos no quedan bloqueados por este constraint.

ALTER TABLE ledger_entries
    ADD COLUMN operation_id VARCHAR(60) DEFAULT NULL COMMENT 'ID de la operación en la pasarela (ej. PaymentIntent de Stripe) que originó el movimiento.' AFTER payment_id,
    ADD CONSTRAINT unique_ledger_entry UNIQUE (movement_type, operation_id, account_id);
