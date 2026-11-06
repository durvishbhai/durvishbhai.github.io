-- Clients can send a new-project requirements form from their dashboard (source "client_portal"), and a payment request can say it is the ADVANCE payment:
-- when an advance payment on a project is verified, the project is confirmed (Ready to Start) automatically.
ALTER TABLE enquiries
    MODIFY source ENUM('website_requirements','contact_form','appointment_request','manual','referral_partner','client_portal') NOT NULL;

ALTER TABLE gateway_orders
    ADD COLUMN payment_role ENUM('advance','balance','other') NOT NULL DEFAULT 'other' AFTER flow;
