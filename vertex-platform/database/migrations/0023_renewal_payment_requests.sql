-- Provider price rises and online payment of renewals.
-- adjustment_minor: the total price increases added to a renewal after it was approved (already included in total_minor / invoice_amount_minor).
ALTER TABLE renewal_cycles
    ADD COLUMN adjustment_minor BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER total_minor;

-- A payment request can now be for a renewal cycle instead of a billing invoice (exactly one of the two).
ALTER TABLE gateway_orders
    MODIFY invoice_id BIGINT UNSIGNED NULL,
    ADD COLUMN renewal_cycle_id BIGINT UNSIGNED NULL AFTER invoice_id,
    ADD KEY idx_gateway_orders_cycle (renewal_cycle_id),
    ADD CONSTRAINT fk_gateway_orders_cycle FOREIGN KEY (renewal_cycle_id) REFERENCES renewal_cycles (id);
