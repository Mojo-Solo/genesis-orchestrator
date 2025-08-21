"use client";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { Separator } from "@/components/ui/separator";
import { Button } from "@/components/ui/button";
import { LayoutGrid, ScrollText, LineChart, ClipboardList, Target, Calendar } from "lucide-react";
import { useAppStore } from "@/lib/store";

const NAV = [
  { href: "/dashboard", label: "Dashboard", icon: LayoutGrid },
  { href: "/vision", label: "Vision", icon: ScrollText },
  { href: "/budget", label: "Budget", icon: LineChart },
  { href: "/strategic-plan", label: "Strategic Plan", icon: Target },
  { href: "/initiatives", label: "Initiatives", icon: ClipboardList },
  { href: "/projects", label: "Projects", icon: Calendar },
];

export function Sidebar() {
  const pathname = usePathname();
  const reset = useAppStore((s) => s.resetDemo);
  return (
    <aside className="w-64 shrink-0 border-r bg-neutral-50 dark:bg-neutral-900 p-4 flex flex-col gap-3">
      <div className="text-2xl font-semibold tracking-tight">COTHINK'R</div>
      <Separator />
      <nav className="flex-1 space-y-1">
        {NAV.map(({ href, label, icon: Icon }) => (
          <Link key={href} href={href} className="block">
            <Button variant={pathname === href ? "default" : "ghost"} className="w-full justify-start gap-2">
              <Icon size={18} /> {label}
            </Button>
          </Link>
        ))}
      </nav>
      <Button variant="secondary" onClick={reset}>Reset Demo</Button>
    </aside>
  );
}