// Meridian icon set — hand-authored line-art glyphs (24×24, stroke-based, no external icon
// dependency so the embeddable guest runtime stays request-free). Each value is SVG path `d`
// data rendered with fill:none / stroke:currentColor by Icon.vue. Add glyphs here as the app needs
// them; keep the geometry simple/technical to match the blueprint concept. See the DSR "Icon System"
// appendix for the sizing + a11y conventions.

export const icons = {
    dashboard: 'M4 4h6v6H4z M14 4h6v6h-6z M4 14h6v6H4z M14 14h6v6h-6z',
    forms: 'M7 3h7l4 4v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z M14 3v4h4 M9 13h6 M9 17h6',
    submissions:
        'M9 6h11 M9 12h11 M9 18h11 M4.5 6l1 1 2-2 M4.5 12l1 1 2-2 M4.5 18l1 1 2-2',
    settings:
        'M3 6h18 M3 12h18 M3 18h18 M6 6a2 2 0 1 0 4 0a2 2 0 1 0-4 0 M12 12a2 2 0 1 0 4 0a2 2 0 1 0-4 0 M4 18a2 2 0 1 0 4 0a2 2 0 1 0-4 0',
    bell: 'M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9z M10.5 21a1.5 1.5 0 0 0 3 0',
    menu: 'M4 6h16 M4 12h16 M4 18h16',
    close: 'M6 6l12 12 M18 6l-12 12',
    'chevron-down': 'M6 9l6 6 6-6',
    sun: 'M7 12a5 5 0 1 0 10 0a5 5 0 1 0-10 0 M12 2v2 M12 20v2 M2 12h2 M20 12h2 M4.9 4.9l1.4 1.4 M17.7 17.7l1.4 1.4 M19.1 4.9l-1.4 1.4 M6.3 17.7l-1.4 1.4',
    moon: 'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z',
    monitor: 'M3 4h18v12H3z M8 20h8 M12 16v4',
    user: 'M8 8a4 4 0 1 0 8 0a4 4 0 1 0-8 0 M4 20c1-4 4-6 8-6s7 2 8 6',
    logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4 M16 17l5-5-5-5 M21 12H9',
    feedback: 'M21 15a2 2 0 0 1-2 2H8l-4 4V5a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2z',
    check: 'M5 12l4 4 10-10',
    alert: 'M12 3L2 20h20L12 3z M12 10v5 M12 18v.5',
    info: 'M12 3a9 9 0 1 0 0 18a9 9 0 0 0 0-18 M12 11v5 M12 8v.5',
    shield: 'M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6l7-3z M9 12l2 2 4-4',
    plus: 'M12 5v14 M5 12h14',

    // ── Actions ──────────────────────────────────────────────────────────────
    search: 'M11 4a7 7 0 1 0 0 14a7 7 0 0 0 0-14z M20 20l-4.2-4.2',
    filter: 'M3 5h18 M6 12h12 M10 19h4',
    edit: 'M4 20h4L18 10l-4-4L4 16z M14 6l4 4',
    trash: 'M4 7h16 M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2 M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13 M10 11v6 M14 11v6',
    download: 'M12 4v11 M8 11l4 4 4-4 M4 20h16',
    upload: 'M12 20V9 M8 13l4-4 4 4 M4 4h16',
    'external-link': 'M14 4h6v6 M20 4l-9 9 M18 13v6a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h6',
    'chevron-right': 'M9 6l6 6-6 6',
    'chevron-left': 'M15 6l-6 6 6 6',

    // ── People & places ──────────────────────────────────────────────────────
    users: 'M8 9a3.2 3.2 0 1 0 6.4 0a3.2 3.2 0 1 0-6.4 0 M2.5 20c.8-3.2 2.9-4.8 5.7-4.8s4.9 1.6 5.7 4.8 M17 5.4a3.2 3.2 0 0 1 0 6.2 M18.5 20c-.4-2.1-1.3-3.6-2.7-4.5',
    'user-plus': 'M9 8a4 4 0 1 0 8 0a4 4 0 1 0-8 0 M3 20c.9-3.4 3.3-5.3 6-5.3 M18 13.5v5 M15.5 16h5',
    building: 'M4 21h16 M6 21V4h8v17 M14 21V9h4v12 M9 8h2 M9 12h2 M9 16h2',

    // ── Metrics & data ───────────────────────────────────────────────────────
    'trend-up': 'M3 17l6-6 4 4 8-8 M16 7h5v5',
    activity: 'M3 12h4l2.5 7 4-15 2.5 8H21',
    inbox: 'M4 13l2.5-8h11L20 13v6H4z M4 13h5a3 3 0 0 0 6 0h5',

    // ── Comms & time ─────────────────────────────────────────────────────────
    mail: 'M3 6h18v12H3z M3 7l9 6 9-6',
    calendar: 'M4 6h16v14H4z M4 10h16 M8 3v4 M16 3v4',
    clock: 'M12 3a9 9 0 1 0 0 18a9 9 0 0 0 0-18z M12 8v4l3 2',

    // ── Builder — reorder / history / duplicate ────────────────────────────────
    grip: 'M9 6h.01 M9 12h.01 M9 18h.01 M15 6h.01 M15 12h.01 M15 18h.01',
    'chevron-up': 'M6 15l6-6 6 6',
    copy: 'M9 9h9a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1v-9a1 1 0 0 1 1-1z M5 15a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1',
    undo: 'M9 7L4 12l5 5 M4 12h11a5 5 0 0 1 5 5v1',
    redo: 'M15 7l5 5-5 5 M20 12H9a5 5 0 0 0-5 5v1',

    // ── Builder — field-type category glyphs (palette grouping) ────────────────
    type: 'M4 6V4h16v2 M12 4v16 M9 20h6',
    hash: 'M9 4L7 20 M17 4l-2 16 M4 9h16 M3 15h16',
    list: 'M8 6h13 M8 12h13 M8 18h13 M3.5 6h.01 M3.5 12h.01 M3.5 18h.01',
    'map-pin': 'M12 21c4-4.5 7-7.8 7-11a7 7 0 1 0-14 0c0 3.2 3 6.5 7 11z M9.5 10a2.5 2.5 0 1 0 5 0a2.5 2.5 0 1 0-5 0',
    image: 'M4 5h16v14H4z M8.5 11a1.5 1.5 0 1 0 0-.01 M4 16l5-4 4 3 3-2 4 3',
    layout: 'M4 5h16v14H4z M4 9h16 M10 9v10',
    sliders: 'M4 7h12 M4 12h8 M4 17h14 M18 5v4 M14 10v4 M8 15v4',

    // ── Integrations (H15b) ───────────────────────────────────────────────────
    // A two-pin plug: prongs, body, cord. Deliberately generic rather than a vendor mark — the set is
    // hand-authored line art (see the header), and a brand logo would be neither line art nor ours.
    plug: 'M9 3v5 M15 3v5 M6 8h12v3a6 6 0 0 1-12 0z M12 17v4',
} as const;

export type IconName = keyof typeof icons;
