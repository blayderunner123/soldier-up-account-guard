=== Soldier-up Account Guard ===
Contributors: soldierupdesigns
Tags: otp, email verification, wordpress security, woocommerce, user registration
Requires at least: 6.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later

Configurable account verification, OTP onboarding, login protection, role-aware redirects, and account administration for WordPress and WooCommerce.

== Description ==

Soldier-up Account Guard provides a reusable account-verification layer for WordPress sites.

Highlights:
* Email OTP verification
* Verification-before-password workflow
* Native WordPress, WooCommerce, and WP-Admin user creation support
* Configurable verification rules and email branding
* Dynamic role discovery
* Per-role login redirects to WordPress default, home, any existing page, or a custom URL
* Verification status column in Users
* Resend verification and manual mark-verified admin actions
* Mail-failure monitoring and administrator alerts
* Scheduled cleanup of abandoned unverified accounts
* Built-in diagnostics, logs, and test email control
* WooCommerce is optional

== Installation ==

1. Upload the plugin ZIP from Plugins > Add New > Upload Plugin.
2. Activate Soldier-up Account Guard.
3. Open Account Guard in WP-Admin.
4. Review the automatically created Verify Account page.
5. Configure role exemptions, redirects, email identity, and verification rules.
6. Send a test email before production use.

== Changelog ==

= 1.0.2 =
* Added high-entropy verification-link access tokens so a user ID alone cannot reach account verification or email-correction actions.
* Added public-repository security and provenance documentation.

= 1.0.1 =
* Added General-tab Quick Start setup guide.
* Added one-click Create / Repair Verification Page control.
* Automatically migrates the legacy [l6_otp_verify] shortcode when found on activation.
* System Status now requires both a published verification page and [suag_verify] before reporting Ready.
* Added field-level explanations for verification, email, user-creation, and general settings.
* Added Edit/View links for the configured verification page.

= 1.0.0 =
* Initial production build.
