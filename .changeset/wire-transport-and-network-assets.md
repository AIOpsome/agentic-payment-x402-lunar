---
"agentic-payment-x402-lunar": minor
---

Implement spec-compliant x402 v2 wire transport headers (PAYMENT-REQUIRED, PAYMENT-SIGNATURE, PAYMENT-RESPONSE) and automatic per-network default asset resolution for Base Sepolia (fixes #21, #22).

`lunar-crypto.asset` now defaults to null and the USDC contract is resolved from whichever network is in play, so `LUNAR_CRYPTO_NETWORK` alone switches both human and agent checkout to testnet. An unknown network with no explicit asset is now a hard error instead of a silent Base mainnet fallback. Every 402 the x402 middleware returns carries a `PAYMENT-REQUIRED` header and an empty `{}` body — a client that read the requirements out of the 402 body must read the header instead.
