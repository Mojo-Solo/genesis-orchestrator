import "./globals.css";
import type { Metadata } from "next";

export const metadata: Metadata = { title: "COTHINK'R Demo", description: "P3 OS demo" };

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" className="h-full">
      <body className="min-h-screen bg-background text-foreground">
        {children}
      </body>
    </html>
  );
}