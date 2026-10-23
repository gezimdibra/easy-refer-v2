# Easy Refer v2 (rebuild)

Production rebuild of Easy Refer (easy-refer.com), a QR-based referral platform for small businesses.
Customers scan a store QR code and flow through /refer; business owners manage everything from the /auth dashboard.

- Staging: easy-refer.com/new (the app is base-path agnostic: one config value sets the base URL, no hardcoded paths)
- Production: easy-refer.com (cutover only after full verification sign-off + owner approval)
- Integrations: Square (payments), Twilio (SMS)
- The /affiliate feature from v1 is dropped.

Development rules live in the project verification protocol (hidden_files/verification-protocol.md in the goal workspace):
independent verifier roles, both-directions sweeps, sign-off only on an unchanged, hash-identified build.
Every verified build is a tagged commit in this repository.
