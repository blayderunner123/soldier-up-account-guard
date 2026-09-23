# Soldier-up Account Guard

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

This repository contains the recovered production source for version 1.0.1. See [ARTIFACT-PROVENANCE.md](ARTIFACT-PROVENANCE.md) for provenance.

The plugin declares GPLv2-or-later licensing in its WordPress readme.
