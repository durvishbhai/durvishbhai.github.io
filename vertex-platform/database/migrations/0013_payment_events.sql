-- Phase 8: the payment history also records a client's submission, a verifier's correction of the amount, and a rejection.
ALTER TABLE client_payment_events MODIFY event ENUM('recorded','claimed','verified','amount_corrected','rejected','refund','chargeback','reversed') NOT NULL;
