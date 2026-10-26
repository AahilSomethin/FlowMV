export const THEME_STORAGE_KEY = "flowmv-theme";

export type ThemePreference = "light" | "dark" | "system";

export const themeOptions: { value: ThemePreference; label: string }[] = [
  { value: "light", label: "Light" },
  { value: "dark", label: "Dark" },
  { value: "system", label: "System" },
];

export const themeBootScript = `(function(){try{var stored=localStorage.getItem("${THEME_STORAGE_KEY}");var root=document.documentElement;root.classList.remove("light","dark");if(stored==="light"){root.classList.add("light");root.style.colorScheme="light";}else if(stored==="dark"){root.classList.add("dark");root.style.colorScheme="dark";}else{root.style.colorScheme="";}}catch(e){}})();`;

export function isThemePreference(value: string | null): value is ThemePreference {
  return value === "light" || value === "dark" || value === "system";
}

export function readTheme(): ThemePreference {
  try {
    const stored = localStorage.getItem(THEME_STORAGE_KEY);

    return isThemePreference(stored) ? stored : "system";
  } catch {
    return "system";
  }
}

export function applyTheme(theme: ThemePreference): void {
  const root = document.documentElement;
  root.classList.remove("light", "dark");

  if (theme === "light") {
    root.classList.add("light");
    root.style.colorScheme = "light";
  } else if (theme === "dark") {
    root.classList.add("dark");
    root.style.colorScheme = "dark";
  } else {
    root.style.colorScheme = "";
  }

  try {
    localStorage.setItem(THEME_STORAGE_KEY, theme);
  } catch {
    // Preference still applies for this page when storage is blocked.
  }
}
