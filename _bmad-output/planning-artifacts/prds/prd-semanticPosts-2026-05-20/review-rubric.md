# PRD Quality Review — SemanticPosts

## Overall verdict

This is an unusually disciplined PRD for a 1-week MVP — features are tied to UJs, FRs carry testable consequences, the addendum carries the technical/rejected-alternative load that PRDs usually bloat with, and counter-metrics (SM-C1, SM-C2) actually counter something. What's at risk: the Pro-tier deferral (§12) and the host pre-approval gate (SM-3 / NFR-HOST-1) are both load-bearing but underspecified for "launch stakes" — Pro pricing is decorative in v1, but SM-3 is a hard launch gate with no fallback if WP Engine declines, and that's a single point of failure for the entire project premise. NFR-QUAL-1's "blind manual evaluation" is the most expensive-to-execute NFR in the document with no resourcing plan.

## Decision-readiness — adequate

The decision-maker (Leo, per §0) can act: scope is closed, Non-Goals are explicit, kill criterion is hard-numbered, Open Questions are actually open (not parked decisions). Trade-offs in §1 ("self-contained" vs. external-infra alternatives) are surfaced honestly — the addendum §A.1 even documents the abandoned "first" framing, which is rare candor.

What's smoothed: SM-3 ("at least 1 major managed host pre-approves... Hard launch gate"). This is treated as a deterministic milestone, but addendum §E.1 step 3 reveals it depends on cold-emailing engineering contacts at six hosts. If zero respond by launch date, what happens? The PRD doesn't say. Either the launch slips, the gate is downgraded, or the project ships ungated — none of those decisions are surfaced. Open Question #9 ("Who at WP Engine receives the outreach?") concedes the channel isn't even identified yet.

Open Question #10 isn't open — it's resolved ("Decision: acknowledge in marketing-site... do not treat as direct threats"). It should move to §1 or addendum §G, not occupy a slot in §8.

### Findings
- **high** SM-3 has no failure branch (§7, §9.2 NFR-HOST-1) — Single-point-of-failure milestone with no defined contingency. If no host pre-approves by launch, the PRD doesn't say what changes. *Fix:* add explicit fallback in §7: e.g., "If no host pre-approves by Day -7, launch without the host-compat-list claim and treat NFR-HOST-1 as Day-30 milestone instead."
- **medium** Open Question #10 is a resolved decision masquerading as open (§8) — "Decision: acknowledge in marketing-site competitive section but do not treat as direct threats" is a closed call, not a question. *Fix:* move to addendum §G or §1, freeing the slot for a real open question.
- **medium** Pro-tier deferral structure (§12) is provisional but presented with specifics ($49/$129, feature list) that aren't decision-bearing in v1 — risks anchoring future Pro work to numbers nobody committed to. *Fix:* either move all numbers to addendum §I (which already has them) or tag §12 explicitly with "[PROVISIONAL — revisited at Day 90]".

## Substance over theater — strong

No persona theater: §2 has one primary persona and one secondary, both load-bearing. Sarah is concretely sketched in addendum §D (hosting tier, income, tech comfort, time budget) and named in UJ-1. The secondary persona (agency operator) is explicitly called out as following Sarah's path — no decorative second persona that adds nothing.

No innovation theater: §1 and addendum §A.1 explicitly retract the "first plugin" claim and reframe to "self-contained." That's the opposite of innovation theater — it's anti-theater.

No vision theater: §1 paragraph 2 ("forced to pick between accurate-but-slow... and fast-but-dumb") is product-specific and couldn't be lifted into a different PRD without surgery.

NFR theater watch: §9 is mostly product-specific (TTFB delta vs. baseline on 5k-post install, exactly 2 added queries, autoload ≤1KB, etc.) — these are not boilerplate. One soft spot: NFR-PERF-6 ("Render performance independent of corpus size. Pageview cost is constant at 1k, 10k, 100k posts") — true by construction (postmeta lookup is O(1)) but stated without a measurement bound. Not a falsifiable test as written.

### Findings
- **low** NFR-PERF-6 (§9.1) is a tautology as worded — "constant" needs a measured tolerance (e.g., "TTFB delta variance across 1k/10k/100k corpora <2ms"). *Fix:* add tolerance bound or strike — NFR-PERF-1 already covers the substantive claim.

## Strategic coherence — strong

There is a thesis: "cheap at request time, accurate at index time, with nothing for a managed host to ban" (§1 paragraph 2). Every feature serves it. FR-1/FR-2/FR-3 (indexing) cover "accurate at index time." FR-4 (refresh tick) is the index-time precomputation. FR-6/FR-7/FR-8 (display) is the request-time-cheap path. FR-10 (observability) is the "nothing for a host to ban" audit surface, tied directly to UJ-5.

Success Metrics validate the thesis: SM-1/SM-2 measure distribution (the WP.org organic funnel hypothesis), SM-3 measures the host-compat positioning, SM-5 measures the performance promise. SM-C2 (cross-category rate 20–50%) is exactly the right counter — it would catch the failure mode where embeddings degrade to recency-weighted category matching.

The plugin isn't a list of capabilities — it's one architecture (precompute-then-cache) expressed across indexing, computation, display, and observability.

### Findings
*None at the strategic level. The arc is unified.*

## Done-ness clarity — adequate

Most FRs are testable: FR-1 names the hook (`save_post`), the storage (transient/queue entry), the retry policy (3 attempts, exponential backoff). FR-2 names batch size (50), pause (1s), memory threshold (80% of `WP_MEMORY_LIMIT`), corpus completion target (1k posts <2 min). FR-6 names the filter, the position, and the de-duplication behavior. FR-8's "Output HTML is identical for identical post IDs" is a sharp, testable claim tied to NFR-PERF-5.

Adjectives hiding numbers:
- FR-4 "completes in under 60 seconds on a $5/mo shared host" — `$5/mo shared host` is a category, not a spec. Which host? What PHP version, what concurrent load? Open Question #5 admits the 60s budget is unconfirmed; addendum §C.3 shows the naïve compute is ~125s, twice the target, with the gap closed only via optimizations not specified in the FR.
- NFR-QUAL-1 "blind manual evaluation" — by whom? How many evaluators? Inter-rater agreement threshold? "3 of 5 suggestions per post judged more relevant" is testable, but the evaluation protocol that produces those judgments is undefined.
- NFR-QUAL-3 "Zero 'completely unrelated' suggestions in manual review of 50 posts" — "completely unrelated" is the evaluator's judgment call; no rubric.
- §10.3 "host-compat constraint wins" — fine as a principle, but no example of what loses to what.

FR-9 API key storage is flagged as `[ASSUMPTION]` and punted to architecture — appropriate, but the FR-level Consequence "API key is stored encrypted" is non-testable until the scheme is pinned.

### Findings
- **high** NFR-QUAL-1 evaluation protocol undefined (§9.4) — "blind manual evaluation" by an unnamed evaluator pool with no inter-rater agreement check is the most expensive NFR in the doc and not executable as written. *Fix:* specify evaluator count (≥3), background (must be content-domain familiar or generic), rubric (e.g., "would this post be a reasonable next-read for someone who just finished the source post — yes/no"), agreement threshold (e.g., 2/3 evaluators agree).
- **medium** FR-4's 60-second budget conflicts with addendum §C.3's 125s naïve estimate (§4.2) — Open Question #5 acknowledges it's unconfirmed, but the FR Consequence states 60s as if it's a decision. *Fix:* either downgrade to "[TARGET — pending architecture-phase optimization choice]" or specify which optimization from §C.3 closes the gap.
- **medium** "$5/mo shared host" is not a spec (FR-2, FR-4, NFR-IDX-1) — needs a concrete reference environment (e.g., "1 vCPU, 1GB RAM, PHP 8.0, WP_MEMORY_LIMIT=256M, no opcache tuning"). *Fix:* add reference-environment definition to §13 or §9.5 and cross-reference.
- **low** NFR-QUAL-3 "completely unrelated" needs a rubric (§9.4) — "obvious failure" is sometimes obvious, sometimes not. *Fix:* give one or two examples of what counts (e.g., "different topic domain entirely — recipe blog suggesting tax software").

## Scope honesty — strong

Non-Goals (§5) is doing real work, not boilerplate. Each entry has a reason: "No external vector DB... loses its positioning," "No self-hosted models... API-only. Self-hosted is desired by a vocal minority but adds large surface area." The Mida +25% CTR opportunity is flagged as deferred with `[NOTE FOR PM]` — that's exactly where tension belongs.

§6.2 (Out of Scope for MVP) overlaps §5 (Non-Goals) but the duplication is purposeful: §5 is permanent non-goals (search engine, RAG layer), §6.2 is "not in this build" (Gutenberg block, multi-language). The distinction holds.

`[ASSUMPTION]` tags are indexed in §15. Roundtrip check:
- §15 #1 (slug) ↔ doc header line 9 ✓
- §15 #2 (empty-state UX) ↔ UJ-2 line 53 ✓
- §15 #3 (batch size 50) ↔ FR-2 line 104 ✓
- §15 #4 (refresh 60s) ↔ FR-4 line 136 ✓
- §15 #5 (auto-injection position) ↔ FR-6 line 161 ✓
- §15 #6 (API key encryption) ↔ FR-9 line 200 ✓
- §15 #7 (WP 6.0+ coverage) ↔ §13 line 417 — **inline tag missing.** §13 says "covers ~85% of active installs per WP.org stats — confirm at submission time" but lacks the `[ASSUMPTION]` marker. Index entry exists; inline marker absent.

Open Questions density (10 questions for a launch PRD) is appropriate — high enough to signal real openness, low enough to indicate the core is decided.

### Findings
- **low** §13 WP 6.0+ assumption lacks inline `[ASSUMPTION]` tag (§13) — Assumptions Index entry #7 points to §13, but §13 text doesn't carry the marker. Roundtrip half-broken. *Fix:* add `[ASSUMPTION: WP 6.0+ covers ~85% of active installs; verify at submission]` inline.

## Downstream usability — adequate

Glossary (§3) is present and the terms are used: "Embedding," "Related-post list," "Refresh tick," "Bulk index," "Indexed-eligible post," "Featured card," "Display surface," "Observability panel" all reappear in FRs and NFRs. "Embedding provider" is glossary-defined and used consistently.

Glossary drift check:
- "Embedding" appears in FR-1 ("embedding job"), §6.1 ("Embedding provider integration") — consistent.
- "Refresh tick" appears in FR-4, FR-5, UJ-2, §C.3 — consistent.
- "Featured card" appears in FR-8, UJ-1, UJ-3, §14 — consistent.
- "Indexed-eligible post" appears in FR-1, FR-2 — consistent.
- One drift: §1 says "served from `postmeta` as a single indexed query at render" but NFR-PERF-2 says "exactly 2 added queries per pageview — one `get_post_meta` for `_sp_related`, one `WP_Query` for the related post IDs." §1 collapses two queries into one phrase. Mild but worth tightening for marketing-site accuracy.

ID continuity:
- FR-1 through FR-12: contiguous ✓
- SM-1 through SM-6 + SM-C1, SM-C2: contiguous ✓
- UJ-1 through UJ-5: contiguous ✓
- NFR-* IDs: `NFR-PERF-1..6`, `NFR-HOST-1..5`, `NFR-IDX-1..6`, `NFR-QUAL-1..4`, `NFR-ON-1..4`, `NFR-WPORG-1..5`, `NFR-MKT-1..3` — all contiguous within their group ✓
- Anomaly: NFR-CAC referenced in FR-8 ("required for full-page cache compatibility (see NFR-CAC)") but **no NFR-CAC exists.** The intended reference is NFR-PERF-5 (page-cache compatible). Broken cross-reference.

UJs name personas: UJ-1 names "Sarah" (matches addendum §D persona). UJ-2 names "Marcus" — Marcus is NOT defined in §2.1 or addendum §D. He's a second named character introduced only in UJs, with no persona sketch. Either Marcus is Sarah (same profile, different name for narrative variety) or he's a separate persona who needs a sketch.

Cross-refs spot-checked:
- "see FR-10" in FR-2 → resolves ✓
- "see NFR-PERF" — none broken
- "see NFR-CAC" in FR-8 → **broken** (intended NFR-PERF-5)
- "Same as SM-3" in NFR-HOST-1 → resolves ✓
- "Same as SM-C2" in NFR-QUAL-2 → resolves ✓
- "Same as SM-6" in NFR-ON-1 → resolves ✓
- "see §2.3" in §10.2 → resolves ✓

Each section pulls out cleanly except §12 (Monetization) — it references SM-1, SM-2 by ID but the gating semantics ("Pro ships only if Day-90 milestones hit") require reading §7 to interpret.

### Findings
- **high** Broken cross-reference: FR-8 cites NFR-CAC, no such NFR exists (§4.3 FR-8) — intended target is NFR-PERF-5. Architecture/engineering reviewer will hunt for a missing NFR. *Fix:* change "(see NFR-CAC)" to "(see NFR-PERF-5)".
- **medium** Marcus (UJ-2) has no persona sketch (§2, UJ-2) — UJ-1 has Sarah (defined in §2.1 + addendum §D). UJ-2 introduces "Marcus" with no profile. Either he's a second persona (then sketch him) or he's Sarah-equivalent (then rename or merge). *Fix:* if Marcus is meant to differ from Sarah (e.g., higher publishing cadence), add a one-line sketch in §2.1 or addendum §D; if not, rename UJ-2 protagonist to Sarah for persona discipline.
- **low** §1 "single indexed query at render" vs. NFR-PERF-2 "exactly 2 added queries" (§1, §9.1) — marketing-adjacent phrasing collapses two queries to one. Won't confuse engineers but will confuse a host engineer reading §1 then auditing against NFR-PERF-2. *Fix:* §1 reads "served from `postmeta` with two indexed queries at render" — more honest, same positioning.

## Shape fit — strong

This is the right shape for a consumer WordPress plugin going to WP.org. UJs are load-bearing: UJ-1 (install path) ties to SM-6, NFR-ON-1, and FR-9; UJ-3 (reader click-through) ties to SM-2 (reviews would be the only signal that UJ-3 actually fires); UJ-5 (host audit) is uncommon-but-correct — it acknowledges that the managed-host engineer is effectively a second buyer whose buy-in is gated separately.

Personas are appropriately sized for a consumer product: one primary (Sarah), one secondary (agency operators), explicit non-users (§2.3). The Pro tier is deferred, which is correct — multi-stakeholder pricing logic doesn't belong in a free-WP.org-launch PRD.

The competitive map lives in the addendum (§G), not the PRD — correct for shape. The PRD's §1 carries the one-paragraph version a reader needs.

Aesthetic and Tone (§14) is the right shape for a consumer plugin — gives the developer enough voice guidance to write the setup wizard and observability copy without a designer in the loop.

### Findings
*Shape is correct. The PRD is doing consumer-product PRD work, not enterprise-B2B PRD work.*

## Mechanical notes

**Glossary drift:** Minimal. One soft mismatch: §1 phrase "single indexed query at render" vs. NFR-PERF-2 "exactly 2 added queries per pageview." Recommend fixing §1.

**ID continuity:**
- FR-1 through FR-12: contiguous, unique ✓
- SM-1 through SM-6 + SM-C1, SM-C2: contiguous, unique ✓
- UJ-1 through UJ-5: contiguous, unique ✓
- NFR-PERF-1..6, NFR-HOST-1..5, NFR-IDX-1..6, NFR-QUAL-1..4, NFR-ON-1..4, NFR-WPORG-1..5, NFR-MKT-1..3: contiguous, unique within groups ✓

**Broken cross-references:**
- FR-8 → NFR-CAC: **broken.** Intended NFR-PERF-5. Single instance; otherwise refs resolve.

**Assumptions Index roundtrip:**
- 6 of 7 inline `[ASSUMPTION]` tags appear in the document and are indexed in §15 ✓
- Index entry #7 (WP 6.0+ coverage) points to §13, but §13 text lacks the inline `[ASSUMPTION]` marker. Roundtrip half-broken.
- No orphan index entries (nothing in §15 without a corresponding inline tag, modulo entry #7's missing marker).
- No orphan inline tags (every inline `[ASSUMPTION]` appears in §15).

**`[NOTE FOR PM]` placement:** Appears twice — §5 (mid-content injection) and §6.2 (same item). Both at real product-tension points (the deferred +25% CTR opportunity). Appropriate.

**Counter-metric structure:** SM-C1 (review keyword surveillance) and SM-C2 (cross-category 20–50% band) are both falsifiable and counter the obvious failure modes. SM-C2 doubles as NFR-QUAL-2 — intentional cross-tie, not duplication.
