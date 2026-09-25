# Interaction ownership and collision risks

Scope: baseline source trace. No mobile/browser observations have been made. Each risk below is a hypothesis/verification target, not a reported reproduced regression.

| Interaction | Source owner and evidence | Precedence / collision | Required observation |
|---|---|---|---|
| Parent vertical scroll | app.php inline html/body overflow:hidden and fixed #sakazuki-app-frame | Parent must not become page scroll owner | Inspect parent/child scroll offsets during touch and toolbar resize |
| Child page scroll | Page document/main CSS; Dashboard main has overflow-y-auto but no explicit fixed height | Computed scroll owner cannot be inferred just from utility name | scrollHeight/clientHeight/computed overflow at all widths |
| Drawer | nav.js::setDrawerState; role nav CSS .nav-drawer-open on html/body | Locks child document; independently scrollable .nav-drawer-scroll; no implemented focus trap found in nav.js | Body/main leakage, footer reachability, focus return, keyboard/SR |
| Dropdown | nav.js delegated click and Escape; role nav menu CSS | Closes on outside click; matchMedia 1536 crossing resets transient state, not every resize | 1535/1536 crossing, no accidental close on toolbar resize |
| Dashboard dynamic rows | purchase-activity.js + fragment data attributes | Owns activity rows only; dashboard-live does not own current main | Verify one deferred init, rotation, empty/updated/failure states |
| Activity bfcache | purchase-activity.js:292 pagehide marks stopped and removes visibility handler | No pageshow restart found; nav and shell do handle restore | Real persisted pageshow then observe request/rotation continuation |
| Language | role nav PHP_LANG before lang.js; lang init then nav DOMContentLoaded init | Lang.initialized prevents duplicate langReady; init still updates translations | Server language wins stale storage; no loader trapped; TH/EN layout |
| Loader | security.js::AppPageLoader + role nav initial #site-loader | Nav initialization hides through shared helper; pageshow/visibility/focus also hide; 8s fallback | Navigation, download, hidden tab, aborted request; pointer-events released |
| Child history | native full document navigation; shell-bridge wraps pushState/replaceState in iframe | app-shell replaces parent URL, not duplicate push; Admin instant-filter changes child history | Back/Forward and reload with query/hash in both contexts |
| Auth navigation | app-shell::promoteAuthPage | login/logout/register/forgot/reset promoted; verify_account absent | Expired/unverified user inside iframe; no nested shell or navigation lock |
| Modal quantity | buy.php::selectVariant / closeQuantityModal / Tab handler | Existing code explicitly focuses number input; AppMotion adds animation; close restores focus after timer | Actual Android/iOS keyboard, dismiss/reopen, visible controls, background scroll |
| Purchase success | Store inline success focus + copy/download/close | Distinct from quantity modal; do not merge states for styling | Long keys, Copy All, download must not leave loader over page |
| Pending / stock | Store inline callbacks and __store navigation guard | Async order/inventory callbacks must not override user navigation; multiple state owners touch buttons/banner | Navigate during delayed response; stock changes while selection open |
| Admin replacement | instant-filter.js::refresh -> replaceWith; remember/restoreFocus | Lang update, security instantfilter:updated animation and page-specific delegated handlers follow | Composition typing, lost focus, stale responses, multiple target panels |
| Touch / motion | security.js injects broad touch-action:manipulation and active transform; per-page styles | Existing global behavior affects proposed component styles even without editing it | Tap vs drag, text selection, focus-visible, reduced motion |
| Parent music | music-player.php settings; music-player.js early child suppression | Parent overlay z-index 58 is outside child stacking context; child z-index 12050 cannot escape iframe | Music expanded/collapsed against child modal/drawer and keyboard |
| Horizontal areas | Store category-filter, tables, page-specific overflow-x-auto | Nested horizontal/vertical touch owners | Diagonal drag, edge scroll, no accidental card purchase/navigation |
| Branding / external CSS | page style + tailwind-built + role nav + injected security style + optional music CSS | Dashboard .glass is broad and also affects its shared nav; utility CSS not isolated | Computed-style snapshots for main + nav, verify no cross-page changes |

## Stacking contexts are separate documents

Child role nav uses topbar z-50, drawer overlay 60, drawer 61 and loader 9999. Store has overlays/modal layers at 10000/10001, 11000/11001 and 12050 (buy.php styles). Parent music uses z-index 58. These numbers cannot be globally sorted across the iframe boundary. Any design raising only a child modal z-index to hide parent music is based on an invalid ownership model.

## Mandatory second pass performed (source search)

Regenerated 166 PHP/JS/CSS/access-rule source files and searched includes/loaders, inline/delegated handlers, custom events, history/postMessage, fetch/timers/observers, focus/blur, scroll, replacement, fixed/sticky/z-index/touch/safe-area. SOURCE_EVIDENCE.md preserves 458 include/load, 370 event/history/network, 143 focus/scroll/replacement and 94 fixed/stack/touch matched lines. Counts are search results, not coverage of all branches.

Reversed references for Dashboard's catalog-auto-grid/catalog-card, purchase-activity data attributes, drawer/overlay/site-loader, PHP_LANG/Lang, music root and dormant dynamic-root/fast-page markers. Second pass found the verification promotion gap, request-triggered cleanup, the dormant hydration assumption, Store focus conflict, and activity pagehide/bfcache hypothesis. No visualViewport handler was found by the source search. Device/browser behavior is still OPEN.

No shared ownership collision is resolved by this document alone. Pilot may not alter a collision area until observed precedence and baseline evidence exist.
