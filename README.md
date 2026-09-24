# Soldier-up Account Guard

Copyright © 2026 Jonathan R. Adcox

Original author: **Jonathan R. Adcox (Blayderunner123)**

Soldier-up Account Guard is a WordPress plugin for configurable account verification, OTP onboarding, login protection, and role-aware redirects. WooCommerce integration is supported but optional.

## Features

- Email OTP verification with configurable length, expiration, resend cooldown, and attempt limits
- Verification-before-password onboarding
- Coverage for native WordPress, WooCommerce, and administrator-created users
- Login blocking for unverified accounts
- Dynamic WordPress role discovery and per-role redirects
- Configurable email identity and templates
- Verification status and administrative actions in the Users screen
- Mail-failure monitoring, diagnostics, logs, and test-email support
- Scheduled cleanup of abandoned unverified accounts
- High-entropy verification-link tokens in addition to OTP and nonce validation

## Requirements

- WordPress 6.1 or later
- PHP 7.4 or later

## Installation

1. Download or build the plugin ZIP.
2. In WordPress, open **Plugins → Add New → Upload Plugin**.
3. Upload and activate the plugin.
4. Open **Account Guard** in WordPress administration.
5. Review or repair the generated verification page.
6. Configure verification rules, role exemptions, redirects, and email identity.
7. Send a test email before enabling the workflow for production users.

## Release

This repository began with the recovered production source for version 1.0.1. Version 1.0.2 adds publication security hardening. See [ARTIFACT-PROVENANCE.md](ARTIFACT-PROVENANCE.md) for provenance.

The plugin declares GPLv2-or-later licensing in its WordPress readme.

This license permits use, modification, forking, improvement, and redistribution under its terms. Modified versions need not retain project branding or imply endorsement by the original author.

## Security

See [SECURITY.md](SECURITY.md) for reporting guidance and implementation notes. Version 1.0.1 in this repository includes publication hardening that prevents a predictable WordPress user ID from being sufficient to reach account verification and email-correction actions.
