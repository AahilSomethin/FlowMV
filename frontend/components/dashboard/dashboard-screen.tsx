"use client";

import Link from "next/link";
import { ArrowRight, BarChart3, GitBranch, Inbox, ListTodo, Sparkles } from "lucide-react";
import { PageHeader } from "@/components/patterns/page-header";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/lib/auth";

const destinations = [
  { href: "/requests/new", label: "Start a request", icon: Inbox, detail: "Find a workplace service and send the information your team needs.", action: "Browse services" },
  { href: "/tasks", label: "Move work forward", icon: ListTodo, detail: "Review approvals, complete assignments, and respond to requests for changes.", action: "Open My Tasks" },
  { href: "/workflows", label: "Design your processes", icon: GitBranch, detail: "Turn a form into a clear sequence of decisions, tasks, and updates.", action: "Explore workflows" },
  { href: "/analytics", label: "See how work flows", icon: BarChart3, detail: "Understand request outcomes and waiting times using your workspace’s recorded activity.", action: "View analytics" },
] as const;

export function DashboardScreen() {
  const { user } = useAuth();
  const current = user?.current_organization;
  if (!user) return null;
  const items = current?.role === "employee" ? destinations.filter((item) => item.href !== "/workflows") : destinations;

  return (
    <>
      <PageHeader title={`Welcome, ${user.name.split(" ")[0]}`} description={current ? `Your work in ${current.name}, from first request to final decision.` : "Choose a workspace to get started."} actions={<Button className="h-10" asChild><Link href="/requests/new">Start a request<ArrowRight className="size-4" aria-hidden="true" /></Link></Button>} />
      <section className="rounded-2xl border bg-card p-6 shadow-sm md:p-8">
        <div className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-widest text-primary"><Sparkles className="size-4" aria-hidden="true" />A clearer way to work</div>
        <h2 className="max-w-xl text-2xl font-semibold leading-tight tracking-tight md:text-3xl">Every request has a next step.</h2>
        <p className="mt-3 max-w-xl text-sm leading-7 text-muted-foreground">Requests are the things you submit. My Tasks holds the work that needs your action. Workflows define how each process moves forward.</p>
      </section>
      <div className="grid gap-4 md:grid-cols-2">
        {items.map((item) => { const Icon = item.icon; return (
          <section key={item.href} className="flex flex-col gap-4 rounded-2xl border bg-card p-6 shadow-sm transition-shadow hover:shadow-md">
            <Icon className="size-5 text-primary" aria-hidden="true" />
            <div className="flex-1"><h2 className="font-semibold">{item.label}</h2><p className="mt-2 text-sm leading-6 text-muted-foreground">{item.detail}</p></div>
            <Link href={item.href} className="flex w-fit items-center gap-2 rounded-sm text-sm font-medium text-primary underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-ring">{item.action}<ArrowRight className="size-3.5" aria-hidden="true" /></Link>
          </section>
        ); })}
      </div>
    </>
  );
}
