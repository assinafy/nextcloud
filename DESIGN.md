---
name: Assinafy for Nextcloud
description: A Nextcloud-native workspace for governed document signing and contract operations.
colors:
  primary: "#168a53"
  primary-strong: "#0f6d40"
  warning: "#925c00"
  danger: "var(--color-error, #c52828)"
  surface: "var(--color-main-background, #fff)"
  surface-alt: "var(--color-background-dark, #f5f5f5)"
  text: "var(--color-main-text, #222)"
  text-muted: "var(--color-text-maxcontrast, #5f6368)"
  border: "var(--color-border, #d8d8d8)"
typography:
  display:
    fontFamily: "var(--font-face, -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif)"
    fontSize: "clamp(28px, 3vw, 42px)"
    fontWeight: 700
    letterSpacing: "-0.03em"
  headline:
    fontFamily: "var(--font-face, -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif)"
    fontSize: "clamp(20px, 2vw, 25px)"
    fontWeight: 700
    letterSpacing: "-0.02em"
  body:
    fontFamily: "var(--font-face, -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif)"
  label:
    fontFamily: "var(--font-face, -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif)"
    fontWeight: 600
rounded:
  control: "8px"
  surface: "12px"
spacing:
  xs: "8px"
  sm: "12px"
  md: "16px"
  lg: "22px"
  xl: "28px"
components:
  button-primary:
    backgroundColor: "{colors.primary-strong}"
    textColor: "#fff"
    rounded: "{rounded.control}"
  work-surface:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.surface}"
    padding: "{spacing.lg}"
---

# Design System: Assinafy for Nextcloud

## Overview

**Creative North Star: "The Nextcloud Signing Desk"**

The extension is an operational surface inside Nextcloud, not a separate destination. It inherits the host's typography, form controls, theme variables, navigation expectations, and light/dark behavior. Assinafy green identifies the active signing path and successful operational states without taking over the Nextcloud shell.

The screen is dense enough for repeated document work but keeps one paid action visible at the end of a linear flow: select, configure, review, send. Documents, contracts, and webhooks are separate work areas rather than competing dashboard tiles.

**Key Characteristics:**

- Nextcloud-native controls and theme inheritance
- Assinafy green reserved for active workflow and success
- Linear send flow with a visually distinct price gate
- Flat bordered work regions on a lightly tinted page field
- Responsive grids that collapse to one column below 840px

## Colors

The palette is Nextcloud-neutral with one Assinafy operational accent and semantic warning/error roles.

### Primary

- **Assinafy Green:** identifies active tabs, availability, selection focus, and branded primary actions.
- **Assinafy Green Strong:** provides the contrast needed for primary-button backgrounds and success copy.

### Neutral

- **Inherited Surface:** uses the current Nextcloud main background so the app follows installed themes.
- **Inherited Alternate Surface:** separates the page field from working regions.
- **Inherited Text and Border:** retain the host's contrast and theme behavior.

### Named Rules

**The Operational Accent Rule.** Green carries a state or primary action; it is not decorative filler.

**The Host Theme Rule.** Neutral colors come from Nextcloud variables with safe fallbacks. Do not replace them with fixed light-only values.

## Typography

**Display Font:** the Nextcloud interface family with system fallbacks

**Body Font:** the Nextcloud interface family with system fallbacks

**Character:** direct and familiar. Hierarchy comes from weight, size, and spacing instead of a separate display face that would compete with the host application.

### Hierarchy

- **Display** (700, fluid 28–42px, tight tracking): application title only.
- **Headline** (700, fluid 20–25px, tight tracking): work-area and workflow-step headings.
- **Body** (inherited): instructions, values, and recovery copy, kept to roughly 70 characters where possible.
- **Label** (600): field names, table headers, and compact actions.

### Named Rules

**The Host Voice Rule.** Use the installed Nextcloud font stack and control text sizing; the extension should never look embedded from another product.

## Layout

The main container is capped at 1180px and centered inside a fluid page gutter. The send screen uses an asymmetric two-column grid: a narrower file browser and a wider recipient workspace. Supporting options span the full content width. At 840px the send and option grids become one column; at 600px surfaces lose outer shadow and corner treatment, form grids stack, and primary actions fill the width.

Spacing uses a practical 8/12/16/22/28px rhythm. Headings stay close to their explanatory text; separate tasks receive the larger gaps.

## Elevation & Depth

The app is flat by default. One ambient shadow (`0 10px 30px rgb(0 0 0 / 8%)`) lifts the complete work panel from the Nextcloud page field on wider screens. Internal work regions use a one-pixel inherited border without another shadow. Mobile removes the panel shadow.

**The Single-Elevation Rule.** The complete work panel may lift; nested tasks stay flat and bordered.

## Shapes

Working surfaces and recipient groups use 12px corners. Controls retain the host's normal radius. Small circular geometry is limited to connection-status dots. Tabs remain rectangular and use an inset bottom marker for the active state.

## Components

### Buttons

- **Primary:** strong Assinafy green with white text, using the host's control size and an 8px fallback radius.
- **Hover / Focus:** hover lightens to the primary green; keyboard focus uses a three-pixel translucent green outline with a two-pixel offset.
- **Secondary:** standard Nextcloud button appearance without an invented brand treatment.

### Cards / Containers

- **Corner Style:** 12px on desktop, square outer panel on narrow screens.
- **Background:** inherited main surface.
- **Shadow Strategy:** ambient shadow only on the complete desktop panel.
- **Border:** one-pixel inherited border on task regions.
- **Internal Padding:** 22px desktop and 16px narrow.

### Inputs / Fields

Fields use Nextcloud control styling and fill the available column. Labels stack above inputs with a six-pixel gap. Focus receives the shared green outline. Conditional phone and CPF/CNPJ fields leave the layout entirely when their method does not need them.

### Navigation

Four horizontal work-area tabs sit above the panel and may scroll horizontally. The active tab uses strong green text and a three-pixel inset bottom marker. The structure remains tab-like at every width.

### Price Gate

The price gate is a pale green operational region containing the aggregate credits, balances, breakdown, extra-document notice, and resource status. It is the only step that enables the final send action.

## Do's and Don'ts

### Do:

- **Do** inherit Nextcloud theme variables for every neutral surface and text role.
- **Do** keep the quote review visually distinct and adjacent to the final send action.
- **Do** show loading, empty, error, disabled, and partial-success states in the same workflow.
- **Do** stack grids and actions at the established 840px and 600px breakpoints.

### Don't:

- **Don't** use Assinafy green on controls that are not primary, selected, or successful.
- **Don't** add shadows to nested task regions.
- **Don't** replace familiar fields, tabs, tables, and buttons with custom interaction patterns.
- **Don't** hide price changes, contract partial failures, or webhook secret one-time visibility behind generic success copy.
