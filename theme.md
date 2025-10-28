# Cosmic Trading Portal Theme System (v2.0)

This document captures the live design language implemented inside customer project/cosmic-portal/src/app/globals.css. The system blends an **orange glass** experience for light mode and a **black + blue glass** experience for dark mode, keeping parity between both moods.

---
## 1. Stack & Tooling
- **Framework**: Next.js 14 App Router + React 19
- **Language**: TypeScript
- **Styling**: Tailwind CSS v4 (inline @theme tokens) + custom CSS variables
- **Fonts**: Google Inter (body) + Poppins (display)
- **Motion**: Framer Motion + CSS transitions
- **Theming**: 
ext-themes toggling class on <html>

---
## 2. Color Architecture

### Solar Ember — Light Mode (default)
`css
:root {
  --brand-primary: #ff6b2c;
  --brand-primary-strong: #ff914b;
  --brand-electric: #204bff;        /* used for accent pills */
  --brand-secondary: #1b0f24;       /* body text */
  --brand-muted: #6c5870;           /* helper text */
  --brand-background: #fff8f2;      /* page background */
  --brand-panel: rgba(255,255,255,0.95);
  --brand-card: rgba(255,255,255,0.90);
  --brand-glass-accent: rgba(255,122,74,0.25);
  --brand-border: rgba(255,255,255,0.75);
  --radius: 18px;
  --shadow-soft: 0 30px 80px rgba(43,19,0,0.08);
  --shadow-strong: 0 40px 120px rgba(43,19,0,0.18);
}
`
- Background uses stacked gradients + radial glow for a subtle orange haze.
- Copy color defaults to ar(--brand-secondary) ensuring contrast on white glass.

### Lunar Nebula — Dark Mode (.dark on <html>)
`css
.dark {
  --brand-primary: #ff8648;
  --brand-primary-strong: #ffad6b;
  --brand-electric: #4ca4ff;
  --brand-secondary: #f6f7ff;       /* replaces body text */
  --brand-muted: #8f9cc6;
  --brand-background: #04060c;
  --brand-panel: rgba(6,9,18,0.92);
  --brand-card: rgba(9,12,24,0.88);
  --brand-glass-accent: rgba(71,138,255,0.30);
  --brand-border: rgba(255,255,255,0.12);
  --shadow-soft: 0 30px 80px rgba(0,0,0,0.45);
  --shadow-strong: 0 45px 130px rgba(0,0,0,0.65);
}
`
- Uses blue-tinted glass and glowing gradients while keeping the same structure.

### Tailwind Tokens
@theme inline maps 	ext-brand-secondary, 	ext-brand-muted, etc. so components can stay utility-driven.

---
## 3. Surface & Glass Tokens
| Token/Class | Light Treatment | Dark Treatment |
|-------------|-----------------|----------------|
| .glass-panel | Gradient mix of --brand-panel + soft border, 28px radius, strong shadow | Same geometry, but background taps --brand-panel + blue accent for a black/blue glass effect |
| .glass-card | Lighter card blend, 18px radius, white border for lift | Deep navy gradient + translucent border |
| .glass-orange | Used on sidebar/chat; orange glow with 30px blur | Automatically swaps to midnight/blue gradient with neon glow |
| .brand-gradient | Orange CTA gradient (#ff6b2c ? #ff914b) | Same values, but sits above dark surfaces for pop |
| .brand-gradient-blue | Optional electric accent (#14206a ? var(--brand-electric)) for charts/badges | Feels like neon piping against midnight surfaces |

All surface helpers apply color: var(--brand-secondary), so text flips automatically between modes.

---
## 4. Typography & Spacing
- **Heading stack**: ont-[var(--font-heading)] (Poppins) for headlines & logotype.
- **Body stack**: ont-[var(--font-inter)] for everything else.
- Global border radius = 18px; hero/headers use ounded-[32px] for softer shells.
- Text gradients (.text-gradient, .text-gradient-blue) share the same palette as their button counterparts.

---
## 5. Component Treatments
- **Buttons** (src/components/ui/Button.tsx)
  - Primary: rand-gradient, heavy shadow, white text.
  - Secondary: translucent white glass (light) or frosted charcoal (dark) with border.
  - Outline/Ghost respect 	ext-brand-secondary / 	ext-brand-muted and swap automatically.
- **Cards/Badges**
  - Cards inherit .glass-card so statistics, tables, wallet panes read clearly in both modes.
  - Badges use warm neutrals by default and auto-invert in dark mode thanks to the glass surface.
- **Chat Popup**
  - Container uses .glass-panel glass-orange; dark mode switches to the blue glass blend without extra code.
- **Header & Sidebar**
  - glass-panel shells + orange glass overlay in light mode, neon blue edges in dark mode.

---
## 6. Motion & Interaction
- Use 	ransition: background 0.4s ease, color 0.4s ease; globally for smooth theme toggles.
- Framer Motion animates chat FAB + popup; motion curves are spring-based (stiffness 300, damping 30).
- Micro-interactions (buttons, nav pills) rely on Tailwind transitions with slight scale on press.

---
## 7. Implementation Notes
1. **Globals file**: src/app/globals.css defines every token + helper class.
2. **Theme toggle**: ThemeToggle reads esolvedTheme to keep the correct contrast while switching.
3. **Usage pattern**: wrap layouts in .glass-panel and children in .glass-card to inherit colors automatically.
4. **Chat + Sidebar** already demonstrate the orange/blue glass spec; reuse these classes for new modules.

---
## 8. Quick Reference Snippets
`	sx
// Example card
<div className="glass-card p-6 text-brand-secondary dark:text-white">
  <p className="text-sm text-brand-muted">Current Balance</p>
  <p className="text-3xl font-semibold">,570.00</p>
</div>

// Example CTA
<Button className="brand-gradient shadow-[0_20px_45px_rgba(255,107,44,0.35)]">
  New Query
</Button>

// Sidebar shell
<aside className="glass-panel glass-orange ambient-blur"> ... </aside>
`

This document should stay in sync with globals.css. Any palette tweaks should be updated in both places to keep design + documentation aligned.