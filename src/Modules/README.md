# Shared Modules — Integration Point

Placeholder directory. Per `planning/00-portfolio/shared-architecture.md` and `build-sequencing-roadmap.md`, five backend modules are meant to be built **once** and reused across all five platforms:

1. Booking / Availability Engine
2. Escrow & Commission Engine
3. KYC / Verification Pipeline
4. Programmatic SEO Page Generator
5. Review & Dispute Resolution Console

This scaffold does not yet depend on a shared package (each controller currently talks to this platform's own database directly), consistent with `build-sequencing-roadmap.md`'s note that laundry.co.ke is built first specifically to prove out this core before other platforms depend on it.

Once a shared package exists (as a Composer package or a small internal HTTP service — see the deployment-strategy note in `build-sequencing-roadmap.md`), the controllers in `src/Controllers/` that currently contain inline `TODO` business logic (escrow release, KYC gating, matching) should delegate here instead of reimplementing that logic per platform.
