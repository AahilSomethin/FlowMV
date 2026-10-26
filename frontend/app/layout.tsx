import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import Script from "next/script";
import { TooltipProvider } from "@/components/ui/tooltip";
import { ThemeProvider } from "@/components/theme/theme-provider";
import { AuthProvider } from "@/lib/auth";
import { themeBootScript } from "@/lib/theme";
import "./globals.css";

const geistSans = Geist({
  variable: "--font-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: {
    default: "FlowMV",
    template: "%s · FlowMV",
  },
  description: "FlowMV",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html
      lang="en"
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
      suppressHydrationWarning
    >
      <body className="flex min-h-dvh flex-col">
        <Script id="flowmv-theme" strategy="beforeInteractive">
          {themeBootScript}
        </Script>
        <Script id="flowmv-invite-token" strategy="beforeInteractive">
          {`(function(){try{if(location.pathname!=="/invitations/accept")return;var raw=location.hash.charAt(0)==="#"?location.hash.slice(1):"";var token=new URLSearchParams(raw).get("token");if(!token)return;sessionStorage.setItem("flowmv.invite",token);history.replaceState(null,"",location.pathname);}catch(e){}})();`}
        </Script>
        <TooltipProvider>
          <ThemeProvider>
            <AuthProvider>{children}</AuthProvider>
          </ThemeProvider>
        </TooltipProvider>
      </body>
    </html>
  );
}
