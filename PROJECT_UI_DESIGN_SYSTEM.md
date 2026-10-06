# BluePOS Project UI Design System

This file is the single source of truth for BluePOS frontend visual language and reusable UI behavior.

It applies to the React + TypeScript + Vite frontend, including tenant application pages, authentication, platform administration, dialogs, nested dialogs, reports, POS screens, and future modules.

## 1. Core rule

BluePOS uses one design system, not one visual implementation per page.

Page-specific code may control:
- workflow,
- information hierarchy,
- geometry,
- responsive composition,
- business-specific layout.

Page-specific code must not invent a separate visual language for:
- colors,
- fonts,
- control appearance,
- dropdowns,
- dropdown arrows,
- buttons,
- tabs,
- modals,
- shadows,
- radii,
- focus,
- hover,
- disabled states,
- validation,
- loading,
- selected states,
- animation timing.

If a shared BluePOS UI component already exists, reuse it. Do not create a local replacement unless the shared component cannot support the required behavior.

## 2. Theme & Appearance is authoritative

All migrated and new UI must respond to the user-selected Theme & Appearance settings:

- Appearance: Light / Dark / System
- Primary Theme: Indigo / Blue / Emerald / Teal / Purple / Slate
- Density: Compact / Comfortable
- Border Radius: Small / Medium / Large
- Card Shadow: None / Soft / Normal / 3D
- Font Family
- UI Animations: On / Off

The runtime source of truth is the appearance provider and root data attributes. Visual code must use the global tokens derived from those settings.

Do not hard-code a primary brand blue, purple, green, radius, shadow, or font in a component.

Semantic colors are allowed only through global semantic tokens:
- success
- warning
- danger
- info

## 3. Canonical token source

Do not create a second token system.

Use the existing global variables exposed through `frontend/src/appearance.css`, especially:

- `--ui-bg`
- `--ui-surface`
- `--ui-surface-subtle`
- `--ui-surface-muted`
- `--ui-text`
- `--ui-text-muted`
- `--ui-border`
- `--ui-accent`
- `--ui-accent-soft`
- `--ui-accent-strong`
- `--ui-success`
- `--ui-warning`
- `--ui-danger`
- `--ui-info`
- `--ui-focus-shadow`
- `--ui-card-shadow`
- `--ui-control-shadow`
- `--control-radius`
- `--panel-radius`
- `--app-font-family`
- `--ui-transition-fast`
- `--ui-transition-normal`

Density-dependent font, spacing and control-height variables already exposed by the appearance/density CSS must be preferred over literal values when practical.

## 4. Canonical components

### Dropdowns / selects

Canonical component: `UiSelect` in `frontend/src/components/ui/UiSelect.tsx`.

Rules:
- use `UiSelect` for user-facing application dropdowns,
- popup is rendered in a portal so it is not clipped by tables, panels, or dialogs,
- selected, hover, disabled, focus, Light/Dark, radius, shadow, density and animation states come from global tokens,
- nested dialogs must pass a suitable `menuZIndex`,
- use text labels in the popup; never expose internal BIGINT IDs,
- do not add native-select arrow CSS to imitate the canonical dropdown.

Compatibility wrappers such as `BpFancySelect` and `AnimatedSelect` may remain temporarily, but they must delegate to `UiSelect`; they must not maintain independent visual behavior.

Canonical multi-select component: `UiMultiSelect` in `frontend/src/components/ui/UiMultiSelect.tsx`.

Rules:
- use `UiMultiSelect` for user-facing multi-selection instead of native `select multiple`,
- keep the same Theme & Appearance, portal, layering, focus, disabled, density, radius, shadow and animation contract as `UiSelect`.

`UiSelect` supports controlled values and legacy form serialization through `name` + `defaultValue`; do not keep a native single-select only because a form currently uses `FormData`.

Native `select` is permitted only where there is a concrete browser-semantic reason that the canonical component cannot safely provide. It must still use global tokens. Single-value controlled dropdowns should use `UiSelect`.

### Buttons

Canonical component: `UiButton` in `frontend/src/components/ui/UiButton.tsx`.

Legacy wrappers such as `DesktopButton` must delegate to `UiButton` rather than maintaining a second visual system.

Variants:
- default,
- primary,
- success,
- warning,
- danger,
- info,
- icon-only.

Primary follows `--ui-accent`. Semantic variants use semantic tokens.

All buttons must implement consistent:
- focus-visible,
- hover,
- active,
- disabled,
- radius,
- shadow,
- density,
- animations.

### Semantic action button colors

Button background color must communicate the action consistently across pages and modals while remaining soft/subtle rather than saturated.

Canonical action mapping:
- Save / Activate / Post / Approve / Complete -> success tone (soft green).
- Delete / Remove / Void / Deactivate / Reject -> danger tone (soft red).
- Refresh / Close / Preview / Print / Search -> info tone (soft blue/info).
- Hold / Retry / cautionary actions -> warning tone (soft amber).
- New / Add / Create -> Primary Theme tone (soft accent).
- Neutral navigation or non-semantic actions -> default neutral surface.

Rules:
- use `UiButton` semantic variants or shared `data-tone` / `data-action` semantics; do not hard-code action colors locally,
- the same action must have the same semantic tone whether it appears in a page toolbar, modal footer, nested modal, or admin/platform screen,
- semantic action colors must derive from `--ui-success`, `--ui-danger`, `--ui-info`, `--ui-warning`, or the Primary Theme tokens,
- disabled buttons remain visibly disabled and must not look like an enabled semantic action,
- Light/Dark, selected theme, radius, shadow and animations continue to apply.

### Inputs and textareas

Inputs inherit global surface/text/border/radius/shadow/focus/disabled tokens.

Do not create page-specific focus-ring colors.

### Tabs

Tabs may have page-specific geometry but must use global:
- text,
- muted text,
- border,
- accent,
- surface,
- active/hover states,
- radius,
- animations.

Workspace tabs follow the same token contract.

### Tables / data grids

Prefer `PosDataGrid` for standard application data grids.

Custom tables may retain module-specific columns and geometry but must use global:
- header surfaces,
- borders,
- text,
- hover rows,
- selected rows,
- empty/loading states.

### Toggles / checkboxes / radios

Use existing shared controls where available.

Toggle visual tones must use semantic/global tokens. Disabled and animations-off behavior must be respected.

## 5. Modal contract

Canonical standard modal: `UiModal` in `frontend/src/components/ui/UiModal.tsx`.

Specialized draggable or complex nested dialogs may keep their workflow-specific structure, but their visual properties must use the same tokens and overlay-layer contract.

All application dialogs must follow the same modal contract even when their internal layouts differ.

Required behaviors:
- token-based surface, border, text, radius and shadow,
- visible title/header hierarchy,
- keyboard-accessible close action,
- predictable z-index layering,
- nested dialog support,
- no popup hidden behind a backdrop,
- responsive max width/height,
- scroll only inside the content region where practical,
- animations disabled by global Animations Off.

Overlay layers are defined in `frontend/src/components/ui/uiLayers.ts`. Do not invent local numeric z-index values for modals/dropdowns.

Use the closest appropriate `UI_LAYER` constant so dropdown menus always sit above the modal that owns them.

Do not solve modal layering with unrelated page-specific z-index values when a shared modal/dropdown prop can express the layer.

## 6. Nested modal rule

When a module is embedded in another modal:
- keep the same component and workflow,
- use a compact size variant instead of creating a second implementation,
- preserve functionality,
- keep dropdown/menu portals above the nested modal,
- do not duplicate forms or business logic.

## 7. Typography

Font family comes from `--app-font-family`.

Prefer density-aware shared font tokens. Page-specific typography is allowed for hierarchy, not for a separate brand style.

Do not hard-code a different font stack inside module CSS unless required for printing/export.

## 8. Radius and shadow

Do not hard-code decorative border radii or card shadows in new/migrated UI.

Use:
- `--control-radius` for controls,
- `--panel-radius` for cards/panels/dialogs,
- `--ui-control-shadow` for controls,
- `--ui-card-shadow` for panels/dialogs.

The 3D shadow option must work through these same tokens, not through special per-page CSS.

## 9. Animations

Useful animations are allowed:
- dropdown opening,
- caret rotation,
- modal entrance,
- hover transitions,
- expand/collapse.

All such animations must stop when `data-animations='off'`.

Use global transition timing tokens. Avoid new literal timings unless a component has a strong UX reason.

## 10. Focus, hover, disabled and selected states

Every interactive component must have all applicable states.

Focus:
- keyboard-visible,
- uses `--ui-focus-shadow`,
- uses the selected accent.

Hover:
- subtle accent-derived background/border.

Disabled:
- uses muted surface/text,
- clear not-allowed affordance where appropriate,
- no misleading active animation.

Selected:
- accent-derived selected state with readable contrast in both Light and Dark.

## 11. Validation, alerts, feedback

Validation and errors use `--ui-danger`.
Success uses `--ui-success`.
Warnings use `--ui-warning`.
Informational states use `--ui-info`.

Use the existing feedback/toast/confirm system. Do not introduce a second toast or confirm style.

## 12. Responsive behavior

Do not redesign a page merely to make it use shared components.

Preserve intentional module layouts. Shared controls must fit existing responsive containers and must not force desktop-only widths.

Dropdown portals should reposition to remain in viewport.

Dialogs must use viewport-safe max width and max height.

## 13. Frozen / sensitive screens

UI system migration must not change business behavior or financial invariants.

Sales Invoice:
- preserve its approved geometry,
- preserve Amounts layout,
- token changes may affect visual styling only unless explicitly authorized.

The same principle applies to accounting, inventory, posting, payment and stock workflows.

## 14. CSS ownership

Global visual behavior belongs in:
- `frontend/src/appearance.css`
- shared component CSS under `frontend/src/components/ui/`
- existing shared component CSS when the component is domain-neutral.

Page CSS may own:
- layout,
- grid-template geometry,
- widths/heights required by workflow,
- page-only responsive composition.

Page CSS should not redefine the canonical dropdown, button, modal, focus ring, accent palette, shadow system, or theme behavior.

## 15. No local component duplication

Before creating a new UI component:
1. search shared UI components,
2. search shared desktop components,
3. extend a shared component if the behavior is generally useful,
4. create a domain-specific component only when the domain behavior is genuinely unique.

Never create `AnotherSelect`, `SpecialModal`, or page-only arrow styles just to get a different look.

## 16. Modification checklist

Whenever a page or component is created or changed:

1. Read this file.
2. Reuse canonical shared components.
3. Verify Light / Dark / System.
4. Verify all six Primary Themes.
5. Verify Compact / Comfortable.
6. Verify Small / Medium / Large radius.
7. Verify None / Soft / Normal / 3D shadow.
8. Verify Font Family.
9. Verify Animations On / Off.
10. Verify hover, focus, disabled, selected, loading, empty and error states.
11. Verify dropdown/menu z-index inside modals.
12. Verify responsive behavior.
13. Preserve business logic and page-specific workflow.
14. Avoid introducing new hard-coded primary colors or decorative shadows/radii.

## 17. Review rule

A UI task is not complete merely because the requested screen looks correct.

Before finishing, check whether the changed pattern also appears elsewhere. If it does, fix the shared component/token instead of only the current page.

If a local exception is truly required, document why in the code near the exception.

## 18. AI / coding-agent instruction

Any AI or coding agent modifying BluePOS UI must read and follow this file first.

The default decision should always be:

reuse shared component -> extend shared component -> add token -> local exception only as last resort.

Do not rely on conversation memory for UI rules. This repository file is authoritative.


## 19. Repository enforcement

Run the design-system guard before frontend typecheck/build:

```bash
cd frontend
npm run ui:check
npm run typecheck
npm run build
```

`ui:check` currently enforces:
- no raw single/multi `<select>` elements in React TSX; use `UiSelect` / `UiMultiSelect`,
- no known hard-coded Primary Theme colors inside canonical `components/ui` CSS,
- no large numeric overlay z-index values inside canonical `components/ui` CSS.

If a real browser-semantic exception is required, update this document and the checker deliberately in the same reviewed change rather than bypassing the rule locally.
