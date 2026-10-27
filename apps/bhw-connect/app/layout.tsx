import type { Metadata } from "next";
import { headers } from "next/headers";
import { Suspense } from "react";
// Geist from the `geist` package (next/font/local underneath), not next/font/google: a build on
// the testing box or in CI must not depend on reaching Google at build time.
import { GeistSans } from "geist/font/sans";
import { GeistMono } from "geist/font/mono";
import { NuqsAdapter } from "nuqs/adapters/next/app";
import "./globals.css";
import { Header } from "@/components/layout/header";
import { Footer } from "@/components/layout/footer";
import { FooterGate } from "@/components/layout/footer-gate";
import { SpotFeedback } from "@/components/feedback/spot-feedback";
import { PageViewLogger } from "@/components/analytics/page-view-logger";
import { NoFlashScript } from "@/components/settings/no-flash-script";
import { SettingsProvider } from "@/components/settings/settings-provider";
import { SITE_URL } from "@/lib/site-url";

export const metadata: Metadata = {
  // Origin AND base path: Next joins a relative metadata URL onto metadataBase's path, and adds no
  // base path of its own (measured: an origin alone gave og:image without /bhw-connect).
  metadataBase: new URL(SITE_URL),
  title: {
    default: "BHW Connect",
    template: "%s · BHW Connect",
  },
  description:
    "A public, open-access dashboard for the Philippine Barangay Health Worker (BHW) dataset.",
};

export default async function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  // The per-request CSP nonce proxy.ts minted (lib/csp.ts). Reading it renders every page on
  // request: a nonce cannot be baked into a prerendered page.
  const nonce = (await headers()).get("x-nonce") ?? undefined;

  return (
    <html lang="en" className={`${GeistSans.variable} ${GeistMono.variable} h-full antialiased`}>
      <head>
        <NoFlashScript nonce={nonce} />
      </head>
      <body className="flex min-h-full flex-col">
        <SettingsProvider>
          <NuqsAdapter>
            <Suspense fallback={null}>
              <PageViewLogger />
            </Suspense>
            <a href="#main-content" className="skip-link">
              Skip to main content
            </a>
            <Header />
            <main id="main-content" className="flex flex-1 flex-col">
              {children}
            </main>
            <FooterGate>
              <Footer />
            </FooterGate>
            <SpotFeedback />
          </NuqsAdapter>
        </SettingsProvider>
      </body>
    </html>
  );
}
