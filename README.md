# Lunar Crypto Payments

Crypto payment driver for [Lunar](https://lunarphp.io) — pay with crypto as a
human (wallet-signed on-chain transfer at checkout) or as an agent (x402
HTTP-402 payment challenge/response).

**Status: early spike.** Interface and settlement flow are sketched but not
implemented or tested. Not ready for production use.

## Design

Two entry points share one settlement core:

- `PaymentTypes\CryptoPaymentType` — implements Lunar's `PaymentTypeInterface`
  for human checkout, same pattern as `lunarphp/stripe`. The frontend has the
  shopper sign a transfer (e.g. USDC via EIP-3009) and posts it as cart data;
  `authorize()` settles it and creates the order.
- `X402\X402PaymentMiddleware` — wraps agent-facing routes. Not a cart/order
  checkout flow, so it does not implement `PaymentTypeInterface`; it's plain
  middleware that responds `402` with payment requirements, then settles a
  signed `X-PAYMENT` header on retry.
- `Actions\SettleOnChainPayment` — the shared verify/settle core both entry
  points call into (facilitator-based, EIP-3009-style signed transfers).
  Coinbase's public facilitator (`x402.org/facilitator`) is the default;
  PayAI's facilitator is the fallback. Both target Base/USDC first.

## Open questions before this is real

- How the x402 agent flow maps a priced route to a Lunar cart/order — the
  human flow always has a cart; a generic priced API endpoint may not.
- Refund path: requires a separate outbound on-chain transfer, not sketched
  yet.

## License

MIT
