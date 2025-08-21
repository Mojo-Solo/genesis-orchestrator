"use client";
import { Sidebar } from "@/components/Sidebar";
import { Toaster } from "@/components/ui/sonner"; // if missing, replace with any toast system

export default function ShellLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-screen">
      <Sidebar />
      <main className="flex-1 p-6 md:p-8 bg-white dark:bg-neutral-950">
        {children}
      </main>
      <Toaster />
    </div>
  );
}