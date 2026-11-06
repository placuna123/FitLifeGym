// Chart colors read from the theme tokens in resources/css/app.css, so every chart uses
// the same palette as the rest of the UI and follows the accent (gold / red) and light mode.
export type ChartPalette = {
    accent: string;
    accentLight: string;
    accentDark: string;
    accentDeep: string;
    neutral: string;
    /** "r, g, b" for rgba(...) fills */
    accentRgb: string;
    neutralRgb: string;
    /** axis labels, legend text */
    text: string;
    /** grid lines */
    grid: string;
    /** the box the chart sits on (doughnut slice borders) */
    surface: string;
    /** a series of distinct palette colors, for doughnut / pie slices */
    series: string[];
};

export function chartPalette(): ChartPalette {
    const css = getComputedStyle(document.documentElement);
    const v = (name: string, fallback: string) => css.getPropertyValue(name).trim() || fallback;

    const accent = v('--fg-accent', '#d9b45a');
    const accentLight = v('--fg-accent-light', '#f0d48a');
    const accentDark = v('--fg-accent-dark', '#b8923a');
    const accentDeep = v('--fg-accent-deep', '#8a6a1c');
    const neutral = v('--fg-neutral', '#8c8478');
    const neutralRgb = v('--fg-neutral-rgb', '140, 132, 120');

    return {
        accent,
        accentLight,
        accentDark,
        accentDeep,
        neutral,
        accentRgb: v('--fg-accent-rgb', '217, 180, 90'),
        neutralRgb,
        text: v('--fg-card-ink-soft', '#a8a196'),
        grid: `rgba(${neutralRgb}, 0.18)`,
        surface: v('--fg-card', '#151515'),
        series: [accent, accentDark, neutral, accentLight, accentDeep],
    };
}
