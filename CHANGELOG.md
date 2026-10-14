# Release Notes

## [Unreleased](https://github.com/laravel/laravel/compare/v11.0.0...11.x)

- Mandal registration is now a yearly fee. Access is gated by `registration_paid_through`; existing paid mandals were given one year from their latest payment.
- Razorpay renewals open 30 days before expiry and add a year from the current expiry. A repeated verify of the same order no longer extends twice.
- iOS: Apple auto-renewable subscription (`mandal.registration.yearly`) with `POST /api/v1/payments/apple/verify` and the `POST /api/v1/payments/apple/webhook` notifications endpoint.
- `402 PAYMENT_REQUIRED` now includes `paidThrough` and `expired` details. Auth payloads include `registrationPaidThrough`, and `config/app` includes `appleIap`.

## [v11.0.0 (2023-02-17)](https://github.com/laravel/laravel/compare/v10.3.2...v11.0.0)

Laravel 11 includes a variety of changes to the application skeleton. Please consult the diff to see what's new.
