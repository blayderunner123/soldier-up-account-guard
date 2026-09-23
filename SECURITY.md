# Security policy

## Supported version

Security fixes are applied to the latest release line.

## Reporting a vulnerability

Please do not open a public issue for a suspected vulnerability. Use GitHub's private vulnerability reporting feature for this repository when available, or contact the project owner privately through the contact information on the associated portfolio site.

Include the affected version, reproduction steps, impact, and any suggested mitigation. Do not include real user credentials, verification codes, or personal data.

## Security design notes

- Verification codes are generated with a cryptographically secure random number generator and stored as WordPress password hashes.
- Public verification links include a per-account high-entropy access token so a predictable WordPress user ID alone cannot access account verification or email-correction actions.
- Verification POST actions require WordPress nonces, and administrator actions require both capability checks and nonces.
- Resends are protected by cooldown and rolling hourly limits; OTP attempts are bounded.
- Inputs are sanitized and rendered output is escaped with WordPress APIs.
