# Lunar Crypto Payments

Crypto payment driver for [Lunar](https://lunarphp.io) — pay with crypto as a
human (wallet-signed on-chain transfer at checkout) or as an agent (x402
HTTP-402 payment challenge/response).

**Status: early build.** Settlement core is implemented against the x402
protocol v2 facilitator API and unit tested (mocked HTTP). Not yet run
end-to-end against a live checkout. Not ready for production use.

## Design

Both entry points settle into the same place — a Lunar cart becoming a
placed order — via one shared pipeline:

- `PaymentTypes\CryptoPaymentType` — implements Lunar's `PaymentTypeInterface`
  for human checkout, same pattern as `lunarphp/stripe`. The frontend has the
  shopper sign an EIP-3009 transfer (e.g. USDC) and posts the resulting x402
  `PaymentPayload` as cart data; `authorize()` delegates to
  `AuthorizeCryptoPayment`.
- `X402\X402PaymentMiddleware` — wraps a cart-checkout route for agent
  payments. Not a `PaymentTypeInterface` implementation — x402 is an HTTP 402
  challenge on a route, not something Lunar's checkout invokes — but it
  resolves a `Cart` via Laravel route-model binding (`{cart}` route
  parameter) and delegates to the exact same `AuthorizeCryptoPayment`. The
  consuming app is responsible for building/pricing the cart itself (its own
  product/checkout API) before ever hitting an x402-protected route — this
  middleware only owns "is this cart paid for", the same boundary
  `CryptoPaymentType` has for human checkout. Route example in the class
  docblock.
- `Actions\AuthorizeCryptoPayment` — the one place a signed payload becomes a
  placed order: resolves/creates the order for a cart, checks the
  `CryptoSettlement` idempotency guard, settles, and finalizes. Both entry
  points call this directly rather than duplicating it, so there's exactly
  one settle-then-record-then-finalize implementation to get right.
- `Actions\SettleOnChainPayment` — the facilitator verify/settle core.
  Calls a facilitator's `/verify` then `/settle` per the
  [x402 v2 spec](https://github.com/coinbase/x402/blob/main/specs/x402-specification-v2.md),
  trying `lunar-crypto.facilitator_order` in turn until one succeeds.
- `Actions\BuildPaymentRequirements` — turns any Lunar `Price` (a cart's or
  an order's `->total`) into an x402 `PaymentRequirements` object, via...
- `Actions\ConvertToAssetUnits` — ...which rescales that total from the store
  currency's minor unit (e.g. cents) to the asset's atomic unit (USDC = 6
  decimals). Refuses (rather than silently mispricing) if the store currency
  isn't the configured `pegged_currency` — no FX conversion is implemented.
- `Models\CryptoSettlement` — an audit-trail row written the instant a
  facilitator confirms settlement, before the order transaction/`placed_at`
  writes that follow it. On-chain settlement can't be undone; if those writes
  fail, this row is the proof funds already moved, and a retried attempt
  resumes from it instead of settling (and charging) again.

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

- Refund path: requires a separate outbound on-chain transfer, not sketched
  yet.
- CDP-authenticated facilitator support (JWT request signing) if/when someone
  wants the mainnet Coinbase option instead of PayAI.
- `CryptoPaymentType`, `AuthorizeCryptoPayment`, `BuildPaymentRequirements`,
  and `CryptoSettlement` have no test coverage yet — anything touching a
  Lunar `Cart`/`Order`/`Price`/`Currency` model needs `LunarServiceProvider`
  — and its own dependency chain (Blink, MediaLibrary, ActivityLog,
  NestedSet, Converter, per how `lunarphp/stripe`'s own test suite bootstraps
  it) — registered, plus Cart/Order factories and migrations that live only
  in the monorepo's internal test suite (`Lunar\Base\ModelManifestInterface`
  isn't bound without it — every Lunar model construction fails outside a
  booted provider, not just database access). Only `SettleOnChainPayment` and
  `ConvertToAssetUnits` (pure logic, no Eloquent) are unit tested so far.
- x402 agent flow → cart/order mapping (previously open) is resolved: the
  consuming app builds/prices the `Cart` via its own product API, then binds
  it to the `{cart}` route parameter on an x402-protected checkout-completion
  route. `X402PaymentMiddleware` and `CryptoPaymentType` both settle through
  `AuthorizeCryptoPayment`, so there's one code path for "cart becomes
  order," not two. What's still unresolved: whether a Lunar app has a public
  cart-building API at all by default (it doesn't ship one — that's a
  storefront/headless-API concern), so this middleware assumes the consuming
  app already has one.

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
