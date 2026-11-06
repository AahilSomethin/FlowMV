import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const css = readFileSync(
  resolve(import.meta.dirname, "../app/globals.css"),
  "utf8",
);
function theme(selector: RegExp) {
  const block = css.match(selector)?.[1];
  if (!block) throw Error("Theme unavailable");
  return Object.fromEntries(
    [...block.matchAll(/--([\w-]+): ([^;]+);/g)].map((m) => [m[1], m[2]]),
  );
}
function luminance(value: string): number {
  const match = value.match(/^oklch\(([\d.]+) ([\d.]+) ([\d.]+)\)$/);
  if (!match) throw Error("Opaque OKLCH token required");
  const [L, C, h] = match.slice(1).map(Number);
  const a = C * Math.cos((h * Math.PI) / 180),
    b = C * Math.sin((h * Math.PI) / 180);
  const l = (L + 0.3963377774 * a + 0.2158037573 * b) ** 3,
    m = (L - 0.1055613458 * a - 0.0638541728 * b) ** 3,
    s = (L - 0.0894841775 * a - 1.291485548 * b) ** 3;
  const rgb = [
    4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
    -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
    -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
  ].map((v) => Math.max(0, Math.min(1, v)));
  return 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2];
}
const themes = {
  light: theme(/\n:root \{([\s\S]*?)\}/),
  dark: theme(/\n.dark \{([\s\S]*?)\}/),
};
describe.each(Object.entries(themes))(
  "%s opaque token contrast",
  (_name, tokens) => {
    it.each([
      ["foreground", "background", 4.5],
      ["muted-foreground", "background", 4.5],
      ["muted-foreground", "card", 4.5],
      ["primary-foreground", "primary", 4.5],
      ["brand-foreground", "brand", 4.5],
      ["sidebar-foreground", "sidebar", 4.5],
      ["destructive", "card", 4.5],
      ["destructive", "muted", 4.5],
      ["destructive", "accent", 4.5],
      ["destructive", "popover", 4.5],
      ["destructive", "background", 4.5],
      ["destructive-foreground", "destructive", 4.5],
      ["status-success", "status-success-soft", 4.5],
      ["status-warning", "status-warning-soft", 4.5],
      ["destructive", "status-danger-soft", 4.5],
      ["status-info", "status-info-soft", 4.5],
      ["primary", "primary-soft", 3],
      ["input", "background", 3],
      ["input", "card", 3],
      ["input", "popover", 3],
      ["input", "accent", 3],
      ["ring", "background", 3],
    ] as const)("%s against %s meets %s", (fg, bg, minimum) => {
      const a = luminance(tokens[fg]),
        b = luminance(tokens[bg]);
      const ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
      expect(ratio).toBeGreaterThanOrEqual(minimum);
    });
  },
);
