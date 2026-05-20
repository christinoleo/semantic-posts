# Input Reconciliation: project-brief.md → PRD

## Coverage summary

Roughly 90-95% of the brief's content lands somewhere in the PRD or addendum. The acceptance-criteria section is carried through with high fidelity into §9 NFRs; the disposable-build strategy, kill criterion, distribution plan, and competitive map are all preserved (mostly in the addendum, which is by design). The remaining gaps are concentrated in two areas: (1) the brief's distinctive voice and pitch-style framing have been flattened in places, and (2) a few specific named angles and risks from the brief did not surface as explicit Open Questions, marketing-site requirements, or NFR consequences. None of the gaps are catastrophic; several are worth a PM pass before architecture.

## Gaps (content from brief missing from PRD or addendum)

- **[Severity: high]** Brief §Distribution — *"Targeted angle: '5 related-posts plugins still banned by WP Engine in 2026 — and what to use instead' (Contextual Related Posts, Similar Posts, Dynamic Related Posts, SEO Auto Links & Related Posts are still on the list; YARPP is not — removed 2022)"* — This specific, named marketing-content angle is not surfaced anywhere in the PRD or addendum. The addendum's §E covers host outreach but not the public-facing "banned plugins" listicle/SEO angle that the brief commits to as a discovery driver. *Suggested placement:* PRD §11 Why Now bullet, or as a new NFR-MKT-4 under §9.7, or as a marketing-site deliverable in §6.1 MVP scope.

- **[Severity: high]** Brief §Distribution — *"Listicle inclusion (WPBeginner, Kinsta, Themeisle) — outreach only once plugin has 20+ five-star reviews"* — Named third-party publication targets and the explicit *reviews-gate* on outreach are dropped. PRD SM-4 mentions "listed in at least one third-party comparison article" but loses the named targets and the gating discipline. *Suggested placement:* PRD §7 Success Metrics SM-4 note, or addendum §E (outreach process).

- **[Severity: high]** Brief §Risks — *"Reader UX pattern may be wrong. The 'list at end of post' format is convention, not proven optimal. Currently being researched in parallel; brief will be updated when findings land."* — This is the brief's only acknowledged product-validity risk. PRD §8 Open Questions has empty-state UX (Q1) and mid-content injection (Non-Goal note) but does not surface the underlying "end-of-post may simply be wrong" hypothesis as an open question. *Suggested placement:* PRD §8 Open Questions as a new entry, or §7 counter-metric.

- **[Severity: medium]** Brief §Risks — *"Big SEO suite ships semantic related posts. AIOSEO, Yoast, or Jetpack adding this in 2026 is plausible. In disposable-build mode, this is acceptable — you'll have captured 6+ months of revenue by then."* — The risk is acknowledged in addendum §F (Disposable-Build Rationale) and competitive map §G but not as an explicit Open Question or risk callout. The brief's *"6+ months of revenue"* framing and the explicit *"acceptable"* judgment are diluted. *Suggested placement:* PRD §8 or §11, addendum §F (sharpen the existing language).

- **[Severity: medium]** Brief §UX rationale — *"No pop-ups, no exit-intent, no pre-content. NN/G classifies these as 'needy patterns' that degrade user experience; Google penalizes mobile interstitials in search ranking."* — This is an explicit design *prohibition* in the brief, not just a deferred option. PRD §5 Non-Goals lists deferred features but does not codify the no-needy-patterns rule. The Google mobile-interstitial SEO penalty rationale is dropped entirely. *Suggested placement:* PRD §5 Non-Goals as an explicit "Will not implement" bullet, or §14 Aesthetic and Tone.

- **[Severity: medium]** Brief §What's been validated — The *"⏳ Optimal UX surface for related content recommendation — research in flight"* line is the only outstanding validation item from the brief, and it has been dropped. *Suggested placement:* PRD §8 Open Questions.

- **[Severity: medium]** Brief §Solution — *"Page-load cost: one `get_post_meta` call. Lower than category-matching plugins."* — The brief's specific positioning claim ("lower than category-matching") is stronger than PRD NFR-PERF-2's "exactly 2 added queries." The competitive *"lighter than Same Category Posts"* benchmark claim from the brief's host-compatibility table is also missing from the PRD's NFR phrasing. *Suggested placement:* PRD §9.1 NFR-PERF, addendum §C or §G.

- **[Severity: low]** Brief §Acceptance Criteria — *"Compatibility list in the plugin's WordPress.org title and copy: 'Compatible with WP Engine, Kinsta, SiteGround, Cloudways, Pressable' — both a buyer signal and a host signal."* — The brief specifies this goes in the WP.org *title*. PRD NFR-MKT-3 only mentions the marketing-site fold; WP.org listing copy is not specified. *Suggested placement:* PRD §9.6 (NFR-WPORG) or §9.7 (NFR-MKT).

- **[Severity: low]** Brief §Success criteria — *"Day 30 post-launch: 100+ active installs, 5+ five-star reviews. If miss → diagnose distribution."* — The Day-30 checkpoint is dropped entirely. PRD §7 keeps Day-90 (SM-1, SM-2) but loses the intermediate diagnosis trigger. *Suggested placement:* PRD §7 Success Metrics.

- **[Severity: low]** Brief §Pricing — *"Pro tier ($49/year, unlimited posts + hosted embeddings + premium support) ships only if free tier hits 1,000 active installs in 90 days. Premature monetization slows distribution."* — The brief's threshold for Pro is **1,000 installs in 90 days**, paired with the explicit reasoning *"premature monetization slows distribution."* PRD §12 says Pro ships if Day-90 milestones (SM-1: 500, SM-2: 20 reviews) hit — a *lower* threshold than the brief committed to. Addendum §I preserves the pricing but not the 1,000-install Pro trigger or the rationale. *Suggested placement:* PRD §12 Monetization — explicitly reconcile the Pro-trigger threshold; either honor 1,000 from the brief or document why 500 is the new bar.

- **[Severity: low]** Brief §Acceptance Criteria — *"Setup wizard surfaces API cost honestly: 'Indexing your 1,247 existing posts will cost approximately $0.13 via OpenAI.'"* — The brief's example uses **$0.13**; the PRD's example in UJ-1 uses **$0.02** (consistent with the May 2026 pricing recalibration — this is the legitimate research correction, not a drop). The brief's literal phrasing *"will cost approximately"* and the *"honest"* framing are preserved in PRD §14 and NFR-ON-4. **Confirming as intentional, not a gap.**

## Drift (content present but altered in tone, scope, or precision)

- **[Severity: medium]** Brief one-liner — *"Related posts that are actually related, without slowing your site down."* → PRD §1 Vision — opens with technical description ("ships related-post recommendations using semantic embeddings computed once at publish time..."). The brief's headline message is the **outcome-not-tech** framing; the PRD opens by leading with the tech. The brief explicitly says *"'Semantic' and 'embeddings' are credibility details in the FAQ, not the pitch."* — PRD §1 inverts this. *Suggested fix:* Lead PRD §1 with the outcome sentence; demote the architecture sentence to paragraph 2.

- **[Severity: medium]** Brief §Pricing — *"Premature monetization slows distribution."* → PRD §12 — the rationale is dropped; only the deferral remains. The brief's framing is a strategic *commitment* ("we are choosing not to monetize because it would slow distribution"), not a passive deferral. *Suggested fix:* Restore the rationale sentence to PRD §12 opening.

- **[Severity: medium]** Brief §Positioning — *"The headline message to the buyer is the outcome, not the tech."* → PRD §14 Aesthetic and Tone captures the *voice* but not the *positioning-message-architecture* rule. The brief is prescriptive about what goes in the pitch vs. what goes in the FAQ. *Suggested fix:* Add a "Messaging hierarchy" note to PRD §14 or §1.

- **[Severity: low]** Brief §Target user — *"Technical enough to install a plugin and paste an API key. Not developers."* → PRD §2.1 says *"Not a developer."* — preserved. But the brief's tighter implicit persona phrasing ("can install a plugin and paste an API key") gets expanded in PRD §2.1 to slightly broader language. Marginal.

- **[Severity: low]** Brief §Competitive map header — *"(after validation)"* qualifier → dropped in addendum §G. The brief explicitly framed the competitive table as post-validation findings; addendum §G presents the same data as authoritative current state without the "after validation" lineage. *Suggested fix:* Note in addendum §G that the map reflects validation work.

- **[Severity: low]** Brief §Solution — *"finds cross-category connections category matching misses entirely"* → PRD NFR-QUAL-2 and SM-C2 both require 20% cross-category, which honors the spirit. The brief's stronger phrasing ("category matching misses entirely") loses its rhetorical force in the NFR's "at least 20%." Acceptable tradeoff for measurability.

## Confirmed alignment

- **One-line pitch / problem statement** — Brief problem statement is reflected in PRD §1 Vision (paragraph 2) and §2.1.
- **Self-contained positioning** — Brief's "no Qdrant, no Pinecone, no Supabase, no Typesense" carries verbatim to PRD §1 and §5.
- **v1 scope** — All in-scope items from brief §v1 scope are mapped to PRD §6.1 / FR-1 through FR-12. Out-of-scope items map to §6.2 / §5.
- **Display template** — Brief's "5 items, #1 featured (Medium-style)" maps to FR-8 with CSS class names preserved.
- **Host compatibility table** — Brief's structural-guarantees table maps to PRD §9.2 (NFR-HOST) and §10.3, plus addendum §E.
- **Pre-launch certification process** — Brief's 6-step process is preserved in addendum §E.
- **Acceptance criteria sections** — Performance, host-compat, indexing reliability, suggestion quality, onboarding, WP.org review, and marketing-site gating criteria all carry through to PRD §9 NFRs with thresholds intact (TTFB <5ms, 2 queries, <1MB memory, 1000 posts in <2min, 256M memory limit, 3-of-5 baseline beat, 20% cross-category, etc.).
- **90-day kill criterion** — 500 installs / 20 reviews / 1 comparison-article listing preserved as SM-1, SM-2, SM-4.
- **UX rationale (Collins, Beierle, CME, NN/G, Mida)** — All five sources preserved in addendum §H.
- **Risks** — API cost surprise (NFR-ON-4 cost preview) and WP.org review hygiene (NFR-WPORG) are addressed in §9.
- **Disposable-build operating mode** — Preserved in addendum §F.
- **Validated items** — Three of four "What's been validated" items from the brief are reflected (no SEO-suite roadmap entry confirmed in §11 and addendum §G; vector storage approach in addendum §A.3; first-claim retraction in addendum §A.1; YARPP host-ban correction in addendum §A.2). Only the "⏳ optimal UX surface research in flight" item is dropped (flagged above).
