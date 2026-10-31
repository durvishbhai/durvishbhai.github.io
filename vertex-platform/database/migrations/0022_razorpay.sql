-- Online payments move to Razorpay: the collection is a Razorpay UPI QR or a Payment Link. Existing columns are provider-neutral; add the QR image address Razorpay returns.
ALTER TABLE gateway_orders
    MODIFY flow VARCHAR(20) NOT NULL DEFAULT 'payment_link',
    ADD COLUMN qr_image_url VARCHAR(300) NULL AFTER qr_payload;
