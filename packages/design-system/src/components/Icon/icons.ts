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
} as const;

export type IconName = keyof typeof icons;
