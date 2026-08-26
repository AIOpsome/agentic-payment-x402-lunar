# Lunar Crypto Payments

Crypto payment driver for [Lunar](https://lunarphp.io) — pay with crypto as a
human (wallet-signed on-chain transfer at checkout) or as an agent (x402
HTTP-402 payment challenge/response).

**Status: early build.** Settlement core is implemented against the x402
protocol v2 facilitator API and unit tested (mocked HTTP). Not yet run
end-to-end against a live checkout. Not ready for production use.

## Design

Two entry points share one settlement core:

- `PaymentTypes\CryptoPaymentType` — implements Lunar's `PaymentTypeInterface`
  for human checkout, same pattern as `lunarphp/stripe`. The frontend has the
  shopper sign an EIP-3009 transfer (e.g. USDC) and posts the resulting x402
  `PaymentPayload` as cart data; `authorize()` settles it and creates the
  order.
- `X402\X402PaymentMiddleware` — wraps agent-facing routes. Not a cart/order
  checkout flow, so it does not implement `PaymentTypeInterface`; it's plain
  middleware that responds `402` with payment requirements, then settles a
  signed `X-PAYMENT` header on retry.
- `Actions\SettleOnChainPayment` — the shared verify/settle core both entry
  points call into. Calls a facilitator's `/verify` then `/settle` per the
  [x402 v2 spec](https://github.com/coinbase/x402/blob/main/specs/x402-specification-v2.md),
  trying `lunar-crypto.facilitator_order` in turn until one succeeds.
- `Models\CryptoSettlement` — an audit-trail row written the instant a
  facilitator confirms settlement, before the order transaction/`placed_at`
  writes that follow it. On-chain settlement can't be undone; if those writes
  fail, this row is the proof funds already moved, and a retried
  `authorize()` resumes from it instead of settling (and charging) again.
- `Actions\ConvertToAssetUnits` — rescales the order total from the store
  currency's minor unit (e.g. cents) to the asset's atomic unit (USDC = 6
  decimals) before it's sent to the facilitator. Refuses (rather than
  silently mispricing) if the store currency isn't the configured
  `pegged_currency` — no FX conversion is implemented.

### Facilitators

Verified live against each facilitator's `/supported` endpoint:

| Facilitator | Auth | Base mainnet | Base Sepolia (testnet) |
| --- | --- | --- | --- |
| **PayAI** (default) | none | ✅ | ✅ |
| Coinbase public (`x402.org/facilitator`) | none | ❌ | ✅ |
| Coinbase CDP-hosted | CDP API key/secret | ✅ | ✅ |

PayAI is the default in `facilitator_order` because it's the only
unauthenticated option that supports Base mainnet. Coinbase's free public
facilitator is configured but excluded from the default order — it only
settles testnet, so silently including it in a mainnet fallback chain would
be misleading. Coinbase's CDP-hosted facilitator supports mainnet but needs
authenticated (signed) requests; that signing isn't implemented yet
(`SettleOnChainPayment` throws `FacilitatorNotSupportedException` if you add
`coinbase_cdp` to the order before then) — see `config/crypto.php`.

## Open questions before this is real

- How the x402 agent flow maps a priced route to a Lunar cart/order — the
  human flow always has a cart; a generic priced API endpoint may not.
- Refund path: requires a separate outbound on-chain transfer, not sketched
  yet.
- CDP-authenticated facilitator support (JWT request signing) if/when someone
  wants the mainnet Coinbase option instead of PayAI.
- `CryptoPaymentType` and `CryptoSettlement` have no test coverage yet.
  `BaseModel` (which `CryptoSettlement` extends) requires
  `Lunar\Base\ModelManifestInterface`, which only exists once
  `LunarServiceProvider` — and its own dependency chain (Blink, MediaLibrary,
  ActivityLog, NestedSet, Converter, per how `lunarphp/stripe`'s own test
  suite bootstraps it) — is registered, plus Cart/Order factories and
  migrations that live only in the monorepo's internal test suite. Only
  `SettleOnChainPayment` (pure HTTP, no Eloquent) is unit tested so far.

## Prior art / lessons folded in

[`financedistrict-platform/saleor-agentic-commerce`](https://github.com/financedistrict-platform/saleor-agentic-commerce)
ships an equivalent x402/EIP-3009 stablecoin handler for Saleor (not
portable here — different stack). Their merged
[#65](https://github.com/financedistrict-platform/saleor-agentic-commerce/pull/65)
(SAC-2) found that settling on-chain and then writing the order can silently
lose the audit trail if the order write fails after money has already moved.
`CryptoSettlement` exists specifically to avoid that class of bug here.

A cross-repo edge-case audit (2026-08-26, see
[#3](https://github.com/wakqasahmed/lunar-crypto-payments/issues/3)) also
caught the same major/minor-unit mismatch that financedistrict-platform hit
in production (Saleor PR #34) — this package's order total was being sent to
the facilitator without converting from the store currency's minor unit to
the asset's atomic unit. Fixed by `ConvertToAssetUnits`.

## Testing

```
composer install
vendor/bin/pest
```

## License

MIT
